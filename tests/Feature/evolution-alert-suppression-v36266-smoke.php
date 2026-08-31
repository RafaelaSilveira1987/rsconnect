<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = (string) file_get_contents($root . '/database/migrations/097_evolution_operational_alert_suppression.sql');
$operations = (string) file_get_contents($root . '/app/Services/OperationsService.php');
$reprocess = (string) file_get_contents($root . '/app/Services/AiReprocessService.php');
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$afterHours = (string) file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php');
$health = (string) file_get_contents($root . '/app/Services/OperationalHealthService.php');
$tenantHealth = (string) file_get_contents($root . '/app/Services/TenantHealthService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/InstanceController.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$view = (string) file_get_contents($root . '/app/Views/instances/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'migration cria os três campos de pausa operacional' => str_contains($migration, 'operational_alerts_enabled')
        && str_contains($migration, 'operational_alerts_paused_at')
        && str_contains($migration, 'operational_alerts_pause_reason'),
    'logout do cliente pausa alertas automaticamente' => str_contains($controller, 'operational_alerts_pause_reason = "client_logout"')
        && str_contains($controller, 'notificações da fila foram pausados'),
    'webhook de logout pausa e reconexão automática retoma somente pausas de logout' => str_contains($webhook, 'operational_alerts_pause_reason = "connection_logout"')
        && str_contains($webhook, 'IN ("client_logout", "connection_logout")'),
    'fila global exclui conexões pausadas dos limites de alerta' => str_contains($operations, 'evolutionAlertsEnabledSql')
        && str_contains($operations, 'mensagem(ns) preservada(s) em conexão(ões) pausada(s)')
        && str_contains($operations, 'syncBlockedEvolutionIncidents'),
    'fila da IA separa pendências pausadas das acionáveis' => str_contains($reprocess, 'pending_actionable_total')
        && str_contains($reprocess, 'pending_paused_total')
        && str_contains($reprocess, 'monitoring_suppressed'),
    'reprocessamento não seleciona conversas de conexões pausadas' => str_contains($automation, 'queueMonitoringSql')
        && str_contains($automation, 'operational_alerts_enabled')
        && str_contains($automation, 'Pausa intencional: preserva a pendência'),
    'recuperação pós-horário exclui conexões pausadas de tentativas e erros' => str_contains($afterHours, 'monitoringCondition')
        && str_contains($afterHours, "'paused' =>")
        && str_contains($afterHours, 'Preserva sem nova tentativa'),
    'central operacional ignora bloqueios de conexões pausadas' => str_contains($health, 'pausada(s) pelo cliente')
        && str_contains($health, 'monitoring_suppressed'),
    'saúde por empresa trata pausa intencional como informação e não incidente' => str_contains($tenantHealth, "'Conexão pausada pelo cliente. Alertas e notificações da fila estão silenciados")
        && str_contains($tenantHealth, 'if ($blockedByEvolution && $alertsPaused)'),
    'tela oferece pausa e retomada manual' => str_contains($view, "'resume_alerts' : 'pause_alerts'")
        && str_contains($view, 'Alertas silenciados'),
    'pacote exige migration 097 e identifica a versão 36.26.6' => str_contains($version, 'RS Connect 36.26.6')
        && str_contains($version, "REQUIRED_MIGRATION = '097_evolution_operational_alert_suppression.sql'"),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - pausa de alertas por conexão WhatsApp validada na v36.26.6.\n";
