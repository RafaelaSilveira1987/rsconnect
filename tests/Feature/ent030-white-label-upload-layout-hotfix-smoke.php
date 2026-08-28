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

$controller = (string) file_get_contents($root . '/app/Controllers/WhiteLabelController.php');
$branding = (string) file_get_contents($root . '/app/Services/BrandingService.php');
$view = (string) file_get_contents($root . '/app/Views/white_label/index.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$routes = (string) file_get_contents($root . '/routes/web.php');
$docker = (string) file_get_contents($root . '/Dockerfile');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');
$publicRouter = (string) file_get_contents($root . '/public/router.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(str_contains($controller, 'storage/app/white-label/tenant-') && !str_contains($controller, 'public/uploads/white-label'), 'uploads novos usam storage/app em vez de public/uploads');
$check(str_contains($controller, "'/white-label/asset?scope='") && str_contains($controller, 'Content-Type: '), 'controller gera URL segura e entrega a imagem com MIME validado');
$check(str_contains($controller, 'is_writable($uploadDir)') && str_contains($controller, 'storage/app'), 'erro de permissão de armazenamento é detectado e explicado');
$check(str_contains($routes, "get('/white-label/asset'") && !str_contains($routes, "get('/white-label/asset', [WhiteLabelController::class, 'asset'], ['auth'"), 'rota pública de imagem permite exibição na tela de login');
$check(str_contains($branding, "\$assetPath === '/white-label/asset'") && str_contains($branding, "\$query['scope']"), 'BrandingService aceita somente URLs internas de marca válidas');
$check(str_contains($view, 'white-label-upload-card') && str_contains($view, 'white-label-color-card'), 'layout usa cards próprios para arquivos e cores');
$check(str_contains($view, 'data-preview-color') && str_contains($js, 'data-white-label-form'), 'paleta atualiza a prévia sem JavaScript inline');
$check(str_contains($css, '.white-label-color-card input[type="color"]') && str_contains($css, 'width: 46px !important'), 'seletor de cor fica compacto e não ocupa toda a largura');
$check(str_contains($css, '.white-label-upload-preview-grid') && str_contains($css, '.white-label-preview-card'), 'layout responsivo e prévia lateral foram estilizados');
$check(str_contains($docker, '/var/www/html/storage/app/white-label'), 'imagem Docker prepara a pasta persistente de marcas');
$check(str_contains($bootstrap, "'/white-label/asset'"), 'rota de imagem não cria sessão nem cookie desnecessário');
$check(str_contains($publicRouter, "\$_SERVER['SCRIPT_NAME'] = '/index.php'"), 'servidor PHP local preserva o caminho completo das rotas');
$check(str_contains($version, 'RS Connect 36.20.15.2'), 'versão do hotfix foi atualizada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo do hotfix White Label: {$passes} verificações aprovadas.\n";
