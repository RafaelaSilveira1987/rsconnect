<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$servicePath = $root . '/app/Services/OperationalAlertService.php';
$layoutPath = $root . '/app/Views/layouts/app.php';
$manifestPath = $root . '/database/migrations/manifest.php';
$migrationPath = $root . '/database/migrations/100_operational_health_digest_delivery.sql';
$versionPath = $root . '/app/Services/AppVersionService.php';

$service = file_get_contents($servicePath) ?: '';
$layout = file_get_contents($layoutPath) ?: '';
$manifest = file_get_contents($manifestPath) ?: '';
$migration = file_get_contents($migrationPath) ?: '';
$version = file_get_contents($versionPath) ?: '';

preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $service, $declaredMatches);
preg_match_all('/\$this->([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $service, $calledMatches);
$declared = array_values(array_unique($declaredMatches[1] ?? []));
$called = array_values(array_unique($calledMatches[1] ?? []));
$missingInternal = array_values(array_diff($called, $declared));
sort($missingInternal);

$requiredPublic = [
    'dashboard', 'preferences', 'savePreferences', 'dispatchOpened', 'dispatchRecovered',
    'dispatchReminderIfDue', 'dispatchHealthDigest', 'acknowledgeIncident', 'resolveIncident',
    'testConfiguredChannels', 'sendExternalWhatsapp', 'sendExternalEmail', 'externalChannelStatus',
    'unreadCount', 'markAllRead',
];

$publicOk = true;
foreach ($requiredPublic as $method) {
    if (preg_match('/public\s+function\s+' . preg_quote($method, '/') . '\s*\(/', $service) !== 1) {
        $publicOk = false;
        echo "[ERRO] Método público ausente: {$method}\n";
    }
}

$checks = [
    'nenhuma chamada interna órfã' => $missingInternal === [],
    'contrato público completo' => $publicOk,
    'transportes restaurados' => str_contains($service, 'private function sendWhatsapp') && str_contains($service, 'private function sendEmail'),
    'consultas do painel restauradas' => str_contains($service, 'private function activeIncidents') && str_contains($service, 'private function notifications'),
    'digest mantém deduplicação persistente' => str_contains($service, 'healthDigestSentToday') && str_contains($service, 'operations_digest_'),
    'ledger dedicado existe' => str_contains($migration, 'operational_health_digest_deliveries') && str_contains($migration, 'UNIQUE KEY uq_operations_health_digest_user_channel_date'),
    'manifesto inclui migration 100' => str_contains($manifest, "100_operational_health_digest_delivery.sql"),
    'layout possui fallback defensivo' => str_contains($layout, "method_exists(\$operationalAlerts, 'unreadCount')"),
    'versão 36.27.14 registrada' => str_contains($version, 'RS Connect 36.27.14') && str_contains($version, "100_operational_health_digest_delivery.sql"),
    'texto correto de verificações preservado' => str_contains($service, 'Verificações operacionais:'),
    'bloqueio comercial preservado no digest' => str_contains($service, 'Com bloqueio comercial:'),
];

$failures = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

if ($missingInternal !== []) {
    echo '[ERRO] Chamadas internas sem implementação: ' . implode(', ', $missingInternal) . PHP_EOL;
}

echo PHP_EOL;
if ($failures > 0) {
    echo "FALHA - {$failures} verificação(ões) não passaram." . PHP_EOL;
    exit(1);
}

echo "OK - contrato integral do OperationalAlertService v36.27.14 validado." . PHP_EOL;
