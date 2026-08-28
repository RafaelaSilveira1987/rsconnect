<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');

use App\Controllers\HealthController;

$failures = [];
$passes = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        $passes++;
        return;
    }

    echo "[FAIL] {$label}\n";
    $failures[] = $label;
};

$routes = (string) file_get_contents($root . '/routes/web.php');
$controller = (string) file_get_contents($root . '/app/Controllers/HealthController.php');
$service = (string) file_get_contents($root . '/app/Services/HealthCheckService.php');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');
$compose = (string) file_get_contents($root . '/docker-compose.yml');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

ob_start();
(new HealthController())->live();
$liveBody = (string) ob_get_clean();

$check($liveBody === '{"status":"ok"}', 'liveness público retorna somente status ok');
$check(str_contains($routes, "get('/health/live'") && str_contains($routes, "get('/health/ready'"), 'rotas de liveness e readiness foram separadas');
$check(str_contains($routes, "get('/health/ready/details'") && str_contains($routes, "['auth', 'super_admin']"), 'readiness detalhado exige autenticação de super admin');
$check(str_contains($controller, '[\'status\' => $ready ? \'ok\' : \'unavailable\']'), 'readiness público expõe somente o estado resumido');
$check(!str_contains($controller, 'DB_HOST') && !str_contains($controller, 'DB_DATABASE') && !str_contains($controller, 'DB_USERNAME'), 'controller público não expõe configuração do banco');
$check(str_contains($service, 'catch (Throwable)') && !str_contains($service, 'getMessage()'), 'falhas internas são ocultadas sem mensagem de exceção');
$check(str_contains($service, "'name' => 'database'") && str_contains($service, "'name' => 'storage'") && str_contains($service, "'name' => 'application_key'"), 'diagnóstico autenticado usa nomes genéricos dos componentes');
$check(str_contains($bootstrap, "['/health/live', '/health/ready']") && str_contains($bootstrap, '$sessionlessHealthRequest'), 'health checks públicos não criam sessão nem cookie');
$check(str_contains($controller, "header_remove('X-Powered-By')") && str_contains($controller, 'Cache-Control: no-store'), 'respostas removem identificação PHP e desativam cache');
$check(str_contains($compose, 'http://127.0.0.1/health/live'), 'Docker usa o endpoint de liveness sem dependências externas');
$check(str_contains($version, 'RS Connect 36.20.14') && str_contains($version, 'health checks seguros'), 'versão interna foi atualizada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo ENT-029: {$passes} verificações aprovadas.\n";
