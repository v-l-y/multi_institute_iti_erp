<?php
declare(strict_types=1);

namespace App\Modules\Regulatory;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class TradeMasterController
{
    public function __construct(private Database $database) {}

    private static function text(Request $r,string $field,int $max): string
    {
        $v=$r->body[$field]??null;
        if (!is_string($v) || trim($v)==='' || strlen(trim($v))>$max) {
            throw new HttpException('INVALID_INPUT',$field.' must be nonempty text.',422);
        }
        return trim($v);
    }

    private static function id(Request $r,string $field): int
    {
        $v=$r->body[$field]??null;
        if (!is_int($v) || $v<1) throw new HttpException('INVALID_INPUT','Positive integer '.$field.' required.',422);
        return $v;
    }

    private static function common(Request $request): array
    {
        $doc=self::id($request,'source_document_id');
        $version=self::text($request,'version_code',80);
        $name=self::text($request,'official_name',190);
        $type=self::text($request,'trade_type',30);
        if (!in_array($type,['engineering','non_engineering','divyang'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid DGT trade type.',422);
        }
        $starts=self::text($request,'effective_from',10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$starts);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$starts)
            || $date===false || $date->format('Y-m-d')!==$starts) {
            throw new HttpException('INVALID_INPUT','Valid effective_from required.',422);
        }
        return [$doc,$version,$name,$type,$starts];
    }

    private static function allowed(AuthContext $actor): void
    {
        $actor->requirePermission('platform.regulatory.manage');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
    }

    private function service(): TradeMasterService
    {
        return new TradeMasterService($this->database->connection());
    }

    public function create(Request $request,AuthContext $actor): Response
    {
        self::allowed($actor);
        $dgtCode=self::text($request,'dgt_code',80);
        [$doc,$ver,$name,$type,$date]=self::common($request);
        $data=$this->service()->register($actor,$dgtCode,$doc,$ver,$name,$type,$date);
        return new Response(['success'=>true,'data'=>$data],201);
    }

    public function draftRevision(Request $request,AuthContext $actor): Response
    {
        self::allowed($actor);
        $id=self::id($request,'trade_id');
        [$doc,$ver,$name,$type,$date]=self::common($request);
        $revision=$this->service()->draftRevision($actor,$id,$doc,$ver,$name,$type,$date);
        return new Response(['success'=>true,'data'=>[
            'revision_id'=>$revision,'trade_id'=>$id,'status'=>'draft'
        ]],201);
    }

    public function activate(Request $request,AuthContext $actor): Response
    {
        self::allowed($actor);
        $id=self::id($request,'revision_id');
        $evidence=self::text($request,'review_reference',500);
        $this->service()->activate($actor,$id,$evidence);
        return new Response(['success'=>true,'data'=>['revision_id'=>$id,'status'=>'active']]);
    }

    public function index(Request $request,AuthContext $actor): Response
    {
        self::allowed($actor);
        $after=0;$size=50;
        foreach(['after_id'=>PHP_INT_MAX,'page_size'=>100] as $key=>$max) {
            if (!array_key_exists($key,$request->query)) continue;
            $v=$request->query[$key];
            if ((!is_string($v)&&!is_int($v))
                || filter_var($v,FILTER_VALIDATE_INT,['options'=>[
                    'min_range'=>1,'max_range'=>$max]])===false) {
                throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
            }
            if ($key==='after_id') $after=(int)$v;
            else $size=(int)$v;
        }
        return new Response(['success'=>true,'data'=>$this->service()->list($actor,$after,$size)]);
    }
}
