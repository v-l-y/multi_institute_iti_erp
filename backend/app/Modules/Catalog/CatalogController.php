<?php
declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\AuthContext;
use PDO;

/** Scoped read-only ITSU and batch registry; all official decisions remain with authorities. */
final readonly class CatalogController
{
    public function __construct(private Database $database) {}

    private static function paging(Request $request): array
    {
        $values = [];
        foreach (['page_size' => [50,100], 'after_id' => [0,PHP_INT_MAX]] as $key => [$default,$maximum]) {
            if (!array_key_exists($key, $request->query)) {
                $values[$key] = $default;
                continue;
            }
            $raw = $request->query[$key];
            $value = (is_string($raw) || is_int($raw))
                ? filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range'=>1, 'max_range'=>$maximum]])
                : false;
            if ($value === false) {
                throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
            }
            $values[$key] = $value;
        }
        return [$values['page_size'], $values['after_id']];
    }

    private function list(Request $request, AuthContext $actor, string $permission, string $sql, string $key): Response
    {
        $actor->requirePermission($permission);
        if ($actor->instituteId === null) {
            throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
        }
        [$pageSize,$cursor] = self::paging($request);
        $statement = $this->database->connection()->prepare($sql);
        $statement->bindValue(1, $actor->instituteId, PDO::PARAM_INT);
        $statement->bindValue(2, $cursor, PDO::PARAM_INT);
        $statement->bindValue(3, $pageSize, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $pageSize ? (int)$rows[count($rows)-1]['id'] : null;
        return new Response(['success'=>true, 'data'=>[$key=>$rows, 'next_after_id'=>$next]]);
    }

    public function itsu(Request $request, AuthContext $actor): Response
    {
        return $this->list(
            $request, $actor, 'tradeunit.view',
            "SELECT s.id, s.trade_unit_id, t.dgt_code, r.official_name, tu.unit_no,
                    s.global_shift_id, gs.display_name AS shift_name, s.shift_no,
                    s.training_mode, s.affiliation_status, s.status, s.sanctioned_capacity,
                    s.valid_from, s.valid_to, s.approval_reference
             FROM trade_unit_shifts s
             JOIN trade_units tu ON tu.id=s.trade_unit_id AND tu.institute_id=s.institute_id
             JOIN trades t ON t.id=tu.trade_id
             JOIN global_shifts gs ON gs.id=s.global_shift_id
             LEFT JOIN trade_master_revisions r ON r.trade_id=t.id AND r.status='active' AND EXISTS (SELECT 1 FROM global_regulatory_documents d WHERE d.id=r.source_document_id AND d.status='published')
             WHERE s.institute_id=? AND s.id>? ORDER BY s.id ASC LIMIT ?",
            'itsu'
        );
    }

    public function batches(Request $request, AuthContext $actor): Response
    {
        return $this->list(
            $request, $actor, 'batch.view',
            'SELECT id, batch_code, trade_unit_shift_id, trade_id, curriculum_id,
                    seat_matrix_entry_id, admission_rule_id, admission_year, intake_id,
                    calendar_id, starts_on, ends_on, training_mode, capacity, occupied_seats, status
             FROM batches WHERE institute_id=? AND id>? ORDER BY id ASC LIMIT ?',
            'batches'
        );
    }
}
