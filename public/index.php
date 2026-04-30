<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel   = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request  = Request::capture();
$response = $kernel->handle($request)->send();

// Envia a resposta HTTP ao cliente antes de rodar callbacks de terminação
// (permite que tarefas longas rodem após a resposta ser entregue no PHP-FPM)
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}

$kernel->terminate($request, $response);
