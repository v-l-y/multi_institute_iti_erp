<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class RegulatoryDocumentController
{
    public function __construct(private Database $database) {}

    private static function text(Request $request,string $key,int $max): string
    {
        $v=$request->body[$key]??null;
        if (!is_string($v) || trim($v)==='' || strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT',$key.' must be nonempty text under '.$max.' bytes.',422);
        }
        return trim($v);
    }

    private static function nullable(Request $request,string $key,int $max): ?string
    {
        $v=$request->body[$key]??null;
        if ($v===null) return null;
        if (!is_string($v) || strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        }
        return trim($v)===''?null:trim($v);
    }

    private static function date(Request $request,string $key): string
    {
        $v=self::text($request,$key,10);
        $day=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$v)
            || $day===false || $day->format('Y-m-d')!==$v
            || $v>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) {
            throw new HttpException('INVALID_INPUT','Valid non-future issue date required.',422);
        }
        return $v;
    }

    private function service(): RegulatoryDocumentService
    {
        return new RegulatoryDocumentService($this->database->connection());
    }

    public function draft(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Developer authority required.',403);
        }
        $authority=self::text($request,'authority',20);
        if (!in_array($authority,['DGT','NCVET','STATE_UT','SSDEC'],true)) {
            throw new HttpException('INVALID_INPUT','Unsupported regulatory authority.',422);
        }
        $source=self::nullable($request,'source_system',30);
        if ($source!==null && !in_array($source,['SIDH','NCVT_MIS','NIMS'],true)) {
            throw new HttpException('INVALID_INPUT','Unsupported evidence system.',422);
        }
        $state=self::nullable($request,'state_ut_code',20);
        if (in_array($authority,['STATE_UT','SSDEC'],true)) {
            if ($state!=='BR') {
                throw new HttpException('INVALID_INPUT','Bihar state source code BR is required.',422);
            }
        } elseif ($state!==null && $state!=='BR') {
            throw new HttpException('INVALID_INPUT','Only Bihar-specific state code supported.',422);
        }
        $url=self::nullable($request,'source_url',900);
        $reference=self::nullable($request,'evidence_reference',500);
        if ($url===null && $reference===null) {
            throw new HttpException('INVALID_INPUT','At least one source URL or evidence reference required.',422);
        }
        if ($url!==null && (!filter_var($url,FILTER_VALIDATE_URL)
            || !in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['https'],true))) {
            throw new HttpException('INVALID_INPUT','HTTPS source URL required.',422);
        }
        $hash=self::text($request,'sha256',64);
        if (!preg_match('/^[a-fA-F0-9]{64}$/D',$hash)) {
            throw new HttpException('INVALID_INPUT','SHA-256 digest must contain 64 hexadecimal characters.',422);
        }
        $previous=$request->body['supersedes_id']??null;
        if ($previous!==null && (!is_int($previous) || $previous<1)) {
            throw new HttpException('INVALID_INPUT','Invalid supersedes_id.',422);
        }
        $data=[
            'authority'=>$authority,'source_system'=>$source,'state_ut_code'=>$state,
            'document_type'=>self::text($request,'document_type',60),
            'title'=>self::text($request,'title',255),
            'reference_no'=>self::text($request,'reference_no',190),
            'issued_on'=>self::date($request,'issued_on'),
            'source_url'=>$url,'evidence_reference'=>$reference,
            'sha256'=>strtolower($hash),'supersedes_id'=>$previous
        ];
        $id=$this->service()->draft($actor,$data);
        return new Response(['success'=>true,'data'=>[
            'document_id'=>$id,'status'=>'draft','government_verified'=>false
        ]],201);
    }

    public function publish(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Developer authority required.',403);
        }
        $id=$request->body['document_id']??null;
        if (!is_int($id) || $id<1) {
            throw new HttpException('INVALID_INPUT','Positive integer document_id required.',422);
        }
        $evidence=self::text($request,'review_reference',500);
        $this->service()->publish($actor,$id,$evidence);
        return new Response(['success'=>true,'data'=>[
            'document_id'=>$id,'status'=>'published','review_recorded'=>true
        ]]);
    }

    public function index(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Developer authority required.',403);
        }
        $after=0;$size=50;
        foreach (['after_id'=>PHP_INT_MAX,'page_size'=>100] as $name=>$max) {
            if (!array_key_exists($name,$request->query)) continue;
            $raw=$request->query[$name];
            if ((!is_string($raw)&&!is_int($raw))
                || filter_var($raw,FILTER_VALIDATE_INT,['options'=>[
                    'min_range'=>1,'max_range'=>$max
                ]])===false) {
                throw new HttpException('INVALID_INPUT','Invalid '.$name.'.',422);
            }
            if ($name==='after_id') $after=(int)$raw;
            else $size=(int)$raw;
        }
        return new Response(['success'=>true,'data'=>$this->service()->list($actor,$after,$size)]);
    }
}
