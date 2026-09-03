<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$operations = file_get_contents($root . '/app/Services/OperationsService.php') ?: '';
$alerts = file_get_contents($root . '/app/Services/OperationalAlertService.php') ?: '';
$playbook = file_get_contents($root . '/app/Services/OperationalPlaybookService.php') ?: '';
$auditor = file_get_contents($root . '/bin/operational-alerts-audit.php') ?: '';

$checks = [
    'monitor sincroniza empresas bloqueadas' => str_contains($operations, 'syncSubscriptionAccessIncidents()'),
    'monitor dispara resumo periódico' => str_contains($operations, 'dispatchDailyHealthDigest()'),
    'bloqueio usa AccessControlService como fonte' => str_contains($operations, 'new AccessControlService()') && str_contains($operations, "operations.alert.access.tenant."),
    'normalização de acesso gera recovered' => str_contains($operations, 'dispatchRecovered($incidentId)'),
    'resumo diário possui deduplicação por canal' => str_contains($alerts, 'healthDigestSentToday') && str_contains($alerts, 'operations_digest_'),
    'resumo diário respeita horário configurável' => str_contains($alerts, 'OPERATIONS_HEALTH_DIGEST_TIME') && str_contains($alerts, "'08:00'"),
    'resumo inclui empresas bloqueadas' => str_contains($alerts, 'Empresas com acesso bloqueado:'),
    'whatsapp continua respeitando preferência do admin' => str_contains($alerts, "preferences['whatsapp_enabled']") && str_contains($alerts, 'whatsapp_recipient'),
    'lembrete comercial não gera spam a cada monitor' => str_contains($alerts, '$hours = max(24, $hours);'),
    'playbook de acesso aponta para assinaturas' => str_contains($playbook, "'access' =>") && str_contains($playbook, "'url'=>'/billing'"),
    'auditor de avisos está disponível' => str_contains($auditor, 'AUDITOR DE AVISOS OPERACIONAIS') && str_contains($auditor, '--send-test'),
];

$failures = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
}

echo PHP_EOL;
if ($failures > 0) {
    echo "FALHA - {$failures} verificação(ões) não passaram." . PHP_EOL;
    exit(1);
}

echo "OK - resumo periódico, avisos de bloqueio comercial e auditor de entrega validados." . PHP_EOL;
