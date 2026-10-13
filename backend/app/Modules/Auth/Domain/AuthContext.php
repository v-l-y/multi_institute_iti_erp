<?php
declare(strict_types=1);
namespace App\Modules\Auth\Domain;

use App\Http\HttpException;

final readonly class AuthContext
{
    public function __construct(public int $userId, public ?int $instituteId, public int $sessionId, public string $username, public bool $mustChangePassword, public array $roles, public array $permissions, public ?int $staffId, public ?int $studentId) {}

    public function requirePermission(string $permission): void
    {
        if ($this->mustChangePassword) {
            throw new HttpException('PASSWORD_CHANGE_REQUIRED', 'Change your password first.', 403);
        }
        if (!in_array($permission, $this->permissions, true)) {
            throw new HttpException('FORBIDDEN', 'Permission denied.', 403);
        }
    }

    public function requireInstitute(int $instituteId): void
    {
        if ($this->instituteId !== $instituteId) {
            throw new HttpException('FORBIDDEN', 'Institute access denied.', 403);
        }
    }

    public function profile(): array
    {
        return ['id' => $this->userId, 'username' => $this->username, 'institute_id' => $this->instituteId, 'roles' => $this->roles, 'permissions' => $this->permissions, 'staff_id' => $this->staffId, 'student_id' => $this->studentId, 'must_change_password' => $this->mustChangePassword];
    }
}
