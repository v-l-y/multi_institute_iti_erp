<?php
declare(strict_types=1);

namespace App\Modules\Auth\Http;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Application\InstituteUserService;
use App\Modules\Auth\Domain\AuthContext;

/** Staff/student accounts belong to verified institute identities, not public signups. */
final readonly class InstituteUserController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor): int
    {
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN','Institute identity required.',403);
    }

    private static function integer(Request $request,string $name): int
    {
        $value=$request->body[$name]??null;
        if (!is_int($value) || $value<1) {
            throw new HttpException('INVALID_INPUT',$name.' must be a positive integer.',422);
        }
        return $value;
    }

    private static function text(Request $request,string $key,int $limit): string
    {
        $value=$request->body[$key]??null;
        if (!is_string($value) || trim($value)==='' || strlen(trim($value))>$limit) {
            throw new HttpException('INVALID_INPUT',$key.' must be nonempty text, maximum '.$limit.' bytes.',422);
        }
        return trim($value);
    }

    private function service(): InstituteUserService
    {
        return new InstituteUserService($this->database->connection());
    }

    public function createStaff(Request $request, AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $role=self::text($request,'role',30);
        if ($role!=='instructor' && $role!=='administrator') {
            throw new HttpException('INVALID_ROLE','Staff provisioning supports only Instructor or Administrator.',422);
        }
        $permission=$role==='administrator'?'auth.user.grant_administrator':'auth.user.manage_staff_accounts';
        $actor->requirePermission($permission);
        if ($role==='administrator' && !in_array('principal',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Principal must grant Administrator identity.',403);
        }
        $staff=self::integer($request,'staff_id');
        $username=self::text($request,'username',120);
        $password=$request->body['temporary_password']??null;
        InstituteUserService::validatePassword($password);
        $id=$this->service()->create($actor,$institute,$role,$staff,$username,$password);
        return new Response(['success'=>true,'data'=>['user_id'=>$id,'role'=>$role,'must_change_password'=>true]],201);
    }

    public function createStudent(Request $request, AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $actor->requirePermission('auth.user.manage_student_accounts');
        $student=self::integer($request,'student_id');
        $username=self::text($request,'username',120);
        $password=$request->body['temporary_password']??null;
        InstituteUserService::validatePassword($password);
        $id=$this->service()->create($actor,$institute,'student',$student,$username,$password);
        return new Response(['success'=>true,'data'=>['user_id'=>$id,'role'=>'student','must_change_password'=>true]],201);
    }

    public function disable(Request $request, AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $actor->requirePermission('auth.user.view');
        $id=self::integer($request,'user_id');
        $this->service()->disable($actor,$institute,$id);
        return new Response(['success'=>true,'data'=>['user_id'=>$id,'status'=>'disabled']]);
    }

    public function revokeRole(Request $request, AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $actor->requirePermission('auth.user.view');
        $id=self::integer($request,'user_id');
        $this->service()->revokeRole($actor,$institute,$id);
        return new Response(['success'=>true,'data'=>['user_id'=>$id,'status'=>'revoked']]);
    }

    public function resetPassword(Request $request, AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $actor->requirePermission('auth.password.reset_user');
        $id=self::integer($request,'user_id');
        $password=$request->body['temporary_password']??null;
        InstituteUserService::validatePassword($password);
        $this->service()->resetPassword($actor,$institute,$id,$password);
        return new Response(['success'=>true,'data'=>['user_id'=>$id,'must_change_password'=>true]]);
    }

    public function index(Request $request, AuthContext $actor): Response
    {
        $institute=self::institute($actor);
        $actor->requirePermission('auth.user.view');
        $size=50;
        $after=0;
        foreach (['page_size'=>100,'after_id'=>PHP_INT_MAX] as $name=>$max) {
            if (!array_key_exists($name,$request->query)) continue;
            $value=$request->query[$name];
            if ((!is_string($value) && !is_int($value)) ||
                filter_var($value,FILTER_VALIDATE_INT,['options'=>[
                    'min_range'=>1,'max_range'=>$max,
                ]])===false) {
                throw new HttpException('INVALID_INPUT','Invalid '.$name.'.',422);
            }
            if ($name==='page_size') $size=(int)$value;
            else $after=(int)$value;
        }
        return new Response(['success'=>true,'data'=>$this->service()->list($actor,$institute,$after,$size)]);
    }
}
