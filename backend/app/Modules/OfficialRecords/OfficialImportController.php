<?php
declare(strict_types=1);

namespace App\Modules\OfficialRecords;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/** Import records from externally verified authority evidence; never issue an NTC. */
final readonly class OfficialImportController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor, string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId ?? throw new HttpException('FORBIDDEN', 'Institute scope required.', 403);
    }

    private static function id(Request $r, string $key): int
    {
        $value = $r->body[$key] ?? null;
        if (!is_int($value) || $value < 1) {
            throw new HttpException('INVALID_INPUT', 'Positive integer ' . $key . ' required.', 422);
        }
        return $value;
    }

    private static function optionalId(Request $r, string $key): ?int
    {
        if (!isset($r->body[$key])) {
            return null;
        }
        return self::id($r, $key);
    }

    private static function text(Request $r, string $key, int $max): string
    {
        $value = $r->body[$key] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return trim($value);
    }

    private static function optionalText(Request $r, string $key, int $max): ?string
    {
        if (!isset($r->body[$key])) {
            return null;
        }
        return self::text($r, $key, $max);
    }

    private static function choice(Request $r, string $key, array $valid): string
    {
        $value = self::text($r, $key, 50);
        if (!in_array($value, $valid, true)) {
            throw new HttpException('INVALID_INPUT', 'Unsupported ' . $key . '.', 422);
        }
        return $value;
    }

    private static function date(Request $r, string $key, bool $datetime = false): string
    {
        $value = self::text($r, $key, $datetime ? 19 : 10);
        $format = $datetime ? 'Y-m-d H:i:s' : 'Y-m-d';
        $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $value);
        if (!$parsed || $parsed->format($format) !== $value) {
            throw new HttpException('INVALID_INPUT', 'Real ISO date/time required for ' . $key . '.', 422);
        }
        return $value;
    }

    private static function revision(Request $r): int
    {
        $revision = self::id($r, 'revision_no');
        if ($revision > 65535) {
            throw new HttpException('INVALID_INPUT', 'Revision is out of range.', 422);
        }
        return $revision;
    }

    private static function source(Request $r): array
    {
        return [
            'regulatory_source_id' => self::id($r, 'regulatory_source_id'),
            'source_system' => self::choice($r, 'source_system', ['DGT', 'SIDH', 'NCVT_MIS']),
            'source_reference' => self::text($r, 'source_reference', 190),
            'evidence_reference' => self::text($r, 'evidence_reference', 500),
        ];
    }

    public function result(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'result.import');
        $marks = $r->body['component_marks'] ?? null;
        if ($marks instanceof \stdClass) {
            $marks = (array)$marks;
        }
        if (!is_array($marks) || $marks === [] || array_is_list($marks)) {
            throw new HttpException('INVALID_INPUT', 'Official component marks object is required.', 422);
        }
        $encoded = json_encode($marks, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 12000) {
            throw new HttpException('INVALID_INPUT', 'Official component marks exceed limit.', 422);
        }
        $grade = self::optionalText($r, 'ojt_grade', 2);
        if ($grade !== null && !in_array($grade, ['A1', 'A', 'B', 'C'], true)) {
            throw new HttpException('INVALID_INPUT', 'Unknown official OJT grade.', 422);
        }
        $data = [
            ...self::source($r),
            'enrollment_id' => self::id($r, 'enrollment_id'),
            'batch_year_id' => self::id($r, 'batch_year_id'),
            'cycle_id' => self::id($r, 'cycle_id'),
            'result_status' => self::choice($r, 'result_status', [
                'pass', 'fail', 'promoted_with_back', 'withheld', 'absent', 'not_declared',
            ]),
            'component_marks' => $marks,
            'ojt_grade' => $grade,
            'revision_no' => self::revision($r),
            'supersedes_result_id' => self::optionalId($r, 'supersedes_result_id'),
            'declared_at' => self::date($r, 'declared_at', true),
        ];
        $id = (new OfficialImportService($this->database->connection()))->result($actor, $institute, $data);
        return new Response(['success' => true, 'data' => ['official_result_id' => $id, 'status' => 'imported_local_copy']], 201);
    }

    public function certificate(Request $r, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'certificate.record');
        $grade = self::optionalText($r, 'ojt_grade_as_issued', 2);
        if ($grade !== null && !in_array($grade, ['A1', 'A', 'B', 'C'], true)) {
            throw new HttpException('INVALID_INPUT', 'Unknown official OJT grade.', 422);
        }
        $credits = self::optionalText($r, 'credits_as_issued', 10);
        if ($credits !== null && (preg_match('/^(0|[1-9][0-9]{0,5})(?:\.[0-9]{1,2})?$/D', $credits) !== 1)) {
            throw new HttpException('INVALID_INPUT', 'Credits must be a nonnegative value within official record precision.', 422);
        }
        $data = [
            ...self::source($r),
            'enrollment_id' => self::id($r, 'enrollment_id'),
            'official_result_id' => self::id($r, 'official_result_id'),
            'document_type' => self::choice($r, 'document_type', ['NTC', 'marksheet']),
            'certificate_number' => self::text($r, 'certificate_number', 120),
            'revision_no' => self::revision($r),
            'supersedes_certificate_id' => self::optionalId($r, 'supersedes_certificate_id'),
            'status' => self::choice($r, 'status', ['valid', 'revoked']),
            'apaar_id_as_issued' => self::optionalText($r, 'apaar_id_as_issued', 30),
            'credits_as_issued' => $credits,
            'ojt_grade_as_issued' => $grade,
            'verification_reference' => self::text($r, 'verification_reference', 255),
            'issued_on' => self::date($r, 'issued_on'),
        ];
        $id = (new OfficialImportService($this->database->connection()))->certificate($actor, $institute, $data);
        return new Response(['success' => true, 'data' => ['certificate_id' => $id, 'status' => 'imported_local_copy']], 201);
    }
}
