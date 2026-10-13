<?php
declare(strict_types=1);

use App\Core\Environment;
use App\Core\Database;
use App\Http\Router;

require_once __DIR__ . '/autoload.php';
Environment::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Environment::get('APP_TIMEZONE', 'Asia/Kolkata'));
$router = new Router();
$register = require dirname(__DIR__) . '/routes/api.php';
$register($router, new Database(require dirname(__DIR__) . '/config/database.php'));
return $router;
