<?php
declare(strict_types=1);

namespace App\Modules\Exams;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class PracticalPortalReceiptController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor): int
    {
        $actor->requirePermission('exam.portal.submit');
        if (!in_array('principal',$actor->roles,true) || $actor->instituteId===null) {
            throw new HttpException('FORBIDDEN','Principal institute account required.',403);
        }
        return $actor->instituteId;
    }

    private static function id(Request $r,string $key,int $max=PHP_INT_MAX): int
    {
        $v=$r->body[$key]??null;
        if (!is_int($v)||$v<1||$v>$max) {
            throw new HttpException('INVALID_INPUT','Positive integer '.$key.' required.',422);
        }
        return $v;
    }

    private static function text(Request $r,string $key,int $max): string
    {
        $v=$r->body[$key]??null;
        if (!is_string($v)||trim($v)===''||strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT','Valid '.$key.' required.',422);
        }
        return trim($v);
    }

    private static function isoTime(Request $r,string $key): string
    {
        $v=self::text($r,$key,19);
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$v,
            new \DateTimeZone('Asia/Kolkata'));
        if (!$d || $d->format('Y-m-d H:i:s')!==$v
            || $d>new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata'))) {
            throw new HttpException('INVALID_INPUT',
                'Completed actual external receipt date/time required.',422);
        }
        return $v;
    }

    private function service(): PracticalPortalReceiptService
    {
        return new PracticalPortalReceiptService($this->database->connection());
    }

    public function submitted(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $session=self::id($r,'practical_session_id');
        $attempt=self::id($r,'attempt_no',65535);
        $source=self::id($r,'regulatory_source_id');
        $when=self::isoTime($r,'submitted_at');
        $expected=self::id($r,'expected_records',4294967295);
        $count=self::id($r,'submitted_records',4294967295);
        $reference=self::text($r,'portal_reference',190);
        $hash=self::text($r,'payload_sha256',64);
        if (!preg_match('/^[0-9a-fA-F]{64}$/D',$hash)) {
            throw new HttpException('INVALID_INPUT','SHA-256 payload hash required.',422);
        }
        $evidence=self::text($r,'response_evidence_reference',190);
        $id=$this->service()->submitted($actor,$institute,$session,$attempt,$source,
            $when,$expected,$count,$reference,strtolower($hash),$evidence);
        return new Response(['success'=>true,'data'=>[
            'portal_submission_id'=>$id,'status'=>'submitted',
            'external_verification'=>'operator_attested_not_automatically_verified'
        ]],201);
    }

    public function acknowledge(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $id=self::id($r,'portal_submission_id');
        $status=self::text($r,'response_status',20);
        if (!in_array($status,['accepted','rejected'],true)) {
            throw new HttpException('INVALID_INPUT','External response must be accepted or rejected.',422);
        }
        $acceptedAt=null;
        if ($status==='accepted') {
            $acceptedAt=self::isoTime($r,'accepted_at');
        } elseif (array_key_exists('accepted_at',$r->body)
            && $r->body['accepted_at']!==null) {
            throw new HttpException('INVALID_INPUT','Rejected receipt cannot have accepted_at.',422);
        }
        $source=self::id($r,'regulatory_source_id');
        $evidence=self::text($r,'response_evidence_reference',190);
        $review=self::text($r,'review_reference',500);
        $this->service()->acknowledge($actor,$institute,$id,$status,$acceptedAt,
            $source,$evidence,$review);
        return new Response(['success'=>true,'data'=>[
            'portal_submission_id'=>$id,'recorded_external_response'=>$status,
            'government_verified'=>false
        ]]);
    }
}
