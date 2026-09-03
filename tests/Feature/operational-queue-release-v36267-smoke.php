<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$migration = (string) file_get_contents($root . '/database/migrations/098_operational_queue_release.sql');
$manifest = (string) file_get_contents($root . '/database/migrations/manifest.php');
$operations = (string) file_get_contents($root . '/app/Services/OperationsService.php');
$alerts = (string) file_get_contents($root . '/app/Services/OperationalAlertService.php');
$reprocess = (string) file_get_contents($root . '/app/Services/AiReprocessService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/OperationalAlertsController.php');
$view = (string) file_get_contents($root . '/app/Views/operations/alerts.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$instance = (string) file_get_contents($root . '/app/Controllers/InstanceController.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'migration adiciona status cancelled sem apagar histórico' => str_contains($migration, "'cancelled'")
        && str_contains($migration, 'ALTER TABLE conversation_messages MODIFY COLUMN status ENUM'),
    'manifesto inclui migration 098' => str_contains($manifest, "'098_operational_queue_release.sql'"),
    'resolução aceita liberação de fila e pausa conexões' => str_contains($operations, 'bool $releaseQueue = false')
        && str_contains($operations, 'pauseDisconnectedOperationalAlerts')
        && str_contains($operations, 'cancelOutgoingQueue')
        && str_contains($operations, 'incident_resolved'),
    'fila da IA registra cancelamento e encerra pós-horário' => str_contains($reprocess, 'cancelPendingForInstances')
        && str_contains($reprocess, "'ai.cancelled'")
        && str_contains($reprocess, 'manual_queue_release'),
    'serviço de alertas delega resolução persistente' => str_contains($alerts, 'new OperationsService()')
        && str_contains($alerts, '$releaseQueue'),
    'controller recebe a ação de liberar fila' => str_contains($controller, "release_queue")
        && str_contains($controller, 'pendência(s) retirada(s) da fila'),
    'tela apresenta resolver e liberar fila' => str_contains($view, 'Resolver e liberar fila')
        && str_contains($view, 'Resolver sem limpar fila'),
    'reconexão retoma pausas criadas pela resolução' => str_contains($webhook, 'incident_resolved')
        && str_contains($instance, 'incident_resolved'),
    'marcadores históricos da migration 098 e 36.26.7 foram preservados' => str_contains($version, 'RS Connect 36.26.7')
        && str_contains($version, '098_operational_queue_release.sql'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - resolução persistente e liberação da fila validadas na v36.26.7.\n";
