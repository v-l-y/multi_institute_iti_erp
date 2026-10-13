<?php
declare(strict_types=1);

use App\Http\Router;
use App\Modules\Health\HealthController;
use App\Modules\Auth\AuthController;
use App\Core\Database;
use App\Modules\Auth\AuthMiddleware;
use App\Modules\Auth\AuthContext;
use App\Modules\Institutes\InstituteRepository;
use App\Http\Request;
use App\Http\Response;

return static function (Router $router, Database $database): void {
    $router->get('/api/v1/health', new HealthController());
    $auth = new AuthController($database);
    $guard = new AuthMiddleware($database);
    $router->post('/api/v1/auth/login', [$auth, 'login']);
    $router->get('/api/v1/auth/me', $guard->wrap([$auth, 'me'], allowPasswordChange: true));
    $router->post('/api/v1/auth/logout', $guard->wrap([$auth, 'logout'], allowPasswordChange: true));
    $router->post('/api/v1/auth/change-password', $guard->wrap([$auth, 'changePassword'], allowPasswordChange: true));
    $router->get('/api/v1/auth/sessions', $guard->wrap([$auth, 'sessions']));
    $router->post('/api/v1/auth/logout-all', $guard->wrap([$auth, 'logoutAll']));
    $router->get('/api/v1/institute/profile', $guard->wrap(
        static fn(Request $request, AuthContext $context) => new Response(['success' => true, 'data' => (new InstituteRepository($database->connection()))->profile($context)]),
        'institute.profile.view',
    ));
    $router->get('/api/v1/ready', static function () use ($database): Response {
        try {
            $database->connection()->query('SELECT 1');
            return new Response(['success' => true, 'data' => ['status' => 'ready']]);
        } catch (\Throwable) {
            return Response::error('NOT_READY', 'Database unavailable.', 503);
        }
    });
};
