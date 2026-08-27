<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$routes = $read('routes/web.php');
$controller = $read('app/Controllers/InstanceController.php');
$view = $read('app/Views/instances/index.php');
$version = $read('app/Services/AppVersionService.php');
$instructions = $read('INSTRUCOES-v36.16.1.md');

$managedRoutes = [
    "'/instances', [InstanceController::class, 'store'], ['auth', 'permission:instances.manage', 'csrf']",
    "'/instances/test', [InstanceController::class, 'sendTest'], ['auth', 'permission:instances.manage', 'csrf']",
    "'/instances/update', [InstanceController::class, 'update'], ['auth', 'permission:instances.manage', 'csrf']",
    "'/instances/settings', [InstanceController::class, 'saveSettings'], ['auth', 'permission:instances.manage', 'csrf']",
    "'/instances/action', [InstanceController::class, 'remoteAction'], ['auth', 'permission:instances.manage', 'csrf']",
    "'/instances/delete', [InstanceController::class, 'delete'], ['auth', 'permission:instances.manage', 'csrf']",
];

$checks = [
    'rotas de gestão disponíveis ao administrador do cliente' => array_reduce(
        $managedRoutes,
        static fn (bool $ok, string $needle): bool => $ok && str_contains($routes, $needle),
        true
    ),
    'tela completa depende de instances.manage e não de super admin' => str_contains($view, 'if (!$canManage)')
        && !str_contains($view, 'if (!$isSuperAdmin) {' . "\n" . "    require __DIR__ . '/_client.php';"),
    'cliente cria conexão com credenciais protegidas' => str_contains($view, 'Conexão independente')
        && str_contains($view, 'As chaves de acesso permanecem protegidas')
        && str_contains($view, 'type="hidden" name="api_key"'),
    'backend força credenciais do ambiente para cliente' => str_contains($controller, '$baseUrl = $isSuperAdmin')
        && str_contains($controller, "Env::get('EVOLUTION_DEFAULT_API_KEY'")
        && str_contains($controller, ": 'managed';"),
    'isolamento por tenant centralizado' => str_contains($controller, "AND tenant_id = :tenant_id")
        && str_contains($controller, 'private function findInstance'),
    'cliente não altera cadastro técnico' => str_contains($controller, 'if (!Auth::isSuperAdmin())')
        && str_contains($controller, '$instanceName = (string) $source[\'instance_name\'];')
        && str_contains($controller, '$baseUrl = (string) $source[\'base_url\'];'),
    'super admin continua com recursos técnicos' => str_contains($view, '<?php if ($isSuperAdmin): ?>')
        && str_contains($view, 'Criar ou ligar uma conexão existente')
        && str_contains($view, 'Acesso técnico protegido'),
    'pacote e documentação atualizados' => preg_match('/RS Connect 36\.(16\.[1-3]|17\.0)/', $version) === 1
        && str_contains($instructions, 'Administrador do cliente')
        && str_contains($instructions, 'instances.manage'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - administrador do cliente gerencia a Evolution com isolamento por empresa.\n";
