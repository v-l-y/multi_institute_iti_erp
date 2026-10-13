<?php
declare(strict_types=1);

namespace App\Modules\OfficialRecords;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/**
 * Institute retention of externally issued results/certificates, never government issuance.
 * Published institute-linked source and explicit evidence are mandatory.
 */
final readonly class OfficialImportService
{
    public function __construct(private PDO $db) {}

    private static function principal(AuthContext $actor): void
    {
        if (!in_array('principal', $actor->roles, true)) {
            throw new HttpException('PRINCIPAL_REQUIRED',
                'Principal account required for official result/certificate import.', 403);
        }
    }

    private function one(string $sql, array $args): ?array
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function officialSource(int $institute, int $sourceId, string $sourceSystem): array
    {
        $source = $this->one('SELECT d.id,d.status,d.sha256,d.authority,d.source_system,d.reference_no
            FROM regulatory_sources rs JOIN global_regulatory_documents d ON d.id=rs.global_document_id
            WHERE rs.id=? AND rs.institute_id=? FOR UPDATE', [$sourceId, $institute]);
        if (!$source || $source['status'] !== 'published' || !$source['sha256']) {
            throw new HttpException('OFFICIAL_SOURCE_REQUIRED', 'Published institute-linked official evidence is required.', 409);
        }
        if ($sourceSystem === 'DGT') {
            if ($source['authority'] !== 'DGT' || $source['source_system'] !== null) {
                throw new HttpException('SOURCE_SYSTEM_MISMATCH', 'DGT-issued source required.', 409);
            }
        } elseif ($source['source_system'] !== $sourceSystem) {
            throw new HttpException('SOURCE_SYSTEM_MISMATCH', 'Official source system mismatch.', 409);
        }
        return $source;
    }

    private function run(AuthContext $actor, int $institute, string $permission, callable $operation): int
    {
        try {
            return (new InstituteTransaction($this->db))->run($actor, $institute, $permission, $operation);
        } catch (PDOException $exception) {
            if ((int)($exception->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException('OFFICIAL_REVISION_EXISTS', 'Official record or revision already exists.', 409);
            }
            throw $exception;
        }
    }

    public function result(AuthContext $actor, int $institute, array $data): int
    {
        self::principal($actor);
        return $this->run($actor, $institute, 'result.import',
            function () use ($actor, $institute, $data): int {
                $source = $this->officialSource($institute, $data['regulatory_source_id'], $data['source_system']);
                $enrollment = $this->one('SELECT id,batch_id FROM enrollments
                    WHERE id=? AND institute_id=? FOR UPDATE',
                    [$data['enrollment_id'], $institute]);
                $year = $this->one('SELECT id,batch_id FROM batch_years
                    WHERE id=? AND institute_id=? FOR UPDATE',
                    [$data['batch_year_id'], $institute]);
                $cycle = $this->one('SELECT id FROM exam_cycles
                    WHERE id=? AND institute_id=? FOR UPDATE', [$data['cycle_id'], $institute]);
                if (!$enrollment || !$year || !$cycle
                    || (int)$enrollment['batch_id'] !== (int)$year['batch_id']) {
                    throw new HttpException('OFFICIAL_IDENTITY', 'Enrollment, course year and exam cycle must belong to the same institute and batch.', 409);
                }
                $prior = $data['supersedes_result_id'] ?? null;
                if ($prior !== null) {
                    $previous = $this->one('SELECT revision_no FROM official_results
                        WHERE id=? AND enrollment_id=? AND batch_year_id=? AND cycle_id=? AND institute_id=? FOR UPDATE',
                        [$prior, $data['enrollment_id'], $data['batch_year_id'], $data['cycle_id'], $institute]);
                    if (!$previous || (int)$previous['revision_no'] + 1 !== $data['revision_no']) {
                        throw new HttpException('OFFICIAL_REVISION', 'Revision must supersede the preceding version for the same official result.', 409);
                    }
                    $newest = $this->one('SELECT id FROM official_results WHERE
                        supersedes_result_id=? AND institute_id=? FOR UPDATE', [$prior, $institute]);
                    if ($newest) {
                        throw new HttpException('OFFICIAL_REVISION', 'The selected result version is already superseded.', 409);
                    }
                } elseif ($data['revision_no'] !== 1) {
                    throw new HttpException('OFFICIAL_REVISION', 'First import revision must be 1.', 409);
                }
                $marksJson = json_encode($data['component_marks'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $snapshotHash = hash('sha256', json_encode([
                    $data['enrollment_id'], $data['batch_year_id'], $data['cycle_id'],
                    $data['result_status'], $marksJson, $data['ojt_grade'], $data['revision_no'],
                    $prior, $data['source_system'], $data['source_reference'],
                    $data['declared_at'], $source['sha256'],
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                $q = $this->db->prepare('INSERT INTO official_results
                    (institute_id,enrollment_id,batch_id,batch_year_id,cycle_id,result_status,
                     component_marks_json,ojt_grade,revision_no,supersedes_result_id,
                     source_system,source_reference,payload_sha256,declared_at,imported_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([
                    $institute, $data['enrollment_id'], $enrollment['batch_id'],
                    $data['batch_year_id'], $data['cycle_id'], $data['result_status'],
                    $marksJson, $data['ojt_grade'], $data['revision_no'], $prior,
                    $data['source_system'], $data['source_reference'], $snapshotHash,
                    $data['declared_at'], $actor->username,
                ]);
                $id = (int)$this->db->lastInsertId();
                (new InstituteTransaction($this->db))->audit(
                    $actor, $institute, 'official.result.import', 'official_results', $id,
                    ['regulatory_source_id' => $data['regulatory_source_id'],
                     'source_sha256' => $source['sha256'], 'source_reference' => $data['source_reference'],
                     'evidence_reference' => $data['evidence_reference'], 'snapshot_sha256' => $snapshotHash]
                );
                return $id;
            }
        );
    }

    public function certificate(AuthContext $actor, int $institute, array $data): int
    {
        self::principal($actor);
        return $this->run($actor, $institute, 'certificate.record',
            function () use ($actor, $institute, $data): int {
                $source = $this->officialSource($institute, $data['regulatory_source_id'], $data['source_system']);
                $result = $this->one('SELECT id,enrollment_id,result_status FROM official_results
                    WHERE id=? AND institute_id=? FOR UPDATE', [$data['official_result_id'], $institute]);
                if (!$result || (int)$result['enrollment_id'] !== $data['enrollment_id']) {
                    throw new HttpException('OFFICIAL_IDENTITY', 'Certificate must belong to the same official result enrollment.', 409);
                }
                if ($data['document_type'] === 'NTC' && $result['result_status'] !== 'pass') {
                    throw new HttpException('CERTIFICATE_ELIGIBILITY', 'NTC import requires an officially recorded pass result.', 409);
                }
                // A certificate number is not the learner/document identity:
                // prevent a second independent revision 1 under a different number.
                $latest = $this->one('SELECT id,revision_no FROM certificates
                    WHERE enrollment_id=? AND document_type=? AND institute_id=?
                    ORDER BY revision_no DESC,id DESC LIMIT 1 FOR UPDATE',
                    [$data['enrollment_id'], $data['document_type'], $institute]);
                $prior = $data['supersedes_certificate_id'] ?? null;
                if (($latest===null && ($prior!==null || $data['revision_no']!==1))
                    || ($latest!==null
                        && ($prior!==(int)$latest['id']
                            || $data['revision_no']!==(int)$latest['revision_no']+1))) {
                    throw new HttpException('OFFICIAL_REVISION',
                        'Certificate revision must immediately follow the latest matching document.',409);
                }
                if ($prior !== null) {
                    $previous = $this->one('SELECT revision_no FROM certificates WHERE
                        id=? AND enrollment_id=? AND document_type=? AND institute_id=? FOR UPDATE',
                        [$prior, $data['enrollment_id'], $data['document_type'], $institute]);
                    if (!$previous || (int)$previous['revision_no'] + 1 !== $data['revision_no']) {
                        throw new HttpException('OFFICIAL_REVISION', 'Certificate must supersede a matching preceding revision.', 409);
                    }
                    if ($this->one('SELECT id FROM certificates WHERE
                        supersedes_certificate_id=? AND institute_id=? FOR UPDATE', [$prior, $institute])) {
                        throw new HttpException('OFFICIAL_REVISION', 'Selected certificate version already superseded.', 409);
                    }
                } elseif ($data['revision_no'] !== 1) {
                    throw new HttpException('OFFICIAL_REVISION', 'First certificate revision must be 1.', 409);
                }
                $snapshotHash = hash('sha256', json_encode([
                    $data['enrollment_id'], $data['official_result_id'], $data['document_type'],
                    $data['certificate_number'], $data['revision_no'], $prior,
                    $data['status'], $data['apaar_id_as_issued'], $data['credits_as_issued'],
                    $data['ojt_grade_as_issued'], $data['source_system'],
                    $data['source_reference'], $data['verification_reference'],
                    $data['issued_on'], $source['sha256'],
                ], JSON_THROW_ON_ERROR));
                $q = $this->db->prepare('INSERT INTO certificates
                    (institute_id,enrollment_id,official_result_id,document_type,certificate_number,
                     revision_no,supersedes_certificate_id,status,apaar_id_as_issued,credits_as_issued,
                     ojt_grade_as_issued,source_system,source_reference,payload_sha256,
                     verification_reference,issued_on,imported_by)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                $q->execute([
                    $institute, $data['enrollment_id'], $data['official_result_id'],
                    $data['document_type'], $data['certificate_number'], $data['revision_no'],
                    $prior, $data['status'], $data['apaar_id_as_issued'],
                    $data['credits_as_issued'], $data['ojt_grade_as_issued'],
                    $data['source_system'], $data['source_reference'], $snapshotHash,
                    $data['verification_reference'], $data['issued_on'], $actor->username,
                ]);
                $id = (int)$this->db->lastInsertId();
                (new InstituteTransaction($this->db))->audit(
                    $actor, $institute, 'official.certificate.import', 'certificates', $id,
                    ['regulatory_source_id' => $data['regulatory_source_id'],
                     'source_sha256' => $source['sha256'], 'source_reference' => $data['source_reference'],
                     'evidence_reference' => $data['evidence_reference'], 'snapshot_sha256' => $snapshotHash]
                );
                return $id;
            }
        );
    }
}
