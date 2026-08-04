<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/TeamProfessionalReportService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
$view = (string) file_get_contents($root . '/app/Views/reports/team.php');
$css = (string) file_get_contents($root . '/public/assets/css/reports.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$diagnostic = (string) file_get_contents($root . '/database/diagnostics/team_metrics_provenance_v36.11.2.sql');

$checks = [
    'fontes históricas são classificadas explicitamente' => str_contains($service, 'HISTORICAL_CYCLE_SOURCES')
        && str_contains($service, 'migration_snapshot')
        && str_contains($service, 'migration_069_recovery')
        && str_contains($service, 'historical_recovered'),
    'corte confiável vem do contrato UTC' => str_contains($service, 'reliableCutoverAtUtc')
        && str_contains($service, 'rs_datetime_contract')
        && str_contains($service, 'cutover_at_local'),
    'filtro operacional alcança métricas de ciclo' => str_contains($service, 'cycleReliabilityFilter')
        && str_contains($service, 'firstResponses($tenantId, $date, $selectedUserId, $operationalOnly)')
        && str_contains($service, 'conversationClosures($tenantId, $date, $selectedUserId, $operationalOnly)')
        && str_contains($service, 'responseDataQuality($tenantId, $date, $selectedUserId, $operationalOnly)'),
    'proveniência apresenta contagens separadas' => str_contains($service, 'historical_recovered_cycles')
        && str_contains($service, 'operational_recovered_cycles')
        && str_contains($service, 'realtime_operational_cycles')
        && str_contains($service, 'included_cycles'),
    'controller aceita filtro e exporta qualidade' => str_contains($controller, "'operational_only'")
        && str_contains($controller, "'qualidade_dado'")
        && str_contains($controller, "'origem_descricao'")
        && str_contains($controller, "'inicio_metrica_confiavel_utc'"),
    'tela mostra filtro e transparência dos dados' => str_contains($view, 'Somente métricas operacionais')
        && str_contains($view, 'Qualidade das métricas de ciclo')
        && str_contains($view, 'Históricos recuperados')
        && str_contains($view, 'data_quality_label'),
    'estilos da proveniência são responsivos' => str_contains($css, '.team-report-provenance')
        && str_contains($css, '.team-report-operational-filter')
        && str_contains($css, '.team-report-source-label'),
    'diagnóstico separa histórico de operacional' => str_contains($diagnostic, 'historical_recovered')
        && str_contains($diagnostic, 'operational_recovered')
        && str_contains($diagnostic, 'cutover_at_utc'),
    'versão e cache atualizados' => str_contains($version, 'RS Connect 36.15.0')
        && str_contains($layout, 'app.css?v=36.15.0')
        && str_contains($layout, 'app.js?v=36.15.0'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - métricas históricas recuperadas e métricas operacionais estão identificadas, filtráveis e auditáveis.\n";
