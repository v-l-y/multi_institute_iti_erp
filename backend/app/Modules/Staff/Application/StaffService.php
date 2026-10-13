<?php
declare(strict_types=1);
namespace App\Modules\Staff\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/** Audited institute-scoped staff registration; never creates login or regulatory approval. */
final readonly class StaffService
{
    public function __construct(private PDO $db) {}

    public function create(AuthContext $actor, int $institute, string $code, string $name, string $category, string $designation, string $joinedOn): int
    {
        $db = $this->db
        $transaction = new InstituteTransaction($db);
        $id = $transaction->run($actor, $institute, 'staff.manage', function () use ($db, $institute, $actor, $code, $name, $category, $designation, $joinedOn, $transaction): int {
            $existing = $db->prepare('SELECT id FROM staff WHERE institute_id=? AND employee_code=? LIMIT 1 FOR UPDATE');
            $existing->execute([$institute, $code]);
            if ($existing->fetchColumn() !== false) {
                throw new HttpException('STAFF_CODE_EXISTS', 'Employee code already exists in this institute.', 409);
            }
            $insert = $db->prepare(
                'INSERT INTO staff (institute_id,employee_code,full_name,staff_category,designation,joined_on)
                 VALUES (?,?,?,?,?,?)'
            );
            $insert->execute([$institute, $code, $name, $category, $designation, $joinedOn]);
            $id = (int) $db->lastInsertId();
            $transaction->audit($actor, $institute, 'staff.create', 'staff', $id, ['employee_code' => $code, 'staff_category' => $category]);
            return $id;
        });
        return $id;
    }
}
