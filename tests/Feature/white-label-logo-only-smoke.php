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

$check(str_contains($view, 'name="brand_logo_file"'), 'tela permite enviar a logo do cliente');
$check(str_contains($view, 'name="remove_logo"'), 'tela permite remover a logo atual');
$check(str_contains($view, 'PNG, JPG/JPEG ou WEBP'), 'tela documenta os formatos permitidos');

$forbiddenViewFields = [
    'name="brand_name"',
    'name="brand_subtitle"',
    'name="brand_icon_file"',
    'name="brand_favicon_file"',
    'name="brand_primary_color"',
    'name="brand_secondary_color"',
    'name="brand_accent_color"',
    'name="login_title"',
    'name="custom_domain"',
    'name="show_powered_by"',
];
foreach ($forbiddenViewFields as $field) {
    $check(!str_contains($view, $field), "campo removido da tela: {$field}");
}

$check(str_contains($controller, "\$_FILES['brand_logo_file']"), 'controller processa somente o upload principal');
$check(!str_contains($controller, "\$_FILES['brand_icon_file']"), 'controller não processa ícone separado');
$check(!str_contains($controller, "\$_FILES['brand_favicon_file']"), 'controller não processa favicon');
$check(str_contains($controller, 'white_label_enabled = :enabled') && str_contains($controller, 'brand_logo_url = :brand_logo_url'), 'persistência limitada à ativação e à logo');
$check(!str_contains($controller, 'brand_primary_color = :'), 'controller não grava cores personalizadas');
$check(!str_contains($controller, 'custom_domain = :'), 'controller não grava domínio personalizado');

$check(str_contains($branding, "'logo_url' => \$logoUrl"), 'serviço aplica a logo do cliente');
$check(str_contains($branding, 'return array_replace($default'), 'serviço parte integralmente da identidade padrão');
$check(!str_contains($branding, "\$tenant['brand_primary_color']"), 'serviço ignora cores antigas do cliente');
$check(!str_contains($branding, "\$tenant['brand_name']"), 'serviço ignora nome personalizado antigo');
$check(!str_contains($branding, "\$tenant['brand_favicon_url']"), 'serviço ignora favicon antigo');
$check(!str_contains($branding, "\$tenant['login_title']"), 'serviço ignora textos antigos do login');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo: {$passes} verificações aprovadas para White Label somente com logo.\n";
