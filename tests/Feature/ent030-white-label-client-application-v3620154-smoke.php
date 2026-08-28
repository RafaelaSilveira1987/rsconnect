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

$appLayout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$guestLayout = (string) file_get_contents($root . '/app/Views/layouts/guest.php');
$login = (string) file_get_contents($root . '/app/Views/auth/login.php');
$whiteLabel = (string) file_get_contents($root . '/app/Views/white_label/index.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(str_contains($appLayout, 'BrandingService::forCurrentRequest()'), 'painel autenticado carrega a marca da empresa atual');
$check(str_contains($appLayout, 'brand-mark-image') && str_contains($appLayout, '$brandName'), 'sidebar do cliente exibe logo, nome e subtítulo personalizados');
$check(str_contains($appLayout, '$brandCssVariables') && str_contains($appLayout, '--rs-blue:'), 'cores da empresa são aplicadas como tokens do painel');
$check(str_contains($appLayout, '$brandFaviconUrl') && str_contains($appLayout, 'rel="icon"'), 'favicon da empresa é aplicado no painel');
$check(str_contains($appLayout, 'tenant-brand-footer') && str_contains($appLayout, 'Powered by RS Connect'), 'rodapé configurável é exibido no painel do cliente');
$check(str_contains($guestLayout, 'BrandingService::forCurrentRequest()'), 'layout público resolve a marca por empresa, slug ou domínio');
$check(str_contains($login, '$loginTitle') && str_contains($login, '$loginButtonText') && str_contains($login, '$benefits'), 'login real usa textos e benefícios personalizados');
$check(str_contains($login, '$brandMainImage') && str_contains($login, '$brandCompactImage'), 'login real usa logo principal e ícone da empresa');
$check(str_contains($whiteLabel, 'white-label-hero'), 'tela de configuração possui contêiner visual dedicado');
$check(str_contains($css, 'grid-template-columns: minmax(0, 820px) minmax(320px, 390px)') && str_contains($css, 'width: min(1280px, 100%)'), 'layout administrativo possui proporção e largura máxima controladas');
$check(str_contains($css, '.brand-preview-shell') && str_contains($css, '.preview-brand-image img') && str_contains($css, 'object-fit: contain'), 'prévia limita e enquadra a logo sem deformação');
$check(str_contains($css, '.has-tenant-branding .sidebar') && str_contains($css, '.brand-mark-image img'), 'painel do cliente possui estilos específicos de marca');
$check(str_contains($version, 'RS Connect 36.20.15.4') && str_contains($version, 'White Label aplicado ao painel e login do cliente'), 'versão interna foi atualizada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo White Label cliente v36.20.15.4: {$passes} verificações aprovadas.\n";
