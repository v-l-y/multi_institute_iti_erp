<?php
declare(strict_types=1);
namespace App\Modules\Staff\Infrastructure;

use PDO;

final readonly class StaffRepository
{
    public function __construct(private PDO $db) {}
    public function page(int $institute, int $afterId, int $pageSize): array
    {
        $statement = $this->db->prepare(
            'SELECT id,employee_code,full_name,staff_category,designation,principal_assignment,joined_on,left_on
             FROM staff WHERE institute_id=? AND id>? ORDER BY id ASC LIMIT ?'
        );
        $statement->bindValue(1, $institute, PDO::PARAM_INT);
        $statement->bindValue(2, $afterId, PDO::PARAM_INT);
        $statement->bindValue(3, $pageSize, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $pageSize ? (int) $rows[count($rows) - 1]['id'] : null;
        return ['staff' => $rows, 'next_after_id' => $next];
    }
}
