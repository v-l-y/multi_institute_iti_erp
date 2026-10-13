<?php
declare(strict_types=1);
namespace App\Modules\Students\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/** Institute-local student registration. No admission or PRN verification is inferred. */
final readonly class StudentRegistrationService
{
    public function __construct(private PDO $db) {}
    public function create(AuthContext $actor, int $institute, string $code, string $name, string $birth, string $gender, string $address, string $mode, string $reference): int
    {
        $db = $this->db;
        $transaction = new InstituteTransaction($db);
        $id = $transaction->run($actor, $institute, 'student.manage', function () use ($db, $transaction, $actor, $institute, $code, $name, $birth, $gender, $address, $mode, $reference): int {
            $existing = $db->prepare('SELECT id FROM students WHERE institute_id=? AND local_student_code=? LIMIT 1 FOR UPDATE');
            $existing->execute([$institute, $code]);
            if ($existing->fetchColumn() !== false) {
                throw new HttpException('STUDENT_CODE_EXISTS', 'Student code already exists in this institute.', 409);
            }
            $insert = $db->prepare(
                'INSERT INTO students (institute_id,local_student_code,full_name,date_of_birth,gender,address,identity_mode,identity_evidence_reference)
                 VALUES (?,?,?,?,?,?,?,?)'
            );
            $insert->execute([$institute, $code, $name, $birth, $gender, $address, $mode, $reference]);
            $id = (int) $db->lastInsertId();
            $transaction->audit($actor, $institute, 'student.create', 'students', $id, ['local_student_code' => $code]);
            return $id;
        });
        return $id;
    }
}
