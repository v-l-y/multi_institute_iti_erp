<?php
declare(strict_types=1);

namespace App\Modules\Academic\Http;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Academic\Application\CalendarPlanningService;

final readonly class CalendarPlanningController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor): int
    {
        $actor->requirePermission('calendar.manage');
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute identity required.',403);
    }

    private static function id(Request $request,string $key,int $max=PHP_INT_MAX): int
    {
        $value=$request->body[$key]??null;
        if (!is_int($value)||$value<1||$value>$max) {
            throw new HttpException('INVALID_INPUT','Positive integer '.$key.' required.',422);
        }
        return $value;
    }

    private static function text(Request $request,string $key,int $max): string
    {
        $raw=$request->body[$key]??null;
        if (!is_string($raw)||trim($raw)===''||strlen(trim($raw))>$max) {
            throw new HttpException('INVALID_INPUT',$key.' must be nonempty text.',422);
        }
        return trim($raw);
    }

    private static function date(Request $request,string $key): string
    {
        $value=self::text($request,$key,10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$value)
            || $date===false || $date->format('Y-m-d')!==$value) {
            throw new HttpException('INVALID_INPUT','Real '.$key.' date required.',422);
        }
        return $value;
    }

    public function entry(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $year=self::id($request,'batch_year_id');
        $date=self::date($request,'session_date');
        $component=self::text($request,'component',40);
        if (!in_array($component,[
            'trade_practical','trade_theory','employability','ojt_project'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid CTS syllabus component.',422);
        }
        $minutes=self::id($request,'scheduled_minutes',1440);
        (new CalendarPlanningService($this->database->connection()))
            ->entry($actor,$institute,$year,$date,$component,$minutes);
        return new Response(['success'=>true,'data'=>[
            'batch_year_id'=>$year,'session_date'=>$date,'component'=>$component
        ]],201);
    }

    public function review(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal authority required.',403);
        }
        $year=self::id($request,'batch_year_id');
        $date=self::date($request,'reviewed_through');
        $ref=self::text($request,'review_evidence_reference',500);
        (new CalendarPlanningService($this->database->connection()))
            ->review($actor,$institute,$year,$date,$ref);
        return new Response(['success'=>true,'data'=>[
            'batch_year_id'=>$year,'status'=>'reviewed','reviewed_through'=>$date
        ]]);
    }
}
