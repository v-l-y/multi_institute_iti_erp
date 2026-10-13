<?php
declare(strict_types=1);
namespace App\Modules\Auth\Http;

use App\Core\Database;
use App\Modules\Auth\Application\AuthService;
use App\Modules\Auth\Domain\AuthContext;
use App\Modules\Auth\Infrastructure\LoginThrottle;
use App\Http\Request;
use App\Http\Response;

final readonly class AuthController
{
    public function __construct(private Database $database) {}
    private function service(): AuthService
    {
        return new AuthService($this->database->connection());
    }
    public function login(Request $request): Response
    {
        (new LoginThrottle(sys_get_temp_dir() . '/iti-erp-login-throttle'))->check($request->ip);
        return new Response(['success' => true, 'data' => $this->service()->login($request)]);
    }
    public function me(Request $request, AuthContext $context): Response
    {
        return new Response(['success' => true, 'data' => $context->profile()]);
    }
    public function logout(Request $request, AuthContext $context): Response
    {
        $service = $this->service();
        $service->logout($context, $request);
        return new Response(['success' => true, 'data' => ['message' => 'Logged out.']]);
    }
    public function changePassword(Request $request, AuthContext $context): Response
    {
        $service = $this->service();
        $service->changePassword($context, $request);
        return new Response(['success' => true, 'data' => ['message' => 'Password changed. Sign in again.']]);
    }

    public function sessions(Request $request, AuthContext $context): Response
    {
        return new Response(['success' => true, 'data' => $this->service()->sessions($context)]);
    }

    public function logoutAll(Request $request, AuthContext $context): Response
    {
        $this->service()->logoutAll($context, $request);
        return new Response(['success' => true, 'data' => ['message' => 'All sessions revoked. Sign in again.']]);
    }
}
