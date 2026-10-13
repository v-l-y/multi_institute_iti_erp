<?php
declare(strict_types=1);

use App\Http\Request;
use App\Http\Kernel;

ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/bootstrap/autoload.php';

$response = (new Kernel())->handle(static function () {
    if (PHP_VERSION_ID < 80500 || PHP_VERSION_ID >= 80600) {
        throw new RuntimeException('This backend requires PHP 8.5.x.');
    }
    $router = require dirname(__DIR__) . '/bootstrap/app.php';
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return $router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', is_string($path) ? $path : '/', Request::capture());
});
$response->send();
