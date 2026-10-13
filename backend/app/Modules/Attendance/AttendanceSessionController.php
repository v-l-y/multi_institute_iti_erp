<?php
declare(strict_types=1);

namespace App\Modules\Attendance;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/** Explicit, source-backed daily attendance setup and Principal locking. */
final readonly class AttendanceSessionController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor,string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute scope required.',403);
    }

    private static function id(Request $request,string $key): int
    {
        $value=$request->body[$key]??null;
        if (!is_int($value) || $value<1) {
            throw new HttpException('INVALID_INPUT',$key.' must be a positive integer.',422);
        }
        return $value;
    }

    /** Scoped session register for institute management and mapped instructors. */
    public function index(Request $request,AuthContext $actor): Response
    {
        $institute=$actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute identity required.',403);
        $canAll=in_array('attendance.view',$actor->permissions,true);
        $canAssigned=in_array('attendance.view.assigned',$actor->permissions,true)
            && $actor->staffId!==null;
        if (!$canAll && !$canAssigned) {
            throw new HttpException('FORBIDDEN','Attendance register permission required.',403);
        }
        $after=0;$size=50;
        foreach (['after_id'=>PHP_INT_MAX,'page_size'=>100] as $key=>$max) {
            if(!array_key_exists($key,$request->query))continue;
            $raw=$request->query[$key];
            $min=$key==='page_size'?1:0;
            $n=(is_int($raw)||is_string($raw))
                ?filter_var($raw,FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>$max]])
                :false;
            if($n===false)throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
            if($key==='after_id')$after=(int)$n;else $size=(int)$n;
        }
        $sql='SELECT id,batch_id,batch_year_id,session_date,component,
                instructor_id,scheduled_minutes,locked_on
              FROM attendance_sessions WHERE institute_id=? AND id>?';
        $args=[$institute,$after];
        if(!$canAll){$sql.=' AND instructor_id=?';$args[]=$actor->staffId;}
        $sql.=' ORDER BY id ASC LIMIT ?';
        $args[]=$size;
        $q=$this->database->connection()->prepare($sql);
        foreach($args as $i=>$value)$q->bindValue($i+1,$value,\PDO::PARAM_INT);
        $q->execute();$rows=$q->fetchAll(\PDO::FETCH_ASSOC);
        return new Response(['success'=>true,'data'=>[
            'sessions'=>$rows,
            'next_after_id'=>count($rows)===$size?(int)$rows[count($rows)-1]['id']:null
        ]]);
    }

    public function create(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'calendar.manage');
        $year=self::id($request,'batch_year_id');
        $staff=self::id($request,'instructor_id');
        $date=$request->body['session_date']??null;
        if (!is_string($date) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$date)) {
            throw new HttpException('INVALID_INPUT','A real YYYY-MM-DD session date is required.',422);
        }
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if ($parsed===false || $parsed->format('Y-m-d')!==$date) {
            throw new HttpException('INVALID_INPUT','Invalid session date.',422);
        }
        $component=$request->body['component']??null;
        if (!is_string($component) || !in_array($component,[
            'trade_practical','trade_theory','employability','ojt_project'],true)) {
            throw new HttpException('INVALID_INPUT','Invalid CTS session component.',422);
        }
        $id=(new AttendanceSessionService($this->database->connection()))->create(
            $actor,$institute,$year,$date,$component,$staff);
        return new Response(['success'=>true,'data'=>['session_id'=>$id]],201);
    }

    public function lock(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'attendance.correct');
        if (!in_array('principal',$actor->roles,true)) {
            throw new HttpException('PRINCIPAL_AUTHORITY_REQUIRED','Principal authorization required.',403);
        }
        $id=self::id($request,'session_id');
        (new AttendanceSessionService($this->database->connection()))->lock($actor,$institute,$id);
        return new Response(['success'=>true,'data'=>['session_id'=>$id,'locked'=>true]]);
    }
}
