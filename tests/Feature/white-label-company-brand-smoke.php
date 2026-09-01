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
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$check(str_contains($view, 'name="brand_logo_file"'), 'tela permite enviar a logo do cliente');
$check(str_contains($view, 'name="remove_logo"'), 'tela permite remover a logo atual');
$check(str_contains($view, 'PNG, JPG/JPEG ou WEBP'), 'tela documenta os formatos permitidos');
$check(str_contains($view, "\$selected['name']"), 'prévia usa o nome cadastrado da empresa');
$check(str_contains($view, 'nome exibido vem automaticamente do cadastro da empresa'), 'tela explica a origem do nome');

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
    $check(!str_contains($view, $field), "campo continua removido da tela: {$field}");
}

$check(str_contains($controller, "\$_FILES['brand_logo_file']"), 'controller processa o upload principal');
$check(!str_contains($controller, "\$_FILES['brand_icon_file']"), 'controller não processa ícone separado');
$check(!str_contains($controller, "\$_FILES['brand_favicon_file']"), 'controller não processa favicon');
$check(str_contains($controller, 'white_label_enabled = :enabled') && str_contains($controller, 'brand_logo_url = :brand_logo_url'), 'persistência permanece limitada à logo');
$check(str_contains($controller, '$pdo->beginTransaction()'), 'gravação da logo usa transação');
$check(str_contains($controller, 'SELECT white_label_enabled, brand_logo_url'), 'controller relê a configuração depois de salvar');
$check(str_contains($controller, 'banco não confirmou a gravação'), 'controller acusa falha real de persistência');
$check(str_contains($controller, 'storage/app/white-label/tenant-'), 'logo é armazenada no storage persistente');

$check(str_contains($branding, "'tenant_identity' => true"), 'serviço identifica a empresa autenticada');
$check(str_contains($branding, "'app_name' => \$tenantName"), 'serviço usa o nome do cadastro da empresa');
$check(str_contains($branding, "'logo_url' => \$logoUrl"), 'serviço aplica a logo salva');
$check(str_contains($branding, "'enabled' => \$logoUrl !== ''"), 'logo válida controla apenas a exibição da imagem');
$check(!str_contains($branding, "\$tenant['brand_name']"), 'nome antigo editável não é usado');
$check(!str_contains($branding, "\$tenant['brand_primary_color']"), 'cores antigas continuam ignoradas');
$check(str_contains($branding, 'private static function initials'), 'serviço cria iniciais quando não há logo');

$check(str_contains($layout, '$tenantIdentity ='), 'layout separa identidade da empresa da existência da logo');
$check(str_contains($layout, '$brandName = $tenantIdentity'), 'layout mostra o nome da empresa mesmo sem logo');
$check(str_contains($layout, '$brandLogoUrl !=='), 'layout exibe a logo quando ela existe');
$check(str_contains($layout, 'brand-mark-client-logo'), 'layout possui contêiner específico para logo do cliente');
$check(str_contains($layout, '$brandLogoMarkStyle ='), 'layout define fundo específico para a logo do cliente');
$check(str_contains($layout, 'linear-gradient(180deg, #f8fbfd 0%, #edf3f8 100%)'), 'fundo da logo usa painel neutro para harmonizar com a sidebar');
$check(str_contains($layout, 'object-fit: contain'), 'logo mantém proporção no cabeçalho');

$check(!str_contains($layout, '$brandIconUrl'), 'layout não tenta usar ícone antigo separado');

require_once $root . '/app/Services/BrandingService.php';
$brandingClass = new ReflectionClass(App\Services\BrandingService::class);
$buildMethod = $brandingClass->getMethod('buildFromTenant');
$buildMethod->setAccessible(true);
$sampleLogo = '/white-label/asset?scope=27&file=logo-20260901204530-a1b2c3d4.png';
$sample = $buildMethod->invoke(null, [
    'id' => 27,
    'name' => 'Clínica Exemplo',
    'white_label_enabled' => 0,
    'brand_logo_url' => $sampleLogo,
], App\Services\BrandingService::defaults());

$check(($sample['tenant_identity'] ?? false) === true, 'execução real reconhece a identidade da empresa');
$check(($sample['app_name'] ?? '') === 'Clínica Exemplo', 'execução real devolve o nome cadastrado');
$check(($sample['logo_url'] ?? '') === $sampleLogo, 'execução real devolve a logo persistida');
$check(($sample['enabled'] ?? false) === true, 'logo válida é exibida mesmo se a flag antiga estiver inconsistente');
$check(($sample['icon_text'] ?? '') === 'CE', 'iniciais são calculadas a partir do nome da empresa');



if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo: {$passes} verificações aprovadas para nome e logo da empresa.\n";
