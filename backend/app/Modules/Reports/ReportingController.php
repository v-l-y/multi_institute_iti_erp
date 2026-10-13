<?php
declare(strict_types=1);

namespace App\Modules\Reports;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use PDO;

/** Tenant-scoped operational audit and source-record count summaries. */
final readonly class ReportingController
{
    public function __construct(private Database $database) {}

    private static function number(Request $request,string $key,int $default,int $max): int
    {
        if (!array_key_exists($key,$request->query)) return $default;
        $v=$request->query[$key];
        if ((!is_string($v)&&!is_int($v))
            || filter_var($v,FILTER_VALIDATE_INT,['options'=>[
                'min_range'=>1,'max_range'=>$max]])===false) {
            throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
        }
        return (int)$v;
    }

    private static function institute(AuthContext $actor): int
    {
        $actor->requirePermission('audit.view');
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute identity required.',403);
    }

    private static function selectEvents(PDO $db,string $sql,array $parameters,int $size): array
    {
        $statement=$db->prepare($sql.' ORDER BY id LIMIT ?');
        $i=1;
        foreach ($parameters as $value) {
            $statement->bindValue($i++,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
        }
        $statement->bindValue($i,$size,PDO::PARAM_INT);
        $statement->execute();
        $rows=$statement->fetchAll(PDO::FETCH_ASSOC);
        return ['events'=>$rows,'next_after_id'=>count($rows)===$size?
            (int)$rows[count($rows)-1]['id']:null];
    }

    public function instituteAudit(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $after=self::number($request,'after_id',0,PHP_INT_MAX);
        $size=self::number($request,'page_size',50,100);
        $sql='SELECT id,actor_user_id,action_code,entity_table,entity_id,occurred_at
            FROM institute_activity_log WHERE institute_id=? AND id>?';
        $args=[$institute,$after];
        $action=$request->query['action_code']??null;
        if ($action!==null) {
            if (!is_string($action)||!preg_match('/^[a-z0-9._-]{1,80}$/D',$action)) {
                throw new HttpException('INVALID_INPUT','Invalid action_code filter.',422);
            }
            $sql.=' AND action_code=?';
            $args[]=$action;
        }
        $user=$request->query['actor_user_id']??null;
        if ($user!==null) {
            $actorId=self::number($request,'actor_user_id',0,PHP_INT_MAX);
            $sql.=' AND actor_user_id=?';
            $args[]=$actorId;
        }
        return new Response(['success'=>true,'data'=>self::selectEvents(
            $this->database->connection(),$sql,$args,$size)]);
    }

    public function platformAudit(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('platform.audit.view');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform audit access required.',403);
        }
        $after=self::number($request,'after_id',0,PHP_INT_MAX);
        $size=self::number($request,'page_size',50,100);
        $sql='SELECT id,user_id,event_type,occurred_at
            FROM auth_audit_log WHERE institute_id IS NULL AND id>?';
        // Events are metadata-only; no password, token or arbitrary JSON payload.
        return new Response(['success'=>true,'data'=>self::selectEvents(
            $this->database->connection(),$sql,[$after],$size)]);
    }

    public function summary(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $db=$this->database->connection();
        $definitions=[
            'staff_active'=>"SELECT COUNT(*) FROM staff WHERE institute_id=? AND left_on IS NULL",
            'students_registered'=>"SELECT COUNT(*) FROM students WHERE institute_id=?",
            'batches_planned'=>"SELECT COUNT(*) FROM batches WHERE institute_id=? AND status='planned'",
            'batches_active'=>"SELECT COUNT(*) FROM batches WHERE institute_id=? AND status='active'",
            'enrollments_active'=>"SELECT COUNT(*) FROM enrollments WHERE institute_id=? AND status='active'",
            'admissions_pending'=>"SELECT COUNT(*) FROM enrollments WHERE institute_id=? AND status='pending_verification'",
            'attendance_sessions_locked'=>"SELECT COUNT(*) FROM attendance_sessions WHERE institute_id=? AND locked_on IS NOT NULL",
            'seat_matrix_final_versions'=>"SELECT COUNT(*) FROM seat_matrix_versions WHERE institute_id=? AND status='final'"
        ];
        $summary=[];
        foreach ($definitions as $key=>$sql) {
            $q=$db->prepare($sql);
            $q->execute([$institute]);
            $summary[$key]=(int)$q->fetchColumn();
        }
        return new Response(['success'=>true,'data'=>[
            'institute_id'=>$institute,'counts'=>$summary,
            'basis'=>'current_institute_database_records'
        ]]);
    }
}
