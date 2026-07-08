<?php

declare(strict_types=1);

use App\Core\Router;

require_once dirname(__DIR__) . '/bootstrap.php';

$router = new Router();
$registerRoutes = require dirname(__DIR__) . '/routes/web.php';
$registerRoutes($router);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
