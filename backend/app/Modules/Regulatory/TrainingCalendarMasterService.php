<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Transaction;
use App\Http\HttpException;
use App\Modules\Auth\Domain\AuthContext;
use PDO;
use PDOException;

/** DGT CTS calendar annexure registers: values always come from reviewed source. */
final readonly class TrainingCalendarMasterService
{
    public function __construct(private PDO $db) {}

    private static function authorize(AuthContext $actor): void
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
    }

    private function row(string $sql,array $args): ?array
    {
        $q=$this->db->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function actor(AuthContext $actor): void
    {
        if (!$this->row("SELECT id FROM auth_users
            WHERE id=? AND institute_id IS NULL AND status='active' FOR UPDATE",
            [$actor->userId])) {
            throw new HttpException('FORBIDDEN','Active platform user required.',403);
        }
    }

    private function source(int $id): void
    {
        $source=$this->row('SELECT authority,status,sha256
            FROM global_regulatory_documents WHERE id=? FOR UPDATE',[$id]);
        if (!$source || $source['authority']!=='DGT'
            || $source['status']!=='published' || !$source['sha256']) {
            throw new HttpException('CALENDAR_SOURCE_REQUIRED',
                'Published reviewed DGT calendar order and SHA-256 required.',409);
        }
    }

    private function audit(AuthContext $actor,string $action,array $payload): void
    {
        $this->db->prepare('INSERT INTO auth_audit_log
            (institute_id,user_id,event_type,event_data_json,occurred_at)
            VALUES (NULL,?,?,?,UTC_TIMESTAMP())')->execute([
                $actor->userId,$action,json_encode($payload,JSON_THROW_ON_ERROR)
            ]);
    }

    private function draftCalendar(int $id): array
    {
        $calendar=$this->row('SELECT id,status FROM cts_training_calendars
            WHERE id=? FOR UPDATE',[$id]);
        if (!$calendar) throw new HttpException('NOT_FOUND','CTS training calendar not found.',404);
        if ($calendar['status']!=='draft') {
            throw new HttpException('CALENDAR_FROZEN','Only draft calendar accepts annexures.',409);
        }
        return $calendar;
    }

    public function draft(AuthContext $actor,int $year,string $session,string $revision,
        int $document,string $starts,string $ends,?string $profile): int
    {
        self::authorize($actor);
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$year,$session,$revision,$document,$starts,$ends,$profile
            ): int {
                $this->actor($actor);
                $this->source($document);
                $q=$this->db->prepare("INSERT INTO cts_training_calendars
                    (admission_year,session_code,revision_code,source_document_id,
                     training_starts_on,training_ends_on,upload_profile_json,status)
                    VALUES (?,?,?,?,?,?,?,'draft')");
                $q->execute([$year,$session,$revision,$document,$starts,$ends,$profile]);
                $id=(int)$this->db->lastInsertId();
                $this->audit($actor,'platform.calendar.draft',[
                    'calendar_id'=>$id,'source_document_id'=>$document,
                    'admission_year'=>$year,'revision_code'=>$revision
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('CALENDAR_EXISTS','CTS training calendar revision already exists.',409);
            }
            throw $e;
        }
    }

    public function milestone(AuthContext $actor,int $calendar,string $code,
        string $date,string $description,string $section): int
    {
        self::authorize($actor);
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$calendar,$code,$date,$description,$section
            ): int {
                $this->actor($actor);
                $this->draftCalendar($calendar);
                $q=$this->db->prepare('INSERT INTO cts_training_calendar_milestones
                    (calendar_id,milestone_code,milestone_date,description,document_section_reference)
                    VALUES (?,?,?,?,?)');
                $q->execute([$calendar,$code,$date,$description,$section]);
                $id=(int)$this->db->lastInsertId();
                $this->audit($actor,'platform.calendar.milestone.add',[
                    'calendar_id'=>$calendar,'milestone_id'=>$id,'section_reference'=>$section
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('MILESTONE_EXISTS','CTS milestone code already registered.',409);
            }
            throw $e;
        }
    }

    public function shift(AuthContext $actor,int $calendar,string $sessionPart,
        int $physical,int $portal,string $section): int
    {
        self::authorize($actor);
        try {
            return (new Transaction($this->db))->run(function () use (
                $actor,$calendar,$sessionPart,$physical,$portal,$section
            ): int {
                $this->actor($actor);
                $this->draftCalendar($calendar);
                $q=$this->db->prepare('INSERT INTO cts_portal_shift_mappings
                    (calendar_id,session_part,physical_shift_no,portal_shift_no,
                     document_section_reference) VALUES (?,?,?,?,?)');
                $q->execute([$calendar,$sessionPart,$physical,$portal,$section]);
                $id=(int)$this->db->lastInsertId();
                $this->audit($actor,'platform.calendar.portal_shift.add',[
                    'calendar_id'=>$calendar,'mapping_id'=>$id,
                    'physical_shift_no'=>$physical,'portal_shift_no'=>$portal,
                    'section_reference'=>$section
                ]);
                return $id;
            });
        } catch(PDOException $e) {
            if (($e->errorInfo[1]??null)===1062) {
                throw new HttpException('PORTAL_SHIFT_EXISTS','Portal shift mapping already recorded.',409);
            }
            throw $e;
        }
    }

    public function publish(AuthContext $actor,int $calendar,string $review): void
    {
        self::authorize($actor);
        (new Transaction($this->db))->run(function () use (
            $actor,$calendar,$review
        ): void {
            $this->actor($actor);
            $this->draftCalendar($calendar);
            $source=$this->row('SELECT source_document_id
                FROM cts_training_calendars WHERE id=? FOR UPDATE',[$calendar]);
            $this->source((int)$source['source_document_id']);
            $milestone=$this->row('SELECT id FROM cts_training_calendar_milestones
                WHERE calendar_id=? LIMIT 1 FOR UPDATE',[$calendar]);
            $shift=$this->row('SELECT id FROM cts_portal_shift_mappings
                WHERE calendar_id=? LIMIT 1 FOR UPDATE',[$calendar]);
            if (!$milestone || !$shift) {
                throw new HttpException('CALENDAR_ANNEXURE_INCOMPLETE',
                    'Explicit CTS milestones and portal shift mappings required.',409);
            }
            $this->db->prepare("UPDATE cts_training_calendars
                SET status='published' WHERE id=? AND status='draft'")->execute([$calendar]);
            $this->audit($actor,'platform.calendar.publish',[
                'calendar_id'=>$calendar,'review_reference'=>$review,
                'source_document_id'=>$source['source_document_id']
            ]);
        });
    }
}
