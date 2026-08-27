<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Core/Autoloader.php';
App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Core\Router;

$_ENV['APP_URL'] = 'https://rsconnect.rsautomacaodigital.cloud';
$_SERVER['APP_URL'] = $_ENV['APP_URL'];
putenv('APP_URL=' . $_ENV['APP_URL']);

$assertSame = static function (string $expected, string $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nEsperado: {$expected}\nObtido:   {$actual}\n");
        exit(1);
    }
};

$assertSame(
    'https://rsconnect.rsautomacaodigital.cloud/login',
    Router::url('/login'),
    'rota relativa de login'
);

$assertSame(
    'https://rsconnect.rsautomacaodigital.cloud/login?expired=1',
    Router::url('https://rsconnect.rsautomacaodigital.cloud/login?expired=1'),
    'URL absoluta da própria aplicação não pode ser duplicada'
);

$assertSame(
    'https://rsconnect.rsautomacaodigital.cloud/',
    Router::url('https://dominio-malicioso.example/login'),
    'URL externa não pode virar redirecionamento aberto'
);

$reflection = new ReflectionClass(Router::class);
$method = $reflection->getMethod('safeInternalPath');
$method->setAccessible(true);

$assertSame(
    '/billing?tenant_id=5',
    (string) $method->invoke(null, 'https://rsconnect.rsautomacaodigital.cloud/billing?tenant_id=5', '/'),
    'referer absoluto da própria aplicação deve voltar como caminho interno'
);

$assertSame(
    '/login',
    (string) $method->invoke(null, 'https://dominio-malicioso.example/login', '/login'),
    'referer externo deve cair no fallback seguro'
);

$source = file_get_contents(__DIR__ . '/../../app/Core/Router.php') ?: '';
foreach ([
    "\$currentPath === '/login'",
    "safeInternalPath((string) (\$_SERVER['HTTP_REFERER'] ?? ''), '/')",
    "preg_match('~^/https?://~i', \$path)",
] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: proteção ausente no Router: {$needle}\n");
        exit(1);
    }
}

echo "OK - login expirado redireciona com segurança e URLs absolutas não são duplicadas.\n";
