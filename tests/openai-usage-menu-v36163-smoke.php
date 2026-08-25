<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$routes = $read('routes/web.php');
$layout = $read('app/Views/layouts/app.php');
$controller = $read('app/Controllers/OpenAiUsageController.php');
$view = $read('app/Views/openai_usage/index.php');
$credentialsView = $read('app/Views/ai_credentials/index.php');
$version = $read('app/Services/AppVersionService.php');
$instructions = $read('INSTRUCOES-v36.16.3.md');

$checks = [
    'controller importado' => str_contains($routes, 'use App\\Controllers\\OpenAiUsageController;'),
    'rota dedicada protegida' => str_contains($routes, "\$router->get('/openai-usage', [OpenAiUsageController::class, 'index'], ['auth', 'super_admin']);"),
    'menu do super admin' => str_contains($layout, '<span>Consumo OpenAI</span>') && str_contains($layout, "Router::url('/openai-usage')"),
    'cache bust atual' => preg_match('/app\.css\?v=36\.(16\.3|17\.0|17\.1|17\.2|18\.[0123456]|19\.0)/', $layout) === 1 && preg_match('/app\.js\?v=36\.(16\.3|17\.0|17\.1|17\.2|18\.[0123456]|19\.0)/', $layout) === 1,
    'controller consulta serviço' => str_contains($controller, 'OpenAiOrganizationUsageService') && str_contains($controller, "View::render('openai_usage.index'"),
    'view dedicada completa' => str_contains($view, 'Consumo diário da OpenAI') && str_contains($view, 'OPENAI_ADMIN_API_KEY') && str_contains($view, 'Atualizar agora'),
    'atalho em credenciais' => str_contains($credentialsView, 'Abrir consumo OpenAI'),
    'pacote atualizado' => preg_match('/RS Connect 36\.(16\.3|17\.0|17\.1|17\.2|18\.[0123456]|19\.0)/', $version) === 1,
    'documentação atualizada' => str_contains($instructions, '/openai-usage'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "Falhas:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - menu e página dedicada do consumo OpenAI v36.16.3 validados.\n";
