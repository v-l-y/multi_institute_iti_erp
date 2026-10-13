<?php
declare(strict_types=1);
namespace App\Modules\Students\Infrastructure;

use App\Http\HttpException;
use PDO;

final readonly class StudentRepository
{
    public function __construct(private PDO $db) {}
    private const FIELDS = 'id,local_student_code,full_name,date_of_birth,gender,prn,prn_verified_on';

    public function page(int $institute, int $cursor, int $pageSize): array
    {
        $statement = $this->db->prepare('SELECT ' . self::FIELDS . ' FROM students WHERE institute_id=? AND id>? ORDER BY id ASC LIMIT ?');
        $statement->bindValue(1, $institute, PDO::PARAM_INT);
        $statement->bindValue(2, $cursor, PDO::PARAM_INT);
        $statement->bindValue(3, $pageSize, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $pageSize ? (int) $rows[count($rows)-1]['id'] : null;
        return ['students' => $rows, 'next_after_id' => $next];
    }

    public function own(int $studentId, int $institute): array
    {
        $statement = $this->db->prepare('SELECT ' . self::FIELDS . ' FROM students WHERE id=? AND institute_id=?');
        $statement->execute([$studentId, $institute]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new HttpException('NOT_FOUND', 'Student record not found.', 404);
        }
        return $row;
    }
}
