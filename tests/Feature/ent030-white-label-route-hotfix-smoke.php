<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
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
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$controller = (string) file_get_contents($root . '/app/Controllers/WhiteLabelController.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(str_contains($routes, 'use App\\Controllers\\WhiteLabelController;'), 'controller White Label importado nas rotas');
$check(str_contains($routes, "\$router->get('/white-label', [WhiteLabelController::class, 'index'], ['auth', 'super_admin'])"), 'rota canônica protegida registrada');
$check(str_contains($routes, "\$router->get('/white_label', [WhiteLabelController::class, 'index'], ['auth', 'super_admin'])"), 'alias legado com sublinhado registrado');
$check(str_contains($routes, "\$router->post('/white-label/save', [WhiteLabelController::class, 'save'], ['auth', 'super_admin', 'csrf'])"), 'salvamento protegido por autenticação, perfil e CSRF');
$check(str_contains($routes, "\$router->get('/white-label/preview', [WhiteLabelController::class, 'preview'], ['auth', 'super_admin'])"), 'pré-visualização protegida registrada');
$check(str_contains($layout, "Router::url('/white-label')") && str_contains($layout, 'Marca dos clientes'), 'menu do Super Admin aponta para a rota canônica');
$check(str_contains($controller, 'uploadBrandAsset') && str_contains($controller, "'image/webp' => 'webp'"), 'proteções de upload da ENT-030 preservadas');
$check(str_contains($version, 'RS Connect 36.20.15.1'), 'versão do hotfix atualizada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo hotfix White Label: {$passes} verificações aprovadas.\n";
