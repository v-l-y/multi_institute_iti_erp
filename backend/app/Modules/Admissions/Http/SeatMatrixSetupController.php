<?php
declare(strict_types=1);

namespace App\Modules\Admissions\Http;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Admissions\Application\SeatMatrixSetupService;

final readonly class SeatMatrixSetupController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor,string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute scope required.',403);
    }

    private static function positive(Request $request,string $name,int $max=PHP_INT_MAX): int
    {
        $raw=$request->body[$name]??null;
        if (!is_int($raw)||$raw<1||$raw>$max) {
            throw new HttpException('INVALID_INPUT','Positive integer '.$name.' required.',422);
        }
        return $raw;
    }

    private static function text(Request $request,string $name,int $max): string
    {
        $raw=$request->body[$name]??null;
        if (!is_string($raw)||trim($raw)===''||strlen(trim($raw))>$max) {
            throw new HttpException('INVALID_INPUT',$name.' must be nonempty text.',422);
        }
        return trim($raw);
    }

    private function service(): SeatMatrixSetupService
    {
        return new SeatMatrixSetupService($this->database->connection());
    }

    public function intake(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'batch.manage');
        $calendar=self::positive($request,'calendar_id');
        $year=self::positive($request,'admission_year',9999);
        $code=self::text($request,'intake_code',80);
        $session=self::text($request,'session_part',40);
        if (!in_array($session,['primary','six_month_second'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid official session part.',422);
        }
        $id=$this->service()->intake($actor,$institute,$calendar,$year,$code,$session);
        return new Response(['success'=>true,'data'=>['intake_id'=>$id]],201);
    }

    public function matrixDraft(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'seatmatrix.draft');
        $intake=self::positive($request,'intake_id');
        $source=self::positive($request,'regulatory_source_id');
        $version=self::text($request,'version_code',80);
        $portal=self::text($request,'portal_reference',190);
        $supersedes=$request->body['supersedes_id']??null;
        if ($supersedes!==null && (!is_int($supersedes)||$supersedes<1)) {
            throw new HttpException('INVALID_INPUT','Invalid supersedes_id.',422);
        }
        $id=$this->service()->draft($actor,$institute,$intake,
            $source,$version,$portal,$supersedes);
        return new Response(['success'=>true,'data'=>[
            'matrix_version_id'=>$id,'status'=>'draft',
        ]],201);
    }

    public function matrixEntry(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'seatmatrix.draft');
        $version=self::positive($request,'matrix_version_id');
        $itsu=self::positive($request,'itsu_revision_id');
        $capacity=self::positive($request,'seat_capacity',65535);
        $allowed=$request->body['admission_allowed']??null;
        if (!is_bool($allowed)) {
            throw new HttpException('INVALID_INPUT','admission_allowed must be a JSON boolean.',422);
        }
        $portal=self::text($request,'portal_itsu_reference',190);
        $id=$this->service()->entry($actor,$institute,$version,$itsu,
            $capacity,$allowed,$portal);
        return new Response(['success'=>true,'data'=>[
            'matrix_entry_id'=>$id,'status'=>'draft',
        ]],201);
    }

    public function finalize(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'seatmatrix.publish');
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal authority required.',403);
        }
        $version=self::positive($request,'matrix_version_id');
        $review=self::text($request,'review_reference',500);
        $this->service()->finalize($actor,$institute,$version,$review);
        return new Response(['success'=>true,'data'=>[
            'matrix_version_id'=>$version,'status'=>'final',
            'review_recorded'=>true
        ]]);
    }
}
