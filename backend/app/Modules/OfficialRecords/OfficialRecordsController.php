<?php
declare(strict_types=1);

namespace App\Modules\OfficialRecords;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\AuthContext;
use PDO;

/** Read previously imported official results and certificates, preserving revision history. */
final readonly class OfficialRecordsController
{
    public function __construct(private Database $database) {}

    private static function queryInt(Request $request, string $field, int $default, int $max): int
    {
        if (!array_key_exists($field, $request->query)) {
            return $default;
        }
        $raw = $request->query[$field];
        $value = (is_string($raw) || is_int($raw))
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>$max]])
            : false;
        if ($value === false) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $field . '.', 422);
        }
        return $value;
    }

    private function listRecords(Request $request, AuthContext $actor, string $type, bool $own): Response
    {
        $permission = $type === 'results' ? ($own ? 'result.view.own' : 'result.view')
                                           : ($own ? 'certificate.view.own' : 'certificate.view');
        $actor->requirePermission($permission);
        $institute = $actor->instituteId ?? throw new HttpException('FORBIDDEN','Institute access denied.',403);
        if ($own && $actor->studentId === null) {
            throw new HttpException('FORBIDDEN','Linked student identity required.',403);
        }
        $size = self::queryInt($request, 'page_size', 50, 100);
        $after = self::queryInt($request, 'after_id', 0, PHP_INT_MAX);
        if ($type === 'results') {
            $sql = 'SELECT o.id,o.enrollment_id,o.batch_year_id,o.cycle_id,
                           o.result_status,o.component_marks_json,o.ojt_grade,
                           o.revision_no,o.supersedes_result_id,o.source_system,
                           o.source_reference,o.declared_at
                    FROM official_results o';
            $alias = 'o';
        } else {
            $sql = 'SELECT c.id,c.enrollment_id,c.official_result_id,c.document_type,
                           c.certificate_number,c.revision_no,c.supersedes_certificate_id,
                           c.status,c.source_system,c.source_reference,c.verification_reference,c.issued_on
                    FROM certificates c';
            $alias = 'c';
        }
        if ($own) {
            $sql .= ' JOIN enrollments e ON e.id=' . $alias . '.enrollment_id
                      AND e.institute_id=' . $alias . '.institute_id';
        }
        $sql .= ' WHERE ' . $alias . '.institute_id=? AND ' . $alias . '.id>?';
        if ($own) {
            $sql .= ' AND e.student_id=?';
        }
        $sql .= ' ORDER BY ' . $alias . '.id ASC LIMIT ?';
        $statement = $this->database->connection()->prepare($sql);
        $statement->bindValue(1, $institute, PDO::PARAM_INT);
        $statement->bindValue(2, $after, PDO::PARAM_INT);
        if ($own) {
            $statement->bindValue(3, $actor->studentId, PDO::PARAM_INT);
            $statement->bindValue(4, $size, PDO::PARAM_INT);
        } else {
            $statement->bindValue(3, $size, PDO::PARAM_INT);
        }
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $size ? (int)$rows[count($rows)-1]['id'] : null;
        return new Response(['success'=>true,'data'=>[$type=>$rows,'next_after_id'=>$next]]);
    }

    public function results(Request $request, AuthContext $actor): Response
    {
        return $this->listRecords($request, $actor, 'results', false);
    }

    public function ownResults(Request $request, AuthContext $actor): Response
    {
        return $this->listRecords($request, $actor, 'results', true);
    }

    public function certificates(Request $request, AuthContext $actor): Response
    {
        return $this->listRecords($request, $actor, 'certificates', false);
    }

    public function ownCertificates(Request $request, AuthContext $actor): Response
    {
        return $this->listRecords($request, $actor, 'certificates', true);
    }
}
