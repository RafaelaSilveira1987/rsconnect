<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => is_file($root . '/' . $path) ? (string) file_get_contents($root . '/' . $path) : '';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$migration = $read('database/migrations/114_evolution_reconciliation_observability.sql');
$manifest = $read('database/migrations/manifest.php');
$service = $read('app/Services/EvolutionReconciliationService.php');
$instanceController = $read('app/Controllers/InstanceController.php');
$operations = $read('app/Services/OperationsService.php');
$webhook = $read('app/Controllers/EvolutionWebhookController.php');
$security = $read('app/Services/WebhookSecurityService.php');
$view = $read('app/Views/instances/index.php');
$version = $read('app/Services/AppVersionService.php');
$env = $read('.env.example');

$check(str_contains($migration, 'remote_connection_state') && str_contains($migration, 'reconciliation_status') && str_contains($migration, 'evolution_reconciliation_runs'), 'Migration 114 não cria estado remoto/ledger de reconciliação.');
$check(str_contains($manifest, "['sequence' => 121, 'file' => '114_evolution_reconciliation_observability.sql']"), 'Migration 114 não está no manifesto como sequência 121.');
$check(str_contains($service, 'final class EvolutionReconciliationService') && str_contains($service, 'function reconcile') && str_contains($service, 'local_state_updated'), 'Serviço de reconciliação está incompleto.');
$check(str_contains($service, 'EvolutionInstanceSafetyService::persistObservedIdentity') && str_contains($service, 'identity_mismatch'), 'Reconciliação não preserva a trava de identidade do número.');
$check(str_contains($instanceController, "'reconcile'") && str_contains($instanceController, "new EvolutionReconciliationService()"), 'Ação manual Reconciliar agora não foi ligada ao controller.');
$check(str_contains($operations, 'function reconcileEvolutionInstances') && str_contains($operations, "'monitor'"), 'Monitor operacional não executa reconciliação periódica.');
$check(str_contains($view, 'Estado no RS Connect') && str_contains($view, 'Estado observado na Evolution') && str_contains($view, 'Reconciliar agora'), 'Painel das conexões não mostra diagnóstico/reconciliação.');
$check(str_contains($view, 'Reaplicar webhook'), 'Ação de sincronização de webhook não está diferenciada da reconciliação de estado.');
$check(str_contains($webhook, 'reserveWebhookEvent') && str_contains($webhook, "'duplicate' => true"), 'Webhook Evolution perdeu a proteção idempotente existente.');
$check(str_contains($webhook, "\$event . '|' . \$instance . '|' . \$eventId"), 'event_id da Evolution não está namespaced por evento/instância.');
$check(str_contains($security, 'FOR UPDATE') && str_contains($security, 'duplicate_count = duplicate_count + 1'), 'Ledger de idempotência não mantém bloqueio transacional/contagem de duplicados.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.33.0 — Production Readiness: Evolution Reliability'") && str_contains($version, "REQUIRED_MIGRATION = '114_evolution_reconciliation_observability.sql'"), 'Versionamento 36.33.0 incorreto.');
$check(str_contains($env, 'EVOLUTION_RECONCILIATION_TIMEOUT=15'), 'Timeout de reconciliação não foi documentado no .env.example.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL evolution-reliability-v36330-smoke\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK evolution-reliability-v36330-smoke\n";
