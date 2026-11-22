<?php

declare(strict_types=1);

use App\Core\PublicPath;
use App\Core\Router;

$root = dirname(__DIR__, 2);
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');

$cases = [
    ['/rsconnect', '/', 'mount root'],
    ['/rsconnect/', '/', 'mount root with slash'],
    ['/rsconnect/login', '/login', 'login'],
    ['/rsconnect/login?source=site&tab=1', '/login?source=site&tab=1', 'query preserved'],
    ['/rsconnect/assets/css/app.css?v=1', '/assets/css/app.css?v=1', 'asset'],
    ['/rsconnect/webhooks/evolution', '/webhooks/evolution', 'webhook'],
    ['/rsconnect/api/v1/agents', '/api/v1/agents', 'API'],
    ['/rsconnectivity/login', '/rsconnectivity/login', 'prefix boundary'],
    ['/login', '/login', 'legacy subdomain'],
];
$failed = 0;
$base = 'https://rsdigitallab.com/rsconnect';
foreach ($cases as [$incoming, $expected, $name]) {
    $actual = PublicPath::internalRequestUri($incoming, $base);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL {$name}: {$incoming} => {$actual}, expected {$expected}\n");
        $failed++;
    } else {
        echo "OK: {$name}\n";
    }
}
if (PublicPath::internalRequestUri('/login', 'https://rsconnect.rsdigitallab.com') !== '/login') {
    fwrite(STDERR, "FAIL legacy APP_URL\n");
    $failed++;
} else {
    echo "OK: legacy APP_URL\n";
}
putenv('APP_URL=' . $base);
$_ENV['APP_URL'] = $base;
$_SERVER['APP_URL'] = $base;
if (Router::url('/login') !== $base . '/login') {
    fwrite(STDERR, "FAIL generated login URL\n");
    $failed++;
} else {
    echo "OK: generated login URL\n";
}
if (Router::url('/assets/css/app.css?v=1') !== $base . '/assets/css/app.css?v=1') {
    fwrite(STDERR, "FAIL generated asset URL\n");
    $failed++;
} else {
    echo "OK: generated asset URL\n";
}
exit($failed > 0 ? 1 : 0);
