<?php
declare(strict_types=1);

namespace App\Modules\Staff\Http;

use App\Core\Database;
use App\Modules\Staff\Application\StaffService;
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
