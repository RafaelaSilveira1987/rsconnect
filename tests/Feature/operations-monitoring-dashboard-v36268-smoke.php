<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/operations/index.php');
$service = (string) file_get_contents($root . '/app/Services/OperationsService.php');
$language = (string) file_get_contents($root . '/app/Services/OperationalLanguageService.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'visão separa resumo, rotinas e histórico' => str_contains($view, 'data-monitor-tab="overview"')
        && str_contains($view, 'data-monitor-tab="routines"')
        && str_contains($view, 'data-monitor-tab="history"'),
    'painel possui gráficos operacionais' => str_contains($view, 'operations-bar-chart')
        && str_contains($view, 'operations-health-donut'),
    'rotinas usam rótulo correto' => str_contains($view, 'rotinas monitoradas')
        && !str_contains($view, '?> verificações</span>'),
    'histórico é progressivo e distingue normalizados' => str_contains($view, 'data-page-size="8"')
        && str_contains($view, "'Normalizado'"),
    'backend entrega analytics e tendência' => str_contains($service, 'monitoringAnalytics')
        && str_contains($service, 'incidentTrend(7)')
        && str_contains($service, "'resolved_7d'"),
    'financeiro explica ausência de confirmação posterior' => str_contains($service, 'Isso não confirma que o serviço continua indisponível')
        && str_contains($language, 'Isso não significa, por si só, que o Asaas continua indisponível'),
    'css responsivo da nova central existe' => str_contains($css, 'RS Connect 36.26.8')
        && str_contains($css, '.operations-summary-strip')
        && str_contains($css, '.operations-overview-grid-v3'),
    'marcador histórico identifica a versão 36.26.8' => str_contains($version, 'RS Connect 36.26.8 — Central de Monitoramento compacta, gráficos e histórico progressivo.'),
];

$failed = [];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Falhas:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - Central de Monitoramento v36.26.8 validada.\n";
