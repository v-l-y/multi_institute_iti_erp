<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class TrainingCalendarMasterController
{
    public function __construct(private Database $database) {}

    private static function allow(AuthContext $actor): void
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
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
            throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        }
        return trim($v);
    }

    private static function date(Request $r,string $key): string
    {
        $v=self::text($r,$key,10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v)
            || !$date || $date->format('Y-m-d')!==$v) {
            throw new HttpException('INVALID_INPUT','Valid source date required.',422);
        }
        return $v;
    }

    private function service(): TrainingCalendarMasterService
    {
        return new TrainingCalendarMasterService($this->database->connection());
    }

    public function draft(Request $r,AuthContext $actor): Response
    {
        self::allow($actor);
        $year=self::id($r,'admission_year',65535);
        $session=self::text($r,'session_code',80);
        $revision=self::text($r,'revision_code',80);
        $source=self::id($r,'source_document_id');
        $from=self::date($r,'training_starts_on');
        $to=self::date($r,'training_ends_on');
        if ($to<$from) throw new HttpException('INVALID_INPUT','Training end precedes start.',422);
        $profile=$r->body['upload_profile']??null;
        if ($profile!==null && (!is_array($profile)||array_is_list($profile))) {
            throw new HttpException('INVALID_INPUT','Upload profile must be a reviewed JSON object.',422);
        }
        $encoded=$profile===null?null:json_encode($profile,JSON_THROW_ON_ERROR);
        if ($encoded!==null && strlen($encoded)>16000) {
            throw new HttpException('INVALID_INPUT','Upload profile is too large.',422);
        }
        $id=$this->service()->draft($actor,$year,$session,$revision,$source,$from,$to,$encoded);
        return new Response(['success'=>true,'data'=>['calendar_id'=>$id,'status'=>'draft']],201);
    }

    public function milestone(Request $r,AuthContext $actor): Response
    {
        self::allow($actor);
        $id=self::id($r,'calendar_id');
        $code=self::text($r,'milestone_code',80);
        $date=self::date($r,'milestone_date');
        $description=self::text($r,'description',500);
        $section=self::text($r,'document_section_reference',190);
        $created=$this->service()->milestone($actor,$id,$code,$date,$description,$section);
        return new Response(['success'=>true,'data'=>['milestone_id'=>$created]],201);
    }

    public function portalShift(Request $r,AuthContext $actor): Response
    {
        self::allow($actor);
        $id=self::id($r,'calendar_id');
        $part=self::text($r,'session_part',30);
        if (!in_array($part,['primary','six_month_second'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid session_part.',422);
        }
        $physical=self::id($r,'physical_shift_no',3);
        $portal=self::id($r,'portal_shift_no',255);
        $section=self::text($r,'document_section_reference',190);
        $created=$this->service()->shift($actor,$id,$part,$physical,$portal,$section);
        return new Response(['success'=>true,'data'=>['portal_shift_mapping_id'=>$created]],201);
    }

    public function publish(Request $r,AuthContext $actor): Response
    {
        self::allow($actor);
        $calendar=self::id($r,'calendar_id');
        $review=self::text($r,'review_reference',500);
        $this->service()->publish($actor,$calendar,$review);
        return new Response(['success'=>true,'data'=>[
            'calendar_id'=>$calendar,'status'=>'published',
            'basis'=>'registered_DGT_calendar_and_annexures'
        ]]);
    }
}
