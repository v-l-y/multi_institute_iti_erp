<?php
declare(strict_types=1);

namespace App\Modules\Academic\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Source-limited daily CTS plan and auditable Principal coverage review. */
final readonly class CalendarPlanningService
{
    public function __construct(private PDO $db) {}

    private function year(int $institute,int $id): array
    {
        $q=$this->db->prepare('SELECT y.*,c.status AS calendar_publication,
                d.status AS source_status,
                g.trade_practical_minutes,g.trade_theory_minutes,
                g.employability_minutes,g.ojt_project_minutes
            FROM batch_years y
            JOIN cts_training_calendars c ON c.id=y.calendar_id
            JOIN global_regulatory_documents d ON d.id=c.source_document_id
            JOIN curriculum_years l ON l.id=y.curriculum_year_id
                AND l.institute_id=y.institute_id
            JOIN global_curriculum_years g ON g.id=l.global_curriculum_year_id
            WHERE y.id=? AND y.institute_id=? FOR UPDATE');
        $q->execute([$id,$institute]);
        $row=$q->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new HttpException('NOT_FOUND','CTS batch course year not found.',404);
        if ($row['calendar_publication']!=='published' || $row['source_status']!=='published') {
            throw new HttpException('CALENDAR_NOT_PUBLISHED','Published CTS calendar source required.',409);
        }
        return $row;
    }

    public function entry(AuthContext $actor,int $institute,int $year,
        string $date,string $component,int $minutes): void
    {
        $tx=new InstituteTransaction($this->db);
        try {
            $tx->run($actor,$institute,'calendar.manage',function () use (
                $actor,$institute,$year,$date,$component,$minutes,$tx
            ): void {
                $batchYear=$this->year($institute,$year);
                if ($date<$batchYear['starts_on'] || $date>$batchYear['ends_on']) {
                    throw new HttpException('DATE_OUTSIDE_COURSE_YEAR','Session day must fall within batch course year.',422);
                }
                if ($batchYear['calendar_status']==='reviewed'
                    && $batchYear['calendar_reviewed_through']!==null
                    && $date<=$batchYear['calendar_reviewed_through']) {
                    throw new HttpException('CALENDAR_REVIEWED','Already reviewed daily sessions cannot be rewritten.',409);
                }
                $maxColumn=$component.'_minutes';
                $allowance=(int)$batchYear[$maxColumn];
                if ($allowance===0 || $minutes>$allowance) {
                    throw new HttpException('SYLLABUS_HOURS_LIMIT','Component exceeds published CTS yearly teaching minutes.',409);
                }
                // The InstituteTransaction already holds the institute row lock,
                // serializing all calendar writers without locking an aggregate.
                $q=$this->db->prepare('SELECT SUM(scheduled_minutes) FROM training_calendar_entries
                    WHERE batch_year_id=? AND institute_id=? AND component=?');
                $q->execute([$year,$institute,$component]);
                $scheduled=(int)$q->fetchColumn();
                if ($scheduled+$minutes>$allowance) {
                    throw new HttpException('SYLLABUS_HOURS_LIMIT','Proposed plan exceeds published syllabus component minutes.',409);
                }
                $q=$this->db->prepare('SELECT batch_year_id FROM training_calendar_entries
                    WHERE batch_year_id=? AND institute_id=? AND session_date=? AND component=?
                    FOR UPDATE');
                $q->execute([$year,$institute,$date,$component]);
                if ($q->fetchColumn()!==false) {
                    throw new HttpException('CALENDAR_ENTRY_EXISTS','Session is already scheduled.',409);
                }
                $this->db->prepare('INSERT INTO training_calendar_entries
                    (institute_id,batch_year_id,calendar_id,session_date,component,scheduled_minutes)
                    VALUES (?,?,?,?,?,?)')->execute([
                    $institute,$year,$batchYear['calendar_id'],$date,$component,$minutes
                ]);
                // Daily entry is keyed by a composite identity; audit against its batch year.
                $tx->audit($actor,$institute,'calendar.entry.create','batch_years',$year,[
                    'session_date'=>$date,'component'=>$component,'minutes'=>$minutes
                ]);
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('CALENDAR_ENTRY_EXISTS','Daily calendar entry already exists.',409);
            }
            throw $e;
        }
    }

    public function review(AuthContext $actor,int $institute,int $year,
        string $through,string $reference): void
    {
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_AUTHORITY_REQUIRED','Principal must review training calendar.',403);
        }
        $tx=new InstituteTransaction($this->db);
        $tx->run($actor,$institute,'calendar.manage',function () use (
            $actor,$institute,$year,$through,$reference,$tx
        ): void {
            $record=$this->year($institute,$year);
            if ($through<$record['starts_on'] || $through>$record['ends_on']) {
                throw new HttpException('INVALID_REVIEW_DATE','Calendar review boundary outside course year.',422);
            }
            if ($record['calendar_status']==='reviewed'
                && $record['calendar_reviewed_through']!==null
                && $through<=$record['calendar_reviewed_through']) {
                throw new HttpException('REVIEW_NOT_ADVANCING','Review must extend earlier certified coverage.',409);
            }
            $q=$this->db->prepare('SELECT COUNT(*) FROM training_calendar_entries
                WHERE batch_year_id=? AND institute_id=? AND session_date<=?');
            $q->execute([$year,$institute,$through]);
            if ((int)$q->fetchColumn()===0) {
                throw new HttpException('CALENDAR_EMPTY','Scheduled entries required before review.',409);
            }
            $this->db->prepare("UPDATE batch_years
                SET calendar_status='reviewed',calendar_reviewed_through=?,
                  calendar_reviewed_by=?,calendar_evidence_reference=?
                WHERE id=? AND institute_id=?")->execute([
                $through,$actor->username,$reference,$year,$institute
            ]);
            $tx->audit($actor,$institute,'calendar.review','batch_years',$year,[
                'previous_through'=>$record['calendar_reviewed_through'],
                'reviewed_through'=>$through,'evidence_reference'=>$reference
            ]);
        });
    }
}
