<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = file_get_contents($root . '/app/Services/AdminExecutiveReportService.php') ?: '';
$view = file_get_contents($root . '/app/Views/reports/admin.php') ?: '';
$css = file_get_contents($root . '/public/assets/css/reports.css') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';

$assertions = [
    'versão 36.15.0 publicada' => str_contains($version, 'RS Connect 36.15.1')
        && str_contains($version, '075_scheduled_reports_and_deliveries.sql'),
    'cache global renovado' => str_contains($layout, 'app.css?v=36.15.1')
        && str_contains($layout, 'app.js?v=36.15.1'),
    'métricas executivas adicionadas' => str_contains($service, "'conversations_started'")
        && str_contains($service, "'avg_first_response_seconds'")
        && str_contains($service, 'teamPerformance')
        && str_contains($service, 'interactionsByHour'),
    'painel contém indicadores principais' => str_contains($view, 'rs-admin-kpi-grid')
        && str_contains($view, 'Tempo médio da 1ª resposta')
        && str_contains($view, 'Incidentes operacionais'),
    'painel contém os gráficos principais' => str_contains($view, 'Atendimentos ao longo do tempo')
        && str_contains($view, 'Distribuição das interações')
        && str_contains($view, 'Interações por horário'),
    'painel contém equipe agenda e IA' => str_contains($view, 'Desempenho da equipe')
        && str_contains($view, 'Resultado da agenda')
        && str_contains($view, 'Uso da IA'),
    'exportações rápidas disponíveis' => str_contains($view, 'Relatórios prontos para exportar')
        && str_contains($view, '/reports/export?'),
    'estilos responsivos incluídos' => str_contains($css, 'v36.15.0 — Painel executivo')
        && str_contains($css, '.rs-admin-chart-grid-primary')
        && str_contains($css, '@media (max-width: 600px)'),
];

$failed = [];
foreach ($assertions as $label => $ok) {
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "Falhas: " . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - painel executivo de relatórios da RS Admin validado na v36.15.0." . PHP_EOL;
