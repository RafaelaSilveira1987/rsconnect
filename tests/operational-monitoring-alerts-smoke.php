<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$service = $read('app/Services/OperationsService.php');
$alerts = $read('app/Services/OperationalAlertService.php');
$controller = $read('app/Controllers/OperationalAlertsController.php');
$view = $read('app/Views/operations/alerts.php');
$routes = $read('routes/web.php');
$migration = $read('database/migrations/073_operational_monitoring_alert_delivery.sql');
$diagnostic = $read('database/diagnostics/operational_monitoring_v36.12.0.sql');
$version = $read('app/Services/AppVersionService.php');
$health = $read('app/Services/OperationalHealthService.php');
$env = $read('.env.vps.example');
$cli = $read('bin/operations-monitor.php');
$layout = $read('app/Views/layouts/app.php');
$communications = $read('app/Services/ClientCommunicationService.php');
$communicationView = $read('app/Views/communications/index.php');

$checks = [
    'migration cria histórico das execuções' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS operational_monitor_runs'),
    'migration adiciona reconhecimento do incidente' => str_contains($migration, 'acknowledged_at')
        && str_contains($migration, 'acknowledged_by')
        && str_contains($migration, 'acknowledgement_note'),
    'migration permite lembretes recorrentes sem duplicidade' => str_contains($migration, 'delivery_key')
        && str_contains($migration, 'uq_operational_alert_delivery_v2'),
    'monitor mede disco' => str_contains($service, "recordCheck('disk'")
        && str_contains($service, 'private function checkDisk()')
        && str_contains($service, 'OPERATIONS_DISK_WARNING_PERCENT'),
    'monitor mede fila de mensagens' => str_contains($service, "recordCheck('message_queue'")
        && str_contains($service, 'private function checkMessageQueue()')
        && str_contains($service, 'OPERATIONS_MESSAGE_QUEUE_CRITICAL'),
    'n8n considera falhas consecutivas' => str_contains($service, 'consecutiveN8nErrors')
        && str_contains($service, 'OPERATIONS_N8N_CONSECUTIVE_ERRORS_CRITICAL'),
    'webhook possui janela de inatividade configurável' => str_contains($service, 'OPERATIONS_WEBHOOK_INACTIVITY_HOURS'),
    'execução automática fica auditada' => str_contains($service, 'startMonitorRun')
        && str_contains($service, 'finishMonitorRun')
        && str_contains($service, 'incidents_opened = :incidents_opened')
        && str_contains($service, 'incidents_recovered = :incidents_recovered')
        && str_contains($cli, "runChecks(true, 'cli')"),
    'ausência de evidência não encerra incidente' => str_contains($service, 'if ($status === \'unknown\')')
        && str_contains($service, 'não deve encerrar o incidente'),
    'alerta entrega por Evolution administrativa' => str_contains($alerts, 'OPERATIONS_ALERT_EVOLUTION_INSTANCE')
        && str_contains($alerts, 'new EvolutionService(')
        && str_contains($alerts, 'sendText($destination'),
    'alerta entrega e-mail por webhook ou mail' => str_contains($alerts, 'OPERATIONS_ALERT_EMAIL_WEBHOOK_URL')
        && str_contains($alerts, 'OPERATIONS_ALERT_EMAIL_NATIVE')
        && str_contains($alerts, 'postJson('),
    'comunicados externos usam os mesmos transportadores auditados' => str_contains($alerts, 'sendExternalWhatsapp')
        && str_contains($alerts, 'sendExternalEmail')
        && str_contains($communications, 'deliverExternalChannels')
        && str_contains($communications, 'updateExternalDelivery')
        && str_contains($communications, "'queued'")
        && str_contains($migration, 'whatsapp_provider_message_id')
        && str_contains($migration, 'email_provider_message_id')
        && str_contains($communicationView, 'enviado(s)')
        && str_contains($communicationView, 'aguardando configuração'),
    'ciclo do incidente permite reconhecer e resolver' => str_contains($alerts, 'acknowledgeIncident')
        && str_contains($alerts, 'resolveIncident')
        && str_contains($controller, 'public function acknowledge')
        && str_contains($controller, 'public function resolve'),
    'rotas novas estão protegidas' => str_contains($routes, "'/operacao-alertas/test'")
        && str_contains($routes, "'/operacao-alertas/acknowledge'")
        && str_contains($routes, "'/operacao-alertas/resolve'")
        && substr_count($routes, "['auth', 'super_admin', 'csrf']") >= 3,
    'tela apresenta prontidão, incidentes e entregas' => str_contains($view, 'Prontidão de entrega')
        && str_contains($view, 'Incidentes ativos')
        && str_contains($view, 'Status dos canais')
        && str_contains($view, 'Avisar cliente'),
    'painel de saúde conhece disco e fila' => str_contains($health, "'disk' => [")
        && str_contains($health, "'message_queue' => ["),
    'ambiente documenta limiares e canais' => str_contains($env, 'OPERATIONS_DISK_CRITICAL_PERCENT')
        && str_contains($env, 'OPERATIONS_ALERT_EVOLUTION_INSTANCE')
        && str_contains($env, 'OPERATIONS_ALERT_EMAIL_WEBHOOK_URL'),
    'diagnóstico cobre ciclo completo' => str_contains($diagnostic, 'operational_monitor_runs')
        && str_contains($diagnostic, 'operational_alert_deliveries')
        && str_contains($diagnostic, 'acknowledged_at'),
    'versão e migration atualizadas' => str_contains($version, 'RS Connect 36.12.0')
        && str_contains($version, '073_operational_monitoring_alert_delivery.sql'),
    'cache dos assets atualizado' => str_contains($layout, 'app.css?v=36.12.0')
        && str_contains($layout, 'app.js?v=36.12.0'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - monitoramento, ciclo de incidentes e canais operacionais validados na v36.12.0.\n";
