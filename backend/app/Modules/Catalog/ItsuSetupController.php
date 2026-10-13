<?php
declare(strict_types=1);

namespace App\Modules\Catalog;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

final readonly class ItsuSetupController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor,string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute account required.',403);
    }

    private static function positive(Request $request,string $key,int $max=PHP_INT_MAX): int
    {
        $v=$request->body[$key]??null;
        if (!is_int($v) || $v<1 || $v>$max) {
            throw new HttpException('INVALID_INPUT','Positive integer '.$key.' required.',422);
        }
        return $v;
    }

    private static function text(Request $request,string $key,int $limit): string
    {
        $v=$request->body[$key]??null;
        if (!is_string($v)||trim($v)===''||strlen(trim($v))>$limit) {
            throw new HttpException('INVALID_INPUT',$key.' must be nonempty text.',422);
        }
        return trim($v);
    }

    private function service(): ItsuSetupService
    {
        return new ItsuSetupService($this->database->connection());
    }

    public function attachSource(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'institute.affiliation.manage');
        $doc=self::positive($request,'global_document_id');
        $ref=$request->body['local_evidence_reference']??null;
        if ($ref!==null && (!is_string($ref) || trim($ref)==='' || strlen(trim($ref))>500)) {
            throw new HttpException('INVALID_INPUT','Invalid local_evidence_reference.',422);
        }
        $id=$this->service()->attachOfficialSource($actor,$institute,$doc,$ref===null?null:trim($ref));
        return new Response(['success'=>true,'data'=>['regulatory_source_id'=>$id]],201);
    }

    public function createTradeUnit(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'tradeunit.manage');
        $trade=self::positive($request,'global_trade_id');
        $unit=self::positive($request,'unit_no',65535);
        $id=$this->service()->tradeUnit($actor,$institute,$trade,$unit);
        return new Response(['success'=>true,'data'=>['trade_unit_id'=>$id]],201);
    }

    public function createShiftRevision(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'tradeunit.manage');
        $unit=self::positive($request,'trade_unit_id');
        $shift=self::positive($request,'global_shift_id');
        $source=self::positive($request,'regulatory_source_id');
        $capacity=self::positive($request,'sanctioned_capacity',65535);
        $reference=self::text($request,'approval_reference',190);
        $starts=self::text($request,'valid_from',10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$starts);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$starts)
            || $date===false || $date->format('Y-m-d')!==$starts) {
            throw new HttpException('INVALID_INPUT','Valid approval effective date required.',422);
        }
        $affiliation=self::text($request,'affiliation_status',30);
        if (!in_array($affiliation,['approved','suspended','surrendered','deaffiliated'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid affiliation status.',422);
        }
        $mode=self::text($request,'training_mode',10);
        if (!in_array($mode,['CTS','DST'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid training mode.',422);
        }
        $id=$this->service()->shiftRevision($actor,$institute,$unit,$shift,
            $source,$reference,$capacity,$starts,$affiliation,$mode);
        return new Response(['success'=>true,'data'=>[
            'itsu_revision_id'=>$id,'revision_status'=>'active'
        ]],201);
    }
}
