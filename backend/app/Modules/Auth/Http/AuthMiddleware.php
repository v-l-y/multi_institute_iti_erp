<?php
declare(strict_types=1);
namespace App\Modules\Auth\Http;

use App\Core\Database;
use App\Modules\Auth\Application\AuthService;
use App\Http\HttpException;
use App\Http\Request;

final readonly class AuthMiddleware
{
    public function __construct(private Database $database) {}

    public function wrap(callable $handler, ?string $permission = null, bool $allowPasswordChange = false): \Closure
    {
        return function (Request $request) use ($handler, $permission, $allowPasswordChange) {
            if ($request->token === null) {
                throw new HttpException('UNAUTHORIZED', 'Invalid credentials or session.', 401);
            }
            $context = (new AuthService($this->database->connection()))->authenticate($request);
            if ($context->mustChangePassword && !$allowPasswordChange) {
                throw new HttpException('PASSWORD_CHANGE_REQUIRED', 'Change your password first.', 403);
            }
            if ($permission !== null) {
                $context->requirePermission($permission);
            }
            return $handler($request, $context);
        };
    }
}
