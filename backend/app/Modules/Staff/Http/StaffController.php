<?php
declare(strict_types=1);

namespace App\Modules\Staff\Http;

use App\Core\Database;
use App\Modules\Staff\Application\StaffService;
use App\Modules\Staff\Application\StaffLifecycleService;
use App\Modules\Staff\Domain\StaffRecord;
use App\Modules\Staff\Infrastructure\StaffRepository;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/** Institute-scoped staff registry. Teaching and non-teaching share one master. */
final readonly class StaffController
{
    public function __construct(private Database $database) {}

    private static function requiredText(Request $request, string $field, int $max): string
    {
        $value = $request->body[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new HttpException('INVALID_INPUT', $field . ' must be non-empty text of at most ' . $max . ' bytes.', 422);
        }
        return trim($value);
    }

    private static function queryNumber(Request $request, string $field, int $default, int $max): int
    {
        if (!array_key_exists($field, $request->query)) {
            return $default;
        }
        $value = $request->query[$field];
        if (!is_string($value) && !is_int($value)) {
            throw new HttpException('INVALID_INPUT', $field . ' must be a positive integer.', 422);
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if ($number === false) {
            throw new HttpException('INVALID_INPUT', $field . ' must be a positive integer.', 422);
        }
        return $number;
    }

    private static function institute(AuthContext $actor, string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId
            ?? throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
    }

    public function index(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'staff.view');
        $pageSize = self::queryNumber($request, 'page_size', 50, 100);
        $afterId = self::queryNumber($request, 'after_id', 0, PHP_INT_MAX);
        $result = (new StaffRepository($this->database->connection()))->page($institute, $afterId, $pageSize);
        return new Response(['success' => true, 'data' => $result]);
    }

    private static function requestId(Request $request,string $key): int
    {
        $value=$request->body[$key]??null;
        if (!is_int($value) || $value<1) throw new HttpException('INVALID_INPUT',$key.' must be a positive integer.',422);
        return $value;
    }

    public function updateProfile(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'staff.manage');
        $id=self::requestId($request,'staff_id');
        $name=self::requiredText($request,'full_name',190);
        $designation=self::requiredText($request,'designation',120);
        (new StaffLifecycleService($this->database->connection()))->update($actor,$institute,$id,$name,$designation);
        return new Response(['success'=>true,'data'=>['staff_id'=>$id]]);
    }

    public function recordQualification(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'staff.manage');
        $id=self::requestId($request,'staff_id');
        $type=self::requiredText($request,'qualification_type',60);
        $specialization=self::requiredText($request,'specialization',190);
        $reference=self::requiredText($request,'certificate_reference',190);
        $months=$request->body['experience_months']??null;
        if (!is_int($months) || $months<0 || $months>65535) throw new HttpException('INVALID_INPUT','Valid experience_months required.',422);
        $created=(new StaffLifecycleService($this->database->connection()))->qualification(
            $actor,$institute,$id,$type,$specialization,$reference,$months);
        return new Response(['success'=>true,'data'=>['qualification_id'=>$created,'verified'=>false]],201);
    }

    public function exitEmployment(Request $request,AuthContext $actor): Response
    {
        $institute=self::institute($actor,'staff.manage');
        $id=self::requestId($request,'staff_id');
        $date=self::requiredText($request,'left_on',10);
        $parsed=\DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$date) ||
            $parsed===false || $parsed->format('Y-m-d')!==$date ||
            $date>(new \DateTimeImmutable('now',new \DateTimeZone('Asia/Kolkata')))->format('Y-m-d')) {
            throw new HttpException('INVALID_INPUT','Real non-future left_on date required.',422);
        }
        $reason=self::requiredText($request,'reference',190);
        (new StaffLifecycleService($this->database->connection()))->exit($actor,$institute,$id,$date,$reason);
        return new Response(['success'=>true,'data'=>['staff_id'=>$id,'left_on'=>$date]]);
    }

    public function create(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'staff.manage');
        $code = self::requiredText($request, 'employee_code', 50);
        $name = self::requiredText($request, 'full_name', 190);
        $category = self::requiredText($request, 'staff_category', 30);
        $designation = self::requiredText($request, 'designation', 120);
        $joinedOn = self::requiredText($request, 'joined_on', 10);
        if (!StaffRecord::validCategoryAndJoiningDate($category, $joinedOn)) {
            throw new HttpException('INVALID_INPUT', 'Valid staff category and joining date are required.', 422);
        }
        $id = (new StaffService($this->database->connection()))->create($actor, $institute, $code, $name, $category, $designation, $joinedOn);
        // No login account, instructor verification or principal appointment is auto-created.
        return new Response(['success' => true, 'data' => ['staff_id' => $id]], 201);
    }
}
