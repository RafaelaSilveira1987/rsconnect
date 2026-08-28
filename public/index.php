<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Router;

$root = dirname(__DIR__);
$composerAutoload = $root . '/vendor/autoload.php';
$projectAutoloader = $root . '/app/Core/Autoloader.php';

// Carrega o autoload antes do bootstrap para que até erros de inicialização
// sejam tratados com as classes do projeto disponíveis.
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (!class_exists(Autoloader::class, false)) {
    if (!is_file($projectAutoloader)) {
        http_response_code(500);
        error_log('RS Connect bootstrap error: project autoloader not found.');
        echo 'Ocorreu um erro interno ao iniciar a aplicação.';
        exit;
    }

    require_once $projectAutoloader;
}

Autoloader::register($root . '/app');
require_once $root . '/bootstrap.php';

if (!class_exists(Router::class)) {
    http_response_code(500);
    error_log('RS Connect bootstrap error: App\\Core\\Router could not be loaded.');
    echo 'Ocorreu um erro interno ao iniciar a aplicação.';
    exit;
}

$router = new Router();
$registerRoutes = require $root . '/routes/web.php';
$registerRoutes($router);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
