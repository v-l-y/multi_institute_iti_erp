<?php
declare(strict_types=1);
namespace App\Modules\Admissions\Infrastructure;

use PDO;

/** Executes authenticated, institute-filtered registry queries supplied only by the application. */
final readonly class AdmissionRegistryRepository
{
    public function __construct(private PDO $db) {}
    public function page(string $sql, int $institute, int $after, int $size, string $key): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(1, $institute, PDO::PARAM_INT);
        $stmt->bindValue(2, $after, PDO::PARAM_INT);
        $stmt->bindValue(3, $size, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $size ? (int)$rows[count($rows)-1]['id'] : null;
        return [$key => $rows, 'next_after_id' => $next];
    }
}
