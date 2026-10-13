<?php
declare(strict_types=1);
namespace App\Modules\Fees\Infrastructure;

use PDO;

/** Institute-scoped, optionally student-scoped payment history. */
final readonly class FeePaymentRepository
{
    public function __construct(private PDO $db) {}

    public function page(int $institute, int $cursor, int $limit, ?int $studentId): array
    {
        $own = $studentId !== null;
        $sql = 'SELECT id,student_id,payment_no,receipt_no,paid_on,amount,payment_mode
                FROM fee_payments WHERE institute_id=? AND id>?';
        if ($own) {
            $sql .= ' AND student_id=?';
        }
        $sql .= ' ORDER BY id ASC LIMIT ?';
        $statement = $this->db->prepare($sql);
        $statement->bindValue(1, $institute, PDO::PARAM_INT);
        $statement->bindValue(2, $cursor, PDO::PARAM_INT);
        if ($own) {
            $statement->bindValue(3, $studentId, PDO::PARAM_INT);
            $statement->bindValue(4, $limit, PDO::PARAM_INT);
        } else {
            $statement->bindValue(3, $limit, PDO::PARAM_INT);
        }
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $limit ? (int)$rows[count($rows)-1]['id'] : null;
        return ['payments' => $rows, 'next_after_id' => $next];
    }
}
