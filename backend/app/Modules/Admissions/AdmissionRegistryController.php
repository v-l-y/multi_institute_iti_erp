<?php
declare(strict_types=1);

namespace App\Modules\Admissions;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\AuthContext;
use PDO;

/**
 * Institute-only source-linked admissions registries. Status columns are raw,
 * not inferred regulatory approvals. There are no official portal mutations.
 */
final readonly class AdmissionRegistryController
{
    public function __construct(private Database $database) {}

    private static function queryInteger(Request $request, string $key, int $default, int $max): int
    {
        if (!array_key_exists($key, $request->query)) {
            return $default;
        }
        $raw = $request->query[$key];
        $value = (is_string($raw) || is_int($raw))
            ? filter_var($raw, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>$max]])
            : false;
        if ($value === false) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return $value;
    }

    private function registry(Request $request, AuthContext $actor,
        string $permission, string $sql, string $key): Response
    {
        $actor->requirePermission($permission);
        $institute = $actor->instituteId
            ?? throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
        $size = self::queryInteger($request, 'page_size', 50, 100);
        $after = self::queryInteger($request, 'after_id', 0, PHP_INT_MAX);
        $stmt = $this->database->connection()->prepare($sql);
        $stmt->bindValue(1, $institute, PDO::PARAM_INT);
        $stmt->bindValue(2, $after, PDO::PARAM_INT);
        $stmt->bindValue(3, $size, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $size ? (int)$rows[count($rows)-1]['id'] : null;
        return new Response(['success'=>true,'data'=>[$key=>$rows,'next_after_id'=>$next]]);
    }

    public function enrollments(Request $request, AuthContext $actor): Response
    {
        return $this->registry($request,$actor,'enrollment.view',
            'SELECT e.id,e.student_id,e.batch_id,e.intake_id,e.admission_intake_revision_id,
                    e.admission_number,e.state_registration_number,e.admission_route,
                    e.admission_route_source_id,e.admitted_on,e.status,
                    e.admission_rule_id,e.qualification_id,e.qualification_route_code,
                    e.qualification_check_passed,e.vertical_quota_id,e.horizontal_quota_id
             FROM enrollments e
             WHERE e.institute_id=? AND e.id>? ORDER BY e.id ASC LIMIT ?',
            'enrollments'
        );
    }

    public function seatMatrixEntries(Request $request, AuthContext $actor): Response
    {
        return $this->registry($request,$actor,'seatmatrix.view',
            'SELECT e.id,e.version_id,v.version_code,v.admission_year,v.published_on,
                    v.status AS version_status,v.source_id,v.portal_reference,v.dgt_circular_no,
                    e.intake_id,e.trade_unit_shift_id,e.calendar_id,e.session_part,
                    e.physical_shift_no,e.portal_shift_no,e.seat_capacity,
                    e.admission_allowed,e.consumed_previous_session,
                    e.instructor_mapping_id,e.instructor_mapping_satisfied,
                    e.eligibility_reason,e.portal_itsu_reference
             FROM seat_matrix_entries e
             JOIN seat_matrix_versions v ON v.id=e.version_id
                AND v.intake_id=e.intake_id AND v.institute_id=e.institute_id
             WHERE e.institute_id=? AND e.id>? ORDER BY e.id ASC LIMIT ?',
            'entries'
        );
    }

    public function academicCalendars(Request $request, AuthContext $actor): Response
    {
        return $this->registry($request,$actor,'calendar.view',
            'SELECT i.id,i.admission_year,i.intake_code,i.session_part,
                    i.calendar_id,c.session_code,c.revision_code,
                    c.training_starts_on,c.training_ends_on,c.status AS calendar_status,
                    c.source_document_id,d.reference_no AS source_reference,
                    d.status AS source_status
             FROM admission_intakes i
             JOIN cts_training_calendars c ON c.id=i.calendar_id
                AND c.admission_year=i.admission_year
             JOIN global_regulatory_documents d ON d.id=c.source_document_id
             WHERE i.institute_id=? AND i.id>? ORDER BY i.id ASC LIMIT ?',
            'intakes'
        );
    }
}
