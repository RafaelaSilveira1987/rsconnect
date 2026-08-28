<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');

use App\Controllers\WhiteLabelController;
use App\Core\ContentSecurityPolicy;
use App\Services\BrandingService;

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

$header = ContentSecurityPolicy::headerValue(true);
$check(!str_contains($header, "script-src 'self' 'unsafe-inline'"), 'script-src não usa unsafe-inline');
$check(str_contains($header, "script-src 'self' 'nonce-") && str_contains($header, "script-src-attr 'none'"), 'CSP usa nonce e bloqueia handlers inline');
$check(str_contains($header, "style-src-elem 'self' 'nonce-") && str_contains($header, "style-src-attr 'unsafe-inline'"), 'estilos embutidos são limitados por diretiva específica');
$check(str_contains($header, "object-src 'none'") && str_contains($header, "base-uri 'self'"), 'CSP bloqueia objetos e base URI externa');

$markup = ContentSecurityPolicy::addNonceToMarkup('<style>.x{display:none}</style><script>window.ok=true;</script><script src="/app.js"></script>');
$check(substr_count($markup, 'nonce="') === 3, 'nonce é aplicado a scripts e estilos renderizados');

$whiteLabel = (string) file_get_contents($root . '/app/Controllers/WhiteLabelController.php');
$whiteLabelView = (string) file_get_contents($root . '/app/Views/white_label/index.php');
$branding = (string) file_get_contents($root . '/app/Services/BrandingService.php');
$uploadsRules = (string) file_get_contents($root . '/public/uploads/.htaccess');
$appJs = (string) file_get_contents($root . '/public/assets/js/app.js');
$reportsJs = (string) file_get_contents($root . '/public/assets/js/reports.js');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$router = (string) file_get_contents($root . '/public/router.php');
$auditScript = (string) file_get_contents($root . '/bin/audit-public-uploads.php');
$legacyHealth = (string) file_get_contents($root . '/public/health.php');

$check(!str_contains($whiteLabel, "'image/svg+xml'") && !str_contains($whiteLabel, "'image/x-icon'"), 'upload white label não aceita SVG nem ICO');
$check(str_contains($whiteLabel, 'is_uploaded_file') && str_contains($whiteLabel, 'getimagesize'), 'upload valida origem, MIME e estrutura real da imagem');
$check(str_contains($whiteLabel, '4096') && str_contains($whiteLabel, '16000000'), 'upload limita dimensões e quantidade de pixels');
$check(!str_contains($whiteLabelView, '.svg') && !str_contains($whiteLabelView, '.ico'), 'formulário oferece somente PNG, JPG e WEBP');
$check(str_contains($uploadsRules, 'png|jpe?g|webp') && str_contains($uploadsRules, 'Require all denied') && str_contains($uploadsRules, 'RemoveHandler .php'), 'pasta pública serve somente imagens rasterizadas e bloqueia executáveis');
$check(str_contains($branding, "javascript|data|file|vbscript") && str_contains($branding, "'/uploads/'"), 'URLs de marca rejeitam esquemas ativos e uploads inseguros');
$check(str_contains($router, "['png', 'jpg', 'jpeg', 'webp']") && str_contains($router, 'str_starts_with($path, \'/uploads/\')'), 'servidor local também bloqueia arquivos ativos em uploads');
$check(str_contains($auditScript, 'RecursiveDirectoryIterator') && str_contains($auditScript, 'extensão não permitida'), 'auditoria de uploads públicos está disponível');
$check(str_contains($legacyHealth, '{"status":"ok"}') && !str_contains($legacyHealth, 'SELECT DATABASE') && !str_contains($legacyHealth, 'getMessage'), 'health legado não expõe banco nem exceções');

$inlineHandlers = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/app/Views'));
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
        continue;
    }
    $file = $fileInfo->getPathname();
    $contents = (string) file_get_contents($file);
    if (preg_match('/\son(?:click|change|submit|load|error|input|keyup|keydown|mouseover|focus|blur)\s*=/i', $contents) === 1) {
        $inlineHandlers[] = str_replace($root . '/', '', $file);
    }
}
$check($inlineHandlers === [], 'views não possuem handlers JavaScript inline');
$check(str_contains($appJs, '[data-auto-submit]') && str_contains($appJs, 'form.dataset.confirm'), 'ações antigas foram migradas para listeners declarativos');
$check(!str_contains($appJs, 'hint.innerHTML = `Digite exatamente:') && str_contains($appJs, "strong.textContent = `EXCLUIR"), 'nome da instância não é inserido como HTML');
$check(str_contains($reportsJs, 'escapeHtml(row.label') && str_contains($reportsJs, 'escapeHtml(labels[item.key]'), 'tooltips de relatórios escapam conteúdo dinâmico');
$check(str_contains($version, 'RS Connect 36.20.15') && str_contains($version, 'proteção contra XSS, SVG e CSP'), 'versão do pacote foi atualizada');

$reflection = new ReflectionMethod(WhiteLabelController::class, 'isSafeBrandAssetUrl');
$controller = new WhiteLabelController();
$check($reflection->invoke($controller, 'https://cdn.exemplo.com/logo.png') === true, 'URL HTTPS de imagem rasterizada é aceita');
$check($reflection->invoke($controller, 'https://cdn.exemplo.com/logo.svg') === false, 'URL SVG externa é rejeitada');
$check($reflection->invoke($controller, 'javascript:alert(1)') === false, 'URL com esquema ativo é rejeitada');
$check(BrandingService::assetUrl('/uploads/white-label/tenant-1/logo.svg') === '', 'SVG antigo em uploads não é renderizado');
$check(BrandingService::assetUrl('/assets/img/RS_Connect_horizontal_vetor.svg') !== '', 'SVG estático confiável da aplicação permanece disponível');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo ENT-030: {$passes} verificações aprovadas.\n";
