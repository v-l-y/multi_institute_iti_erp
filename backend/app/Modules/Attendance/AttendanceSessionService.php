<?php
declare(strict_types=1);

namespace App\Modules\Attendance;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/**
 * Instantiate only officially scheduled and institute-reviewed sessions.
 * Locking is a separate Principal event once every enrolled learner has a row.
 */
final readonly class AttendanceSessionService
{
    public function __construct(private PDO $db) {}

    public function create(AuthContext $actor,int $institute,int $batchYear,
        string $date,string $component,int $staffId): int
    {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'calendar.manage',function () use (
            $actor,$institute,$batchYear,$date,$component,$staffId,$tx
        ): int {
            $q=$this->db->prepare('SELECT y.*,b.trade_unit_shift_id,c.status AS calendar_publication
                FROM batch_years y JOIN batches b ON b.id=y.batch_id AND b.institute_id=y.institute_id
                JOIN cts_training_calendars c ON c.id=y.calendar_id
                WHERE y.id=? AND y.institute_id=? FOR UPDATE');
            $q->execute([$batchYear,$institute]);
            $year=$q->fetch(PDO::FETCH_ASSOC);
            if ($year===false) throw new HttpException('NOT_FOUND','Batch year not found.',404);
            if ($date<$year['starts_on'] || $date>$year['ends_on']
                || $year['calendar_status']!=='reviewed'
                || $year['calendar_reviewed_through']===null
                || $year['calendar_reviewed_through']<$date
                || $year['calendar_publication']!=='published') {
                throw new HttpException('CALENDAR_NOT_REVIEWED','Applicable published and reviewed training calendar required.',409);
            }
            $q=$this->db->prepare('SELECT scheduled_minutes FROM training_calendar_entries
                WHERE batch_year_id=? AND institute_id=? AND session_date=? AND component=? FOR UPDATE');
            $q->execute([$batchYear,$institute,$date,$component]);
            $minutes=$q->fetchColumn();
            if ($minutes===false) {
                throw new HttpException('SESSION_NOT_SCHEDULED','Source-reviewed calendar entry required.',409);
            }
            $q=$this->db->prepare("SELECT id FROM instructor_mappings
                WHERE institute_id=? AND staff_id=? AND status='active'
                  AND valid_from<=? AND (valid_to IS NULL OR valid_to>=?)
                  AND (
                    (mapping_type='trade' AND trade_unit_shift_id=? AND ?<>'employability')
                    OR
                    (mapping_type='subject' AND subject_code='ES'
                        AND ?='employability' AND EXISTS (
                            SELECT 1 FROM subject_batch_assignments sa
                            WHERE sa.institute_id=instructor_mappings.institute_id
                              AND sa.mapping_id=instructor_mappings.id
                              AND sa.batch_year_id=?
                        ))
                  )
                  AND EXISTS (SELECT 1 FROM staff st WHERE st.id=instructor_mappings.staff_id
                    AND st.institute_id=instructor_mappings.institute_id
                    AND st.left_on IS NULL AND st.staff_category='teaching'
                    AND st.nims_verification='verified')
                LIMIT 1 FOR UPDATE");
            $q->execute([$institute,$staffId,$date,$date,
                $year['trade_unit_shift_id'],$component,$component,$batchYear]);
            if ($q->fetchColumn()===false) {
                throw new HttpException('INSTRUCTOR_NOT_VERIFIED','Active matching reviewed instructor assignment is required.',409);
            }
            $q=$this->db->prepare('SELECT id FROM attendance_sessions
                WHERE batch_year_id=? AND session_date=? AND component=? AND institute_id=? FOR UPDATE');
            $q->execute([$batchYear,$date,$component,$institute]);
            if ($q->fetchColumn()!==false) {
                throw new HttpException('SESSION_EXISTS','Scheduled attendance session already exists.',409);
            }
            $q=$this->db->prepare('INSERT INTO attendance_sessions
                (institute_id,batch_year_id,batch_id,session_date,component,
                 scheduled_minutes,instructor_id) VALUES (?,?,?,?,?,?,?)');
            $q->execute([$institute,$batchYear,$year['batch_id'],$date,$component,
                (int)$minutes,$staffId]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'attendance.session.create','attendance_sessions',$id,
                ['batch_year_id'=>$batchYear,'component'=>$component,'date'=>$date]);
            return $id;
        });
    }

    public function lock(AuthContext $actor,int $institute,int $sessionId): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_AUTHORITY_REQUIRED','Principal is required to certify an attendance session.',403);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'attendance.correct',function () use (
            $actor,$institute,$sessionId,$tx
        ): void {
            $q=$this->db->prepare('SELECT * FROM attendance_sessions
                WHERE id=? AND institute_id=? FOR UPDATE');
            $q->execute([$sessionId,$institute]);
            $session=$q->fetch(PDO::FETCH_ASSOC);
            if ($session===false) throw new HttpException('NOT_FOUND','Session not found.',404);
            if ($session['locked_on']!==null) throw new HttpException('SESSION_LOCKED','Session already locked.',409);
            $today=(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
            if ($session['session_date']>$today) throw new HttpException('FUTURE_SESSION','Future attendance cannot be certified.',409);
            $q=$this->db->prepare("SELECT COUNT(*) FROM enrollments e
                 WHERE e.batch_id=? AND e.institute_id=?
                   AND e.admitted_on<=?
                   AND e.status NOT IN ('pending_verification','cancelled')
                   AND NOT EXISTS (SELECT 1 FROM attendance_records a
                     WHERE a.session_id=? AND a.enrollment_id=e.id
                       AND a.batch_id=e.batch_id AND a.institute_id=e.institute_id)");
            $q->execute([$session['batch_id'],$institute,$session['session_date'],$sessionId]);
            if ((int)$q->fetchColumn()>0) {
                throw new HttpException('ATTENDANCE_INCOMPLETE','Record attendance for all applicable enrolled learners before locking.',409);
            }
            $q=$this->db->prepare('SELECT COUNT(*) FROM attendance_records
                WHERE session_id=? AND institute_id=?');
            $q->execute([$sessionId,$institute]);
            if ((int)$q->fetchColumn()===0) {
                throw new HttpException('ATTENDANCE_EMPTY','An empty session cannot be certified.',409);
            }
            $this->db->prepare('UPDATE attendance_sessions SET locked_on=UTC_TIMESTAMP()
                WHERE id=? AND institute_id=? AND locked_on IS NULL')
                ->execute([$sessionId,$institute]);
            $tx->audit($actor,$institute,'attendance.session.lock','attendance_sessions',$sessionId,
                ['date'=>$session['session_date']]);
        });
    }
}
