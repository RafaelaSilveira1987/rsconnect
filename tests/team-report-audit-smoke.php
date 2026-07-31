<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/TeamProfessionalReportService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/ReportController.php');
$view = (string) file_get_contents($root . '/app/Views/reports/team.php');
$css = (string) file_get_contents($root . '/public/assets/css/reports.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'serviço calcula qualidade diretamente nos ciclos' => str_contains($service, 'responseDataQuality')
        && str_contains($service, 'measured_responses')
        && str_contains($service, 'invalid_response_cycles')
        && str_contains($service, 'pending_response_cycles'),
    'média geral usa agregado bruto' => str_contains($service, "overview['first_responses']")
        && str_contains($service, "overview['avg_first_response_seconds']")
        && str_contains($service, 'average of rounded professional averages'),
    'auditoria converte UTC para fuso local' => str_contains($service, 'firstResponseAudit')
        && str_contains($service, 'first_incoming_at_local')
        && str_contains($service, 'first_response_at_local')
        && str_contains($service, 'Clock::utcToLocal'),
    'CSV detalhado não expõe ID numérico' => str_contains($controller, "detail'] ?? '') === 'first_responses'")
        && str_contains($controller, "'conversation_uuid'")
        && str_contains($controller, 'rs-connect-auditoria-primeiras-respostas.csv'),
    'tela mostra conferência da métrica' => str_contains($view, 'Auditoria das primeiras respostas')
        && str_contains($view, 'Respostas medidas')
        && str_contains($view, 'Datas inconsistentes')
        && str_contains($view, 'Exportar 1ª respostas'),
    'estilos responsivos adicionados' => str_contains($css, '.team-report-audit-kpis')
        && str_contains($css, '.team-report-audit-table')
        && str_contains($css, '@media (max-width:620px)'),
    'versão e cache atualizados' => str_contains($version, 'RS Connect 36.10.7')
        && str_contains($layout, 'app.css?v=36.10.7')
        && str_contains($layout, 'app.js?v=36.10.7'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - auditoria de primeira resposta com UTC/local, consistência e exportação pública.\n";
