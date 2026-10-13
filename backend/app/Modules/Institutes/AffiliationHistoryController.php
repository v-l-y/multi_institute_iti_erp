<?php
declare(strict_types=1);

namespace App\Modules\Institutes;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class AffiliationHistoryController
{
    public function __construct(private Database $database) {}

    private static function text(Request $request,string $key,int $limit): string
    {
        $v=$request->body[$key]??null;
        if (!is_string($v)||trim($v)===''||strlen(trim($v))>$limit) {
            throw new HttpException('INVALID_INPUT','Valid '.$key.' required.',422);
        }
        return trim($v);
    }

    private static function date(Request $request,string $key): string
    {
        $v=self::text($request,$key,10);
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v)
            || !$d || $d->format('Y-m-d')!==$v) {
            throw new HttpException('INVALID_INPUT','Real date required for '.$key.'.',422);
        }
        return $v;
    }

    public function record(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('institute.affiliation.manage');
        $institute=$actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute scope required.',403);
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal review required.',403);
        }
        $source=$request->body['regulatory_source_id']??null;
        if (!is_int($source)||$source<1) {
            throw new HttpException('INVALID_INPUT','Positive regulatory_source_id required.',422);
        }
        $change=self::text($request,'change_type',60);
        $name=self::text($request,'approved_name',190);
        $address=self::text($request,'approved_address',16000);
        $status=self::text($request,'affiliation_status',30);
        if (!in_array($status,['affiliated','suspended','deactivated','deaffiliated'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid source-declared affiliation status.',422);
        }
        $reference=self::text($request,'affiliation_order_reference',190);
        $from=self::date($request,'effective_from');
        $id=(new AffiliationHistoryService($this->database->connection()))->record(
            $actor,$institute,$source,$change,$name,$address,$status,$reference,$from);
        return new Response(['success'=>true,'data'=>[
            'affiliation_history_id'=>$id,'status'=>$status,
            'basis'=>'locally_reviewed_DGT_order'
        ]],201);
    }
}
