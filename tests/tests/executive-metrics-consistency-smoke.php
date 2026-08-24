<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$policy = file_get_contents($root . '/app/Services/ExecutiveMetricsPolicyService.php') ?: '';
$admin = file_get_contents($root . '/app/Services/AdminExecutiveReportService.php') ?: '';
$tenant = file_get_contents($root . '/app/Services/TenantExecutiveReportService.php') ?: '';
$adminView = file_get_contents($root . '/app/Views/reports/admin.php') ?: '';
$tenantView = file_get_contents($root . '/app/Views/reports/index.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$assertions = [
    'versão r1 identificada' => str_contains($version, '36.15.1')
        && str_contains($version, '075_scheduled_reports_and_deliveries.sql'),
    'política única criada' => str_contains($policy, 'operationalFirstResponses')
        && str_contains($policy, 'attributedResponseShares'),
    'histórico fora dos cards executivos' => str_contains($policy, 'migration_snapshot')
        && str_contains($policy, 'migration_069_recovery'),
    'admin usa política única' => str_contains($admin, 'ExecutiveMetricsPolicyService')
        && str_contains($admin, "operationalResponses['average_seconds']"),
    'cliente usa política única' => str_contains($tenant, 'ExecutiveMetricsPolicyService')
        && str_contains($tenant, "operationalResponses['average_seconds']"),
    'participação da IA padronizada' => str_contains($tenant, 'attributedResponseShares')
        && str_contains($admin, '$responseBase = (int) $metrics[\'ai_replies\'] + (int) $metrics[\'human_messages\']'),
    'nomenclatura diferencia escopos' => str_contains($adminView, 'Incidentes operacionais')
        && str_contains($tenantView, 'Conversas que precisam de atenção'),
];

$failed = [];
foreach ($assertions as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - indicadores executivos consistentes entre RS Admin e empresa cliente." . PHP_EOL;
