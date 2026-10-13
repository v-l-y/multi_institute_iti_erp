<?php
declare(strict_types=1);

namespace App\Modules\Admissions\Http;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Admissions\Application\IntakeRevisionService;

/** Explicit institute draft + Principal review for admission-intake evidence. */
final readonly class IntakeRevisionController
{
    public function __construct(private Database $database) {}

    private static function id(Request $r,string $key): int
    {
        $v=$r->body[$key]??null;
        if (!is_int($v)||$v<1) throw new HttpException('INVALID_INPUT','Positive integer '.$key.' required.',422);
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
        $d=\DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D',$v)
            || !$d || $d->format('Y-m-d')!==$v) {
            throw new HttpException('INVALID_INPUT','Real date required for '.$key.'.',422);
        }
        return $v;
    }

    private static function institute(AuthContext $actor): int
    {
        $actor->requirePermission('admission.intake.manage');
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute scope required.',403);
    }

    public function draft(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $intake=self::id($request,'intake_id');
        $source=self::id($request,'regulatory_source_id');
        $code=self::text($request,'revision_code',80);
        $from=self::date($request,'opens_on');
        $to=self::date($request,'closes_on');
        if ($to<$from) throw new HttpException('INVALID_INPUT','Closing date precedes opening date.',422);
        $approval=self::text($request,'approval_reference',190);
        $supersedes=$request->body['supersedes_id']??null;
        if ($supersedes!==null && (!is_int($supersedes)||$supersedes<1)) {
            throw new HttpException('INVALID_INPUT','Invalid supersedes_id.',422);
        }
        $id=(new IntakeRevisionService($this->database->connection()))->draft(
            $actor,$institute,$intake,$source,$code,$from,$to,$approval,$supersedes);
        return new Response(['success'=>true,'data'=>[
            'intake_revision_id'=>$id,'status'=>'draft'
        ]],201);
    }

    public function publish(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal review required.',403);
        }
        $id=self::id($request,'intake_revision_id');
        $reference=self::text($request,'review_reference',500);
        (new IntakeRevisionService($this->database->connection()))
            ->publish($actor,$institute,$id,$reference);
        return new Response(['success'=>true,'data'=>[
            'intake_revision_id'=>$id,'status'=>'published',
            'basis'=>'institute_reviewed_official_source'
        ]]);
    }
}
