<?php
declare(strict_types=1);

namespace App\Modules\Fees\Application;

use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Fees\Domain\Money;
use PDO;
use PDOException;

/** Source-free institute fee configuration; no government amounts or schedules are invented. */
final readonly class FeeSetupService
{
    public function __construct(private PDO $db) {}

    private function record(string $table, int $id, int $institute): array
    {
        // Internal fixed table names only, never request supplied.
        $q = $this->db->prepare("SELECT * FROM $table WHERE id=? AND institute_id=? FOR UPDATE");
        $q->execute([$id, $institute]);
        return $q->fetch(PDO::FETCH_ASSOC)
            ?: throw new HttpException('NOT_FOUND', 'Institute fee record not found.', 404);
    }

    private function run(AuthContext $actor, int $institute, callable $operation): mixed
    {
        try {
            return (new InstituteTransaction($this->db))->run($actor, $institute, 'fee.record', $operation);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                throw new HttpException('FEE_IDENTIFIER_EXISTS', 'Fee identifier or version already exists.', 409);
            }
            throw $e;
        }
    }

    public function createHead(AuthContext $actor, int $institute, string $code, string $name, bool $requiresEnrollment): int
    {
        return $this->run($actor, $institute, function () use ($actor, $institute, $code, $name, $requiresEnrollment): int {
            $q = $this->db->prepare('INSERT INTO fee_heads (institute_id,code,name,requires_enrollment) VALUES (?,?,?,?)');
            $q->execute([$institute, $code, $name, (int)$requiresEnrollment]);
            $id = (int)$this->db->lastInsertId();
            (new InstituteTransaction($this->db))->audit($actor, $institute, 'fee.head.create', 'fee_heads', $id, [
                'code' => $code, 'requires_enrollment' => $requiresEnrollment,
            ]);
            return $id;
        });
    }

    public function draftStructure(AuthContext $actor, int $institute, int $trade, int $version, string $from, ?string $to): int
    {
        return $this->run($actor, $institute, function () use ($actor, $institute, $trade, $version, $from, $to): int {
            $q = $this->db->prepare('SELECT id FROM trades WHERE id=?');
            $q->execute([$trade]);
            if ($q->fetchColumn() === false) {
                throw new HttpException('TRADE_NOT_FOUND', 'Global trade does not exist.', 404);
            }
            $q = $this->db->prepare('INSERT INTO fee_structures
                (institute_id,trade_id,version_no,effective_from,effective_to,created_by)
                VALUES (?,?,?,?,?,?)');
            $q->execute([$institute, $trade, $version, $from, $to, $actor->userId]);
            $id = (int)$this->db->lastInsertId();
            (new InstituteTransaction($this->db))->audit($actor, $institute, 'fee.structure.draft', 'fee_structures', $id, [
                'trade_id' => $trade, 'version_no' => $version,
            ]);
            return $id;
        });
    }

    public function setItem(AuthContext $actor, int $institute, int $structureId, int $headId, string $amount): int
    {
        $value = Money::decimal(Money::cents($amount));
        return $this->run($actor, $institute, function () use ($actor, $institute, $structureId, $headId, $value): int {
            $structure = $this->record('fee_structures', $structureId, $institute);
            $head = $this->record('fee_heads', $headId, $institute);
            if ($structure['status'] !== 'draft' || (int)$head['is_active'] !== 1) {
                throw new HttpException('FEE_CONFIG_FROZEN', 'Draft structure and active fee head required.', 409);
            }
            $q = $this->db->prepare('SELECT id FROM fee_structure_items WHERE institute_id=? AND structure_id=? AND head_id=? FOR UPDATE');
            $q->execute([$institute, $structureId, $headId]);
            if ($q->fetchColumn() !== false) {
                throw new HttpException('FEE_ITEM_EXISTS', 'This head is already present in this version.', 409);
            }
            $q = $this->db->prepare('INSERT INTO fee_structure_items (institute_id,structure_id,head_id,amount) VALUES (?,?,?,?)');
            $q->execute([$institute, $structureId, $headId, $value]);
            $id = (int)$this->db->lastInsertId();
            (new InstituteTransaction($this->db))->audit($actor, $institute, 'fee.structure.item.create', 'fee_structure_items', $id, [
                'structure_id' => $structureId, 'head_id' => $headId, 'amount' => $value,
            ]);
            return $id;
        });
    }

    public function activateStructure(AuthContext $actor, int $institute, int $structureId): void
    {
        $this->run($actor, $institute, function () use ($actor, $institute, $structureId): void {
            $structure = $this->record('fee_structures', $structureId, $institute);
            if ($structure['status'] !== 'draft') {
                throw new HttpException('FEE_STRUCTURE_FROZEN', 'Only a draft fee version can be activated.', 409);
            }
            $q = $this->db->prepare('SELECT i.id,h.is_active FROM fee_structure_items i
                JOIN fee_heads h ON h.id=i.head_id AND h.institute_id=i.institute_id
                WHERE i.structure_id=? AND i.institute_id=? FOR UPDATE');
            $q->execute([$structureId, $institute]);
            $items = $q->fetchAll(PDO::FETCH_ASSOC);
            if ($items === [] || count(array_filter($items, static fn(array $i): bool => (int)$i['is_active'] !== 1)) > 0) {
                throw new HttpException('FEE_STRUCTURE_ITEMS', 'All configured heads must be active and at least one item required.', 409);
            }
            // Preserve old version/issued assessment records; supersede only the active pointer.
            $q = $this->db->prepare("UPDATE fee_structures SET status='inactive'
                WHERE institute_id=? AND trade_id=? AND status='active'");
            $q->execute([$institute, $structure['trade_id']]);
            $q = $this->db->prepare("UPDATE fee_structures
                SET status='active',activated_by=?,activated_at=UTC_TIMESTAMP()
                WHERE id=? AND institute_id=? AND status='draft'");
            $q->execute([$actor->userId, $structureId, $institute]);
            (new InstituteTransaction($this->db))->audit($actor, $institute, 'fee.structure.activate', 'fee_structures', $structureId, [
                'trade_id' => (int)$structure['trade_id'], 'version_no' => (int)$structure['version_no'],
            ]);
        });
    }

    public function draftAssessment(
        AuthContext $actor, int $institute, int $studentId, ?int $enrollmentId,
        int $structureId, string $number, string $assessedOn
    ): int {
        return $this->run($actor, $institute, function () use (
            $actor, $institute, $studentId, $enrollmentId, $structureId, $number, $assessedOn
        ): int {
            $this->record('students', $studentId, $institute);
            $structure = $this->record('fee_structures', $structureId, $institute);
            if ($structure['status'] !== 'active' || $structure['effective_from'] > $assessedOn
                || ($structure['effective_to'] !== null && $structure['effective_to'] < $assessedOn)) {
                throw new HttpException('FEE_STRUCTURE', 'Applicable active fee structure required.', 409);
            }
            if ($enrollmentId !== null) {
                $enrollment = $this->record('enrollments', $enrollmentId, $institute);
                if ((int)$enrollment['student_id'] !== $studentId
                    || in_array($enrollment['status'], ['cancelled', 'dropout', 'transferred'], true)) {
                    throw new HttpException('ASSESSMENT_ENROLLMENT', 'Matching active student enrollment required.', 409);
                }
                $batch = $this->record('batches', (int)$enrollment['batch_id'], $institute);
                if ((int)$batch['trade_id'] !== (int)$structure['trade_id']) {
                    throw new HttpException('ASSESSMENT_ENROLLMENT', 'Enrollment trade must match fee version.', 409);
                }
            }
            $q = $this->db->prepare('SELECT i.head_id,i.amount,h.code,h.name,h.is_active,h.requires_enrollment
                FROM fee_structure_items i JOIN fee_heads h ON h.id=i.head_id AND h.institute_id=i.institute_id
                WHERE i.structure_id=? AND i.institute_id=? FOR UPDATE');
            $q->execute([$structureId, $institute]);
            $items = $q->fetchAll(PDO::FETCH_ASSOC);
            if ($items === []) {
                throw new HttpException('ASSESSMENT_ITEMS', 'Configured fee items required.', 409);
            }
            foreach ($items as $item) {
                if ((int)$item['is_active'] !== 1
                    || ($enrollmentId === null && (int)$item['requires_enrollment'] === 1)) {
                    throw new HttpException('ASSESSMENT_ITEMS', 'Current active heads and enrollment requirements must be satisfied.', 409);
                }
            }
            $q = $this->db->prepare('INSERT INTO student_fee_assessments
                (institute_id,student_id,enrollment_id,structure_id,assessment_no,assessed_on,created_by)
                VALUES (?,?,?,?,?,?,?)');
            $q->execute([$institute, $studentId, $enrollmentId, $structureId, $number, $assessedOn, $actor->userId]);
            $id = (int)$this->db->lastInsertId();
            $q = $this->db->prepare('INSERT INTO student_fee_assessment_items
                (institute_id,assessment_id,head_id,head_code,head_name,amount) VALUES (?,?,?,?,?,?)');
            foreach ($items as $item) {
                $q->execute([$institute, $id, $item['head_id'], $item['code'], $item['name'], $item['amount']]);
            }
            (new InstituteTransaction($this->db))->audit($actor, $institute, 'fee.assessment.draft', 'student_fee_assessments', $id, [
                'student_id' => $studentId, 'structure_id' => $structureId, 'enrollment_id' => $enrollmentId,
            ]);
            return $id;
        });
    }
}
