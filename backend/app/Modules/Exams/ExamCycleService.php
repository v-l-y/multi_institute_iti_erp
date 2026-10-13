<?php
declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** Local CTS exam-cycle setup from an institute-linked published official source. */
final readonly class ExamCycleService
{
    public function __construct(private PDO $db) {}

    /**
     * A published syllabus/training calendar is not evidence of an exam date.
     * This is a type guard, not independent authentication of the publication.
     */
    public static function acceptsScheduleDocumentType(string $type): bool
    {
        $normalized = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', trim($type));
        $normalized = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $normalized ?? '');
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($normalized ?? ''),
            -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $isExam = array_intersect($tokens, ['exam', 'examination', 'aitt']) !== [];
        $isSchedule = array_intersect($tokens, [
            'schedule', 'calendar', 'timetable', 'datesheet', 'programme', 'program',
        ]) !== [];
        return $isExam && $isSchedule;
    }

    private function one(string $sql, array $args): ?array
    {
        $q = $this->db->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function draft(
        AuthContext $actor, int $institute, string $code, string $type, int $ruleId,
        int $scheduleSource, string $cutoff, string $startsOn, string $windows
    ): int {
        try {
            return (new InstituteTransaction($this->db))->run(
                $actor, $institute, 'exam.eligibility.manage',
                function () use ($actor, $institute, $code, $type, $ruleId, $scheduleSource, $cutoff, $startsOn, $windows): int {
                    $rule = $this->one('SELECT g.status,g.source_document_id,g.effective_from,g.effective_to
                        FROM exam_rule_versions r JOIN global_exam_rule_versions g
                          ON g.id=r.global_exam_rule_id AND g.cycle_type=r.cycle_type
                        WHERE r.id=? AND r.cycle_type=? AND r.institute_id=? FOR UPDATE',
                        [$ruleId, $type, $institute]);
                    if (!$rule || $rule['status'] !== 'published' || $cutoff < $rule['effective_from']
                        || ($rule['effective_to'] !== null && $cutoff > $rule['effective_to'])) {
                        throw new HttpException('EXAM_RULE_UNREVIEWED', 'Applicable published exam rule required.', 409);
                    }
                    $ruleSource = $this->one('SELECT d.id,d.status,d.sha256 FROM regulatory_sources s
                        JOIN global_regulatory_documents d ON d.id=s.global_document_id
                        WHERE s.global_document_id=? AND s.institute_id=? FOR UPDATE',
                        [$rule['source_document_id'], $institute]);
                    $schedule = $this->one('SELECT d.id,d.status,d.sha256,d.authority,d.document_type
                        FROM regulatory_sources s JOIN global_regulatory_documents d ON d.id=s.global_document_id
                        WHERE s.id=? AND s.institute_id=? FOR UPDATE', [$scheduleSource, $institute]);
                    if (!$ruleSource || $ruleSource['status'] !== 'published' || !$ruleSource['sha256']
                        || !$schedule || $schedule['status'] !== 'published' || !$schedule['sha256']) {
                        throw new HttpException('EXAM_SOURCE_UNREVIEWED', 'Published institute-linked official rule and schedule evidence required.', 409);
                    }
                    if (!self::acceptsScheduleDocumentType((string)$schedule['document_type'])) {
                        throw new HttpException('EXAM_SCHEDULE_SOURCE_TYPE',
                            'Exam calendar or schedule source required, not a general training document.', 409);
                    }
                    $q = $this->db->prepare('INSERT INTO exam_cycles
                        (institute_id,code,cycle_type,rule_id,schedule_source_id,eligibility_cutoff,
                         exam_starts_on,activity_windows_json,status)
                        VALUES (?,?,?,?,?,?,?,?,"draft")');
                    $q->execute([$institute, $code, $type, $ruleId, $scheduleSource, $cutoff, $startsOn, $windows]);
                    $id = (int)$this->db->lastInsertId();
                    (new InstituteTransaction($this->db))->audit(
                        $actor, $institute, 'exam.cycle.draft', 'exam_cycles', $id,
                        ['code' => $code, 'cycle_type' => $type, 'rule_id' => $ruleId, 'source_id' => $scheduleSource]
                    );
                    return $id;
                }
            );
        } catch (PDOException $exception) {
            if ((int)($exception->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException('EXAM_CYCLE_EXISTS', 'Exam cycle code already exists for this institute.', 409);
            }
            throw $exception;
        }
    }

    public function open(AuthContext $actor, int $institute, int $cycleId, string $reviewReference): void
    {
        if (!in_array('principal', $actor->roles, true)) {
            throw new HttpException('PRINCIPAL_REQUIRED', 'Principal review is required.', 403);
        }
        (new InstituteTransaction($this->db))->run(
            $actor, $institute, 'exam.eligibility.manage',
            function () use ($actor, $institute, $cycleId, $reviewReference): void {
                $cycle = $this->one('SELECT * FROM exam_cycles
                    WHERE id=? AND institute_id=? FOR UPDATE', [$cycleId, $institute]);
                if (!$cycle) {
                    throw new HttpException('NOT_FOUND', 'Exam cycle not found.', 404);
                }
                if ($cycle['status'] !== 'draft') {
                    throw new HttpException('EXAM_CYCLE_FROZEN', 'Only a draft cycle can be opened.', 409);
                }
                $rule = $this->one('SELECT g.status,g.source_document_id
                    FROM exam_rule_versions r JOIN global_exam_rule_versions g ON g.id=r.global_exam_rule_id
                    WHERE r.id=? AND r.cycle_type=? AND r.institute_id=? FOR UPDATE',
                    [$cycle['rule_id'], $cycle['cycle_type'], $institute]);
                $schedule = $this->one('SELECT d.id,d.status,d.sha256,d.document_type
                    FROM regulatory_sources s JOIN global_regulatory_documents d ON d.id=s.global_document_id
                    WHERE s.id=? AND s.institute_id=? FOR UPDATE', [$cycle['schedule_source_id'], $institute]);
                $ruleSource = $rule ? $this->one('SELECT d.id,d.status,d.sha256
                    FROM regulatory_sources s JOIN global_regulatory_documents d ON d.id=s.global_document_id
                    WHERE s.global_document_id=? AND s.institute_id=? FOR UPDATE',
                    [$rule['source_document_id'], $institute]) : null;
                if (!$rule || $rule['status'] !== 'published'
                    || !$schedule || $schedule['status'] !== 'published' || !$schedule['sha256']
                    || !$ruleSource || $ruleSource['status'] !== 'published' || !$ruleSource['sha256']) {
                    throw new HttpException('EXAM_SOURCE_UNREVIEWED', 'Published rule and schedule required at opening.', 409);
                }
                if (!self::acceptsScheduleDocumentType((string)$schedule['document_type'])) {
                    throw new HttpException('EXAM_SCHEDULE_SOURCE_TYPE',
                        'Published document is not an exam schedule.', 409);
                }
                $q = $this->db->prepare("UPDATE exam_cycles SET status='eligibility_open'
                    WHERE id=? AND institute_id=? AND status='draft'");
                $q->execute([$cycleId, $institute]);
                (new InstituteTransaction($this->db))->audit(
                    $actor, $institute, 'exam.cycle.open', 'exam_cycles', $cycleId,
                    ['review_reference' => $reviewReference, 'rule_id' => (int)$cycle['rule_id'],
                     'schedule_source_id' => (int)$cycle['schedule_source_id']]
                );
            }
        );
    }
}
