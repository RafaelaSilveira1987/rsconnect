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
$settings = $read('app/Views/companies/settings.php');
$admin = $read('app/Views/dashboard/admin.php');
$js = $read('public/assets/js/app.js');

$checks = [
    'camada visual 36.29.7 presente' => str_contains($css, 'RS Connect 36.29.7 — hierarquia visual unificada por módulo'),
    'agentes possuem tratamento visual dedicado' => str_contains($css, '.agent-page-actions')
        && str_contains($css, '.agent-card.is-settings-open')
        && str_contains($css, '.agent-admin-tenant-selector'),
    'instâncias possuem tratamento visual dedicado' => str_contains($css, '.admin-module-filters')
        && str_contains($css, '.instance-event-grid .check-field:has(input:checked)')
        && str_contains($css, '#instance-settings-drawer .drawer-savebar'),
    'onboarding possui roteiro e etapa responsivos' => str_contains($css, '.onboarding-steps-card')
        && str_contains($css, 'position: sticky')
        && str_contains($css, '.onboarding-step-panel.is-current'),
    'configurações possuem cards e accordions uniformes' => str_contains($css, '.client-settings-accordion[open] > summary')
        && str_contains($css, '.client-company-savebar')
        && str_contains($css, '.module-setting-card:has(input:checked)'),
    'rs admin possui tratamento visual dedicado' => str_contains($css, '.admin-executive-hero')
        && str_contains($css, '.admin-attention-actions')
        && str_contains($css, '.admin-recent-company'),
    'breakpoints adicionais presentes' => str_contains($css, '@media (max-width: 1024px)')
        && str_contains($css, '@media (max-width: 820px)')
        && str_contains($css, '@media (max-width: 440px)'),
    'cache atualizado para 36.29.7' => str_contains($appLayout, 'app.css?v=36.29.7')
        && str_contains($appLayout, 'app.js?v=36.29.7')
        && str_contains($guestLayout, 'app.css?v=36.29.7')
        && str_contains($restrictedLayout, 'app.css?v=36.29.7'),
    'pacote identificado como 36.29.7' => str_contains($version, 'RS Connect 36.29.7 — Hierarquia visual unificada e experiência mobile'),
    'funcionalidades de agentes preservadas' => str_contains($agents, 'name="message_grouping_enabled"')
        && str_contains($agents, 'name="prioritize_current_turn"')
        && str_contains($js, 'data-workflow-move'),
    'funcionalidades de instâncias preservadas' => str_contains($instances, 'name="webhook_enabled"')
        && str_contains($instances, 'name="receive_messages"')
        && str_contains($instances, 'name="webhook_events[]"'),
    'funcionalidades do onboarding preservadas' => str_contains($onboarding, 'name="calendar_mode"')
        && str_contains($onboarding, 'name="internal_days[]"'),
    'configurações preservam switches e módulos' => str_contains($settings, 'name="module_visible[]"')
        && str_contains($settings, 'name="whatsapp_human_signature_enabled"'),
    'dashboard admin preserva ações funcionais' => str_contains($admin, 'data-toggle-panel="admin-company-create-drawer"')
        && str_contains($admin, "Router::url('/instances')"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

exit($failed === [] ? 0 : 1);
