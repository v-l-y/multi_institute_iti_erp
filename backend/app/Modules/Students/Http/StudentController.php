<?php
declare(strict_types=1);

namespace App\Modules\Students\Http;

use App\Core\Database;
use App\Modules\Students\Application\StudentRegistrationService;
use App\Modules\Students\Domain\StudentIdentity;
use App\Modules\Students\Infrastructure\StudentRepository;
use App\Modules\Students\Application\StudentQualificationService;
use App\Http\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Modules\Auth\Domain\AuthContext;

/** Locally registered learner identities, not official admissions or verified PRNs. */
final readonly class StudentController
{
    public function __construct(private Database $database) {}

    private static function institute(AuthContext $actor, string $permission): int
    {
        $actor->requirePermission($permission);
        return $actor->instituteId ?? throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
    }

    private static function text(Request $request, string $name, int $max): string
    {
        $value = $request->body[$name] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen(trim($value)) > $max) {
            throw new HttpException('INVALID_INPUT', $name . ' must be non-empty text of at most ' . $max . ' bytes.', 422);
        }
        return trim($value);
    }

    private static function integerQuery(Request $request, string $key, int $default, int $max): int
    {
        if (!array_key_exists($key, $request->query)) {
            return $default;
        }
        $raw = $request->query[$key];
        if (!is_string($raw) && !is_int($raw)) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if ($value === false) {
            throw new HttpException('INVALID_INPUT', 'Invalid ' . $key . '.', 422);
        }
        return $value;
    }

    public function index(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'student.view');
        $pageSize = self::integerQuery($request, 'page_size', 50, 100);
        $cursor = self::integerQuery($request, 'after_id', 0, PHP_INT_MAX);
        $page = (new StudentRepository($this->database->connection()))->page($institute, $cursor, $pageSize);
        return new Response(['success' => true, 'data' => $page]);
    }

    public function own(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'student.view.own');
        if ($actor->studentId === null) {
            throw new HttpException('FORBIDDEN', 'Linked student identity required.', 403);
        }
        $row = (new StudentRepository($this->database->connection()))->own($actor->studentId, $institute);
        return new Response(['success' => true, 'data' => $row]);
    }

    public function createQualification(Request $request, AuthContext $actor): Response
    {
        $institute=self::institute($actor,'student.manage');
        $student=$request->body['student_id']??null;
        $year=$request->body['passing_year']??null;
        if (!is_int($student) || $student<1 || !is_int($year)
            || $year<1000 || $year>(int)gmdate('Y')) {
            throw new HttpException('INVALID_INPUT','Valid student ID and passing year required.',422);
        }
        $level=self::text($request,'level_code',40);
        $board=self::text($request,'board_name',190);
        $certificate=self::text($request,'certificate_reference',190);
        $input=$request->body['subject_results']??null;
        if (!$input instanceof \stdClass) {
            throw new HttpException('INVALID_INPUT','subject_results must be a JSON object.',422);
        }
        $results=(array)$input;
        if (count($results)>150) {
            throw new HttpException('INVALID_INPUT','Too many qualification subjects.',422);
        }
        foreach ($results as $subject=>$mark) {
            if (!is_string($subject) || trim($subject)==='' || strlen($subject)>100
                || (!is_int($mark) && !is_float($mark) && !is_string($mark))
                || !is_numeric($mark) || (float)$mark<0 || !is_finite((float)$mark)) {
                throw new HttpException('INVALID_INPUT','Subject marks must be finite nonnegative numbers.',422);
            }
        }
        $id=(new StudentQualificationService($this->database->connection()))->create(
            $actor,$institute,$student,$level,$board,$year,$certificate,$results
        );
        return new Response(['success'=>true,'data'=>['qualification_id'=>$id]],201);
    }

    public function create(Request $request, AuthContext $actor): Response
    {
        $institute = self::institute($actor, 'student.manage');
        $code = self::text($request, 'local_student_code', 60);
        $name = self::text($request, 'full_name', 190);
        $birth = self::text($request, 'date_of_birth', 10);
        $gender = self::text($request, 'gender', 30);
        $address = self::text($request, 'address', 16000);
        $mode = self::text($request, 'identity_mode', 30);
        $reference = self::text($request, 'identity_evidence_reference', 190);
        if (!StudentIdentity::validIdentityModeAndBirthDate($mode, $birth)) {
            throw new HttpException('INVALID_INPUT', 'Valid date of birth and identity mode required.', 422);
        }
        $id = (new StudentRegistrationService($this->database->connection()))->create($actor, $institute, $code, $name, $birth, $gender, $address, $mode, $reference);
        return new Response(['success' => true, 'data' => ['student_id' => $id]], 201);
    }
}
