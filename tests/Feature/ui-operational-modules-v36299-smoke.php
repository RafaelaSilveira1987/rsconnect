<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failed = 0;
$check = static function (bool $condition, string $message, int &$failed): void {
    if ($condition) {
        echo "[OK] {$message}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$message}\n";
};

$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$appCss = $read('public/assets/css/app.css');
$reportsCss = $read('public/assets/css/reports.css');
$agentView = $read('app/Views/agents/index.php');
$calendarView = $read('app/Views/calendar/index.php');
$campaignView = $read('app/Views/campaigns/index.php');
$billingView = $read('app/Views/billing/index.php');
$subscriptionView = $read('app/Views/billing/subscription.php');
$reportViews = implode("\n", [
    $read('app/Views/reports/index.php'),
    $read('app/Views/reports/admin.php'),
    $read('app/Views/reports/automatic.php'),
    $read('app/Views/reports/team.php'),
]);
$layout = $read('app/Views/layouts/app.php');
$version = $read('app/Services/AppVersionService.php');

$check(str_contains($appCss, 'RS Connect 36.29.9 — quarta rodada visual'), 'camada visual 36.29.9 presente no app.css', $failed);
$check(str_contains($reportsCss, 'RS Connect 36.29.9 — quarta rodada visual / Relatórios'), 'camada visual 36.29.9 presente no reports.css', $failed);
$check(str_contains($appCss, '.agent-operation-capabilities .agent-rule-toggle') && str_contains($appCss, 'grid-template-columns: 20px minmax(0, 1fr)'), 'permissões da automação alinham checkbox e conteúdo', $failed);
$check(str_contains($agentView, 'name="agent_capabilities[') && str_contains($agentView, 'policy.fail_closed'), 'inputs e proteção das permissões do agente preservados', $failed);

$check(str_contains($calendarView, 'calendar-page-heading') && str_contains($calendarView, 'calendar-metrics'), 'hooks visuais seguros adicionados à Agenda', $failed);
$check(str_contains($calendarView, "Router::url('/calendar/appointments')") && str_contains($calendarView, "Router::url('/calendar/status')") && str_contains($calendarView, "Router::url('/calendar/delete')"), 'ações funcionais da Agenda preservadas', $failed);
$check(str_contains($appCss, '.calendar-filter-bar') && str_contains($appCss, '.calendar-metrics'), 'Agenda possui filtros e métricas responsivos', $failed);

$check(str_contains($campaignView, 'campaign-filter-bar'), 'filtro de Campanhas possui hook visual próprio', $failed);
$check(str_contains($campaignView, "Router::url('/campaigns/audience')") && str_contains($campaignView, "Router::url('/campaigns/approve')") && str_contains($campaignView, "Router::url('/campaigns/dispatch')") && str_contains($campaignView, "Router::url('/campaigns/status')"), 'ações funcionais de Campanhas preservadas', $failed);
$check(str_contains($appCss, '.campaign-layout') && str_contains($appCss, '.campaign-card') && str_contains($appCss, '.campaign-recipients-table'), 'Campanhas recebeu camada visual responsiva', $failed);

$check(substr_count($reportViews, 'reports.css?v=36.29.9') === 4, 'cache de reports.css atualizado nas quatro views', $failed);
$check(str_contains($reportsCss, '.scheduled-report-sections label:has(input:checked)') && str_contains($reportsCss, '.team-report-kpis'), 'Relatórios automáticos/equipe preservam controles com responsividade', $failed);

$check(str_contains($billingView, 'billing-invoice-actions') && str_contains($subscriptionView, 'client-invoice-card'), 'estruturas funcionais de Cobranças/Assinatura preservadas', $failed);
$check(str_contains($appCss, '.billing-subscription-card') && str_contains($appCss, '.client-invoice-section'), 'Cobranças recebeu acabamento responsivo', $failed);

$check(str_contains($layout, 'app.css?v=36.29.9') && str_contains($layout, 'app.js?v=36.29.9'), 'cache principal atualizado para 36.29.9', $failed);
$check(str_contains($version, 'RS Connect 36.29.9 — Agenda, Campanhas, Relatórios e Cobranças'), 'pacote identificado como 36.29.9', $failed);

exit($failed === 0 ? 0 : 1);
