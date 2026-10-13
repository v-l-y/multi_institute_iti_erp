<?php
declare(strict_types=1);

namespace App\Http;

final class Router
{
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->routes[$path]['GET'] = $handler;
    }

    public function post(string $path, callable $handler): void
    {
        $this->routes[$path]['POST'] = $handler;
    }

    public function dispatch(string $method, string $path, ?Request $request = null): Response
    {
        if (!isset($this->routes[$path])) {
            return Response::error('NOT_FOUND', 'Route not found.', 404);
        }
        if (!isset($this->routes[$path][$method])) {
            return new Response(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'Method not allowed.']], 405, ['Allow' => implode(', ', array_keys($this->routes[$path]))]);
        }
        return ($this->routes[$path][$method])($request ?? new Request());
    }
}
