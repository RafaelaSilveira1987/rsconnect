<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
$service = (string) file_get_contents($root . '/app/Services/TeamProfessionalReportService.php');
$foundation = (string) file_get_contents($root . '/app/Services/TeamMetricsFoundationService.php');
$view = (string) file_get_contents($root . '/app/Views/reports/team.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$css = (string) file_get_contents($root . '/public/assets/css/reports.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$diagnostic = (string) file_get_contents($root . '/database/diagnostics/team_professional_reports_v36.10.0.sql');
$cycleMigration = (string) file_get_contents($root . '/database/migrations/068_conversation_service_cycles_compat.sql');
$recoveryMigration = (string) file_get_contents($root . '/database/migrations/069_service_cycle_recovery_compat.sql');

$checks = [
    'rota do relatório criada' => str_contains($routes, "'/reports/team'")
        && str_contains($routes, "'teamExport'"),
    'controller filtra empresa usuário e período' => str_contains($controller, 'teamFilters')
        && str_contains($controller, "'user_id'")
        && str_contains($controller, '365 days'),
    'serviço respeita escopo own/all' => str_contains($service, 'assertMayView')
        && str_contains($foundation, "'mode' => 'own'")
        && str_contains($foundation, "'mode' => 'all'"),
    'ciclos persistentes preservam reaberturas' => str_contains($cycleMigration, 'CREATE TABLE IF NOT EXISTS conversation_service_cycles')
        && str_contains($cycleMigration, 'conversation_reopened')
        && str_contains($cycleMigration, 'trg_rs_messages_after_insert_metrics')
        && str_contains($recoveryMigration, 'message_cycle_recovery'),
    'métricas de atendimento disponíveis' => str_contains($service, 'humanMessages')
        && str_contains($service, 'firstResponses')
        && str_contains($service, 'conversationClosures')
        && str_contains($service, 'assignmentIncoming'),
    'métricas da agenda disponíveis' => str_contains($service, 'appointmentsCompleted') === false
        && str_contains($service, 'appointments_completed')
        && str_contains($service, 'appointments_no_show')
        && str_contains($service, 'appointment_success_rate'),
    'clientes preferenciais separados' => str_contains($service, 'preferredClients')
        && str_contains($service, 'preferred_user_id'),
    'histórico recente combina conversa e agenda' => str_contains($service, 'conversation_assignment_history')
        && str_contains($service, 'calendar_appointment_history')
        && str_contains($service, 'recentActivities'),
    'filtros públicos usam UUID' => str_contains($view, 'name="tenant_uuid"')
        && str_contains($view, 'name="user_uuid"')
        && str_contains($view, "PublicId::encode('tenant'")
        && str_contains($view, "PublicId::encode('user'"),
    'tela contém KPIs comparativo e histórico' => str_contains($view, 'team-report-kpis')
        && str_contains($view, 'Desempenho por profissional')
        && str_contains($view, 'Movimentações recentes'),
    'exportação preserva escopo' => str_contains($controller, 'public function teamExport')
        && str_contains($controller, "'profissional'")
        && str_contains($controller, 'TeamProfessionalReportService'),
    'layout responsivo criado' => str_contains($css, '.team-report-kpis')
        && str_contains($css, '.team-report-table')
        && str_contains($css, '@media (max-width:620px)'),
    'diagnóstico adicionado' => str_contains($diagnostic, 'tempo_medio_primeira_resposta_segundos')
        && str_contains($diagnostic, 'nao_compareceram'),
    'versão e migrations históricas atualizadas até 070' => str_contains($version, 'RS Connect 36.10.5')
        && str_contains($version, '071_utc_datetime_contract_compat.sql')
        && str_contains($foundation, 'conversation_service_cycles'),
    'cache visual atualizado' => str_contains($layout, 'app.css?v=36.10.5')
        && str_contains($layout, 'app.js?v=36.10.5'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - relatório de equipe e profissionais com escopo, UUID, atendimento, agenda e exportação.\n";
