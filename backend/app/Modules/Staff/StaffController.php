<?php
declare(strict_types=1);

namespace App\Modules\Staff;

use App\Core\Database;
use App\Core\InstituteTransaction;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\AuthContext;
use DateTimeImmutable;
use PDO;

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
        $statement = $this->database->connection()->prepare(
            'SELECT id,employee_code,full_name,staff_category,designation,principal_assignment,joined_on,left_on
             FROM staff WHERE institute_id=? AND id>? ORDER BY id ASC LIMIT ?'
        );
        $statement->bindValue(1, $institute, PDO::PARAM_INT);
        $statement->bindValue(2, $afterId, PDO::PARAM_INT);
        $statement->bindValue(3, $pageSize, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
        $next = count($rows) === $pageSize ? (int) $rows[count($rows) - 1]['id'] : null;
        return new Response(['success' => true, 'data' => ['staff' => $rows, 'next_after_id' => $next]]);
    }

    public function create(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'staff.manage');
        $code = self::requiredText($request, 'employee_code', 50);
        $name = self::requiredText($request, 'full_name', 190);
        $category = self::requiredText($request, 'staff_category', 30);
        $designation = self::requiredText($request, 'designation', 120);
        $joinedOn = self::requiredText($request, 'joined_on', 10);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $joinedOn);
        if (!in_array($category, ['teaching', 'non_teaching'], true)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $joinedOn)
            || $date === false || $date->format('Y-m-d') !== $joinedOn) {
            throw new HttpException('INVALID_INPUT', 'Valid staff category and joining date are required.', 422);
        }
        $db = $this->database->connection();
        $transaction = new InstituteTransaction($db);
        $id = $transaction->run($actor, $institute, 'staff.manage', function () use ($db, $institute, $actor, $code, $name, $category, $designation, $joinedOn, $transaction): int {
            $existing = $db->prepare('SELECT id FROM staff WHERE institute_id=? AND employee_code=? LIMIT 1 FOR UPDATE');
            $existing->execute([$institute, $code]);
            if ($existing->fetchColumn() !== false) {
                throw new HttpException('STAFF_CODE_EXISTS', 'Employee code already exists in this institute.', 409);
            }
            $insert = $db->prepare(
                'INSERT INTO staff (institute_id,employee_code,full_name,staff_category,designation,joined_on)
                 VALUES (?,?,?,?,?,?)'
            );
            $insert->execute([$institute, $code, $name, $category, $designation, $joinedOn]);
            $id = (int) $db->lastInsertId();
            $transaction->audit($actor, $institute, 'staff.create', 'staff', $id, ['employee_code' => $code, 'staff_category' => $category]);
            return $id;
        });
        // No login account, instructor verification or principal appointment is auto-created.
        return new Response(['success' => true, 'data' => ['staff_id' => $id]], 201);
    }
}
