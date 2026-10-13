<?php
declare(strict_types=1);

namespace App\Modules\Staff\Http;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Staff\Application\InstructorMappingService;

final readonly class InstructorMappingController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor,string $permission): int
    {
        $actor->requirePermission($permission);
        if (!in_array('principal',$actor->roles,true) || $actor->instituteId===null) {
            throw new HttpException('FORBIDDEN','Principal institute account required.',403);
        }
        return $actor->instituteId;
    }

    private static function id(Request $r,string $key): int
    {
        $v=$r->body[$key]??null;
        if (!is_int($v)||$v<1) {
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
        $value=self::text($r,$key,10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$value)
            || !$date || $date->format('Y-m-d')!==$value) {
            throw new HttpException('INVALID_INPUT','Real date required for '.$key.'.',422);
        }
        return $value;
    }

    public function verifyQualification(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'staff.manage');
        $id=self::id($r,'qualification_id');
        $source=self::id($r,'regulatory_source_id');
        $review=self::text($r,'review_reference',500);
        (new InstructorMappingService($this->database->connection()))->verifyQualification(
            $actor,$institute,$id,$source,$review);
        return new Response(['success'=>true,'data'=>['qualification_id'=>$id,'reviewed'=>true]]);
    }

    public function verifyIpRn(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'instructor.mapping.manage');
        $staff=self::id($r,'staff_id');
        $source=self::id($r,'regulatory_source_id');
        $iprn=self::text($r,'iprn',120);
        $reference=self::text($r,'verification_reference',190);
        $date=self::date($r,'verified_on');
        if ($date>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) {
            throw new HttpException('INVALID_INPUT','NIMS verification date cannot be in the future.',422);
        }
        (new InstructorMappingService($this->database->connection()))->verifyIpRn(
            $actor,$institute,$staff,$source,$iprn,$reference,$date);
        return new Response(['success'=>true,'data'=>[
            'staff_id'=>$staff,'nims_verification'=>'verified',
            'basis'=>'reviewed_NIMS_source_evidence'
        ]]);
    }

    public function assign(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'instructor.mapping.manage');
        $staff=self::id($r,'staff_id');
        $qualification=self::id($r,'qualification_id');
        $source=self::id($r,'qualification_source_id');
        $type=self::text($r,'mapping_type',20);
        if (!in_array($type,['trade','subject'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid mapping_type.',422);
        }
        $itsu=$r->body['itsu_revision_id']??null;
        $subject=$r->body['subject_code']??null;
        if ($type==='trade') {
            $itsu=self::id($r,'itsu_revision_id');
            if ($subject!==null) throw new HttpException('INVALID_INPUT','Trade mapping cannot specify subject_code.',422);
        } else {
            if ($itsu!==null || !is_string($subject)
                || !in_array($subject,['ES','WCS','ED','WCS_ED'],true)) {
                throw new HttpException('INVALID_INPUT','Official subject_code required without ITSU.',422);
            }
        }
        $date=self::date($r,'valid_from');
        $review=self::text($r,'review_reference',500);
        $id=(new InstructorMappingService($this->database->connection()))->assign(
            $actor,$institute,$staff,$qualification,$source,$type,$itsu,$subject,$date,$review);
        return new Response(['success'=>true,'data'=>['instructor_mapping_id'=>$id,'status'=>'active']],201);
    }

    public function release(Request $r,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'instructor.mapping.manage');
        $mapping=self::id($r,'mapping_id');
        $date=self::date($r,'valid_to');
        $source=self::id($r,'regulatory_source_id');
        $reference=self::text($r,'review_reference',500);
        (new InstructorMappingService($this->database->connection()))->release(
            $actor,$institute,$mapping,$date,$source,$reference);
        return new Response(['success'=>true,'data'=>[
            'instructor_mapping_id'=>$mapping,'status'=>'released'
        ]]);
    }
}
