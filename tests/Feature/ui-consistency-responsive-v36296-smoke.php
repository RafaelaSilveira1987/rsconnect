<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$css = $read('public/assets/css/app.css');
$appLayout = $read('app/Views/layouts/app.php');
$guestLayout = $read('app/Views/layouts/guest.php');
$restrictedLayout = $read('app/Views/layouts/restricted.php');
$version = $read('app/Services/AppVersionService.php');
$agents = $read('app/Views/agents/index.php');
$instances = $read('app/Views/instances/index.php');
$onboarding = $read('app/Views/onboarding/index.php');
$js = $read('public/assets/js/app.js');

$checks = [
    'camada visual 36.29.6 presente' => str_contains($css, 'RS Connect 36.29.6 — camada de consistência visual'),
    'controles de formulário padronizados' => str_contains($css, '--rs-form-control-height: 44px')
        && str_contains($css, 'input[type="datetime-local"]')
        && str_contains($css, 'form input[type="file"]::file-selector-button'),
    'checkbox e radio preservam controle nativo' => str_contains($css, 'input[type="checkbox"]')
        && str_contains($css, 'accent-color: var(--rs-teal)')
        && !str_contains($css, 'input[type="checkbox"] { appearance: none'),
    'mobile transforma grades de formulário' => str_contains($css, '@media (max-width: 680px)')
        && str_contains($css, '.form-grid.two')
        && str_contains($css, 'grid-template-columns: 1fr !important'),
    'filtros e ações preparados para mobile' => str_contains($css, '.conversation-filters')
        && str_contains($css, '.form-actions')
        && str_contains($css, '.table-wrap table'),
    'cache dos layouts atualizado' => str_contains($appLayout, 'app.css?v=36.29.6')
        && str_contains($appLayout, 'app.js?v=36.29.6')
        && str_contains($guestLayout, 'app.css?v=36.29.6')
        && str_contains($restrictedLayout, 'app.css?v=36.29.6'),
    'pacote identificado como 36.29.6' => str_contains($version, 'RS Connect 36.29.6 — Formulários consistentes e responsividade mobile'),
    'configurações recentes do agente preservadas' => str_contains($agents, 'name="message_grouping_enabled"')
        && str_contains($agents, 'name="prioritize_current_turn"')
        && str_contains($js, 'data-workflow-move'),
    'configurações de instância preservadas' => str_contains($instances, 'name="webhook_enabled"')
        && str_contains($instances, 'name="receive_messages"')
        && str_contains($instances, 'name="webhook_events[]"'),
    'modos de agenda do onboarding preservados' => str_contains($onboarding, 'name="calendar_mode"')
        && str_contains($onboarding, 'name="internal_days[]"'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

exit($failed === [] ? 0 : 1);
