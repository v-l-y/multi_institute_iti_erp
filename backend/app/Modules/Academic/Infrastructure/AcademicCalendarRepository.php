<?php
declare(strict_types=1);
namespace App\Modules\Academic\Infrastructure;

use PDO;

/** Reads only calendar/intake records matching the authenticated institute. */
final readonly class AcademicCalendarRepository
{
    public function __construct(private PDO $db) {}

    public function page(int $institute, int $after, int $size): array
    {
        $stmt = $this->db->prepare(
            'SELECT i.id,i.admission_year,i.intake_code,i.session_part,
                    i.calendar_id,c.session_code,c.revision_code,
                    c.training_starts_on,c.training_ends_on,c.status AS calendar_status,
                    c.source_document_id,d.reference_no AS source_reference,
                    d.status AS source_status
             FROM admission_intakes i
             JOIN cts_training_calendars c ON c.id=i.calendar_id
                AND c.admission_year=i.admission_year
             JOIN global_regulatory_documents d ON d.id=c.source_document_id
             WHERE i.institute_id=? AND i.id>? ORDER BY i.id ASC LIMIT ?'
        );
        $stmt->bindValue(1, $institute, PDO::PARAM_INT);
        $stmt->bindValue(2, $after, PDO::PARAM_INT);
        $stmt->bindValue(3, $size, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $size ? (int) $rows[count($rows)-1]['id'] : null;
        return ['intakes' => $rows, 'next_after_id' => $next];
    }
}
