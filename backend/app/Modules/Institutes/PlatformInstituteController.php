<?php
declare(strict_types=1);

namespace App\Modules\Institutes;

use App\Core\Database;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Application\InstituteUserService;

/** Developer-only registration; never approves regulatory affiliation. */
final readonly class PlatformInstituteController
{
    public function __construct(private Database $database) {}

    private static function text(Request $request,string $field,int $max): string
    {
        $value=$request->body[$field]??null;
        if (!is_string($value) || trim($value)==='' || strlen(trim($value))>$max) {
            throw new HttpException('INVALID_INPUT',$field.' must be non-empty text of at most '.$max.' bytes.',422);
        }
        return trim($value);
    }

    private static function optionalText(Request $request,string $field,int $max): ?string
    {
        $value=$request->body[$field]??null;
        if ($value===null) return null;
        if (!is_string($value)||strlen(trim($value))>$max) {
            throw new HttpException('INVALID_INPUT','Invalid '.$field.'.',422);
        }
        return trim($value)===''?null:trim($value);
    }

    private static function id(Request $request,string $name): int
    {
        $raw=$request->body[$name]??null;
        if (!is_int($raw) || $raw<1) {
            throw new HttpException('INVALID_INPUT',$name.' must be a positive integer.',422);
        }
        return $raw;
    }

    private static function date(Request $request,string $name): string
    {
        $raw=self::text($request,$name,10);
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$raw);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$raw)
            || $date===false || $date->format('Y-m-d')!==$raw
            || $raw>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) {
            throw new HttpException('INVALID_INPUT','Real non-future '.$name.' required.',422);
        }
        return $raw;
    }

    private function service(): PlatformInstituteService
    {
        return new PlatformInstituteService($this->database->connection());
    }

    public function create(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('platform.institute.create');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
        $name=self::text($request,'name',190);
        $mis=self::text($request,'mis_code',40);
        $address=self::text($request,'address',16000);
        $district=self::optionalText($request,'district_name',120);
        $management=self::optionalText($request,'management_type',80);
        $id=$this->service()->create($actor,$name,$mis,$address,$district,$management);
        return new Response(['success'=>true,'data'=>[
            'institute_id'=>$id,'affiliation_verified'=>false
        ]],201);
    }

    public function provisionPrincipal(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('platform.user.provision_principal');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
        $institute=self::id($request,'institute_id');
        $employee=self::text($request,'employee_code',50);
        $name=self::text($request,'full_name',190);
        $designation=self::text($request,'designation',120);
        $joined=self::date($request,'joined_on');
        $appointment=self::text($request,'appointment_reference',190);
        $username=self::text($request,'username',120);
        $password=$request->body['temporary_password']??null;
        InstituteUserService::validatePassword($password);
        $result=$this->service()->principal($actor,$institute,$employee,
            $name,$designation,$joined,$appointment,$username,$password);
        return new Response(['success'=>true,'data'=>$result],201);
    }

    public function index(Request $request,AuthContext $actor): Response
    {
        $actor->requirePermission('platform.institute.view');
        if ($actor->instituteId!==null || !in_array('developer',$actor->roles,true)) {
            throw new HttpException('FORBIDDEN','Platform Developer required.',403);
        }
        $after=0;$size=50;
        foreach (['after_id'=>PHP_INT_MAX,'page_size'=>100] as $key=>$max) {
            if (!array_key_exists($key,$request->query)) continue;
            $input=$request->query[$key];
            if ((!is_string($input) && !is_int($input))
                || filter_var($input,FILTER_VALIDATE_INT,
                    ['options'=>['min_range'=>1,'max_range'=>$max]])===false) {
                throw new HttpException('INVALID_INPUT','Invalid '.$key.'.',422);
            }
            if ($key==='after_id') $after=(int)$input;
            else $size=(int)$input;
        }
        return new Response(['success'=>true,'data'=>$this->service()->list($actor,$after,$size)]);
    }
}
