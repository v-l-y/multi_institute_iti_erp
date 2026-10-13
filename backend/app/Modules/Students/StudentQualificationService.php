<?php
declare(strict_types=1);

namespace App\Modules\Students;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\AuthContext;
use PDO;

/**
 * Institute-attested qualification record with explicit certificate reference.
 * This does not confer admission eligibility or government verification.
 */
final readonly class StudentQualificationService
{
    public function __construct(private PDO $db) {}

    public function create(
        AuthContext $actor,int $institute,int $studentId,string $level,
        string $board,int $passingYear,string $certificateReference,array $results
    ): int {
        $tx=new InstituteTransaction($this->db);
        return $tx->run($actor,$institute,'student.manage',function () use (
            $actor,$institute,$studentId,$level,$board,$passingYear,
            $certificateReference,$results,$tx
        ): int {
            $q=$this->db->prepare('SELECT id FROM students WHERE id=? AND institute_id=? FOR UPDATE');
            $q->execute([$studentId,$institute]);
            if ($q->fetchColumn()===false) {
                throw new HttpException('NOT_FOUND','Student not found in this institute.',404);
            }
            $q=$this->db->prepare('SELECT id FROM student_qualifications
                WHERE student_id=? AND institute_id=? AND certificate_reference=? LIMIT 1 FOR UPDATE');
            $q->execute([$studentId,$institute,$certificateReference]);
            if ($q->fetchColumn()!==false) {
                throw new HttpException('QUALIFICATION_EXISTS','Certificate reference already registered for this student.',409);
            }
            $q=$this->db->prepare('INSERT INTO student_qualifications
                (institute_id,student_id,level_code,board_name,passing_year,
                 certificate_reference,subject_results_json,verified_by,verified_on)
                VALUES (?,?,?,?,?,?,?, ?,UTC_DATE())');
            $q->execute([
                $institute,$studentId,$level,$board,$passingYear,
                $certificateReference,json_encode($results,JSON_THROW_ON_ERROR),
                $actor->username
            ]);
            $id=(int)$this->db->lastInsertId();
            $tx->audit($actor,$institute,'student.qualification.record',
                'student_qualifications',$id,['student_id'=>$studentId,'level_code'=>$level]);
            return $id;
        });
    }
}
