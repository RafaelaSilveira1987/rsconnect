<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/070_conversation_cycle_status_sync_compat.sql');
$diagnostic = (string) file_get_contents($root . '/database/diagnostics/conversation_cycle_status_sync_v36.10.3.sql');
$controller = (string) file_get_contents($root . '/app/Controllers/ConversationController.php');
$ownership = (string) file_get_contents($root . '/app/Services/ConversationOwnershipService.php');
$cycleService = (string) file_get_contents($root . '/app/Services/ConversationCycleService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'migration fecha ciclo ativo de conversa encerrada' => str_contains($migration, "conversation.status = 'closed'")
        && str_contains($migration, "cycle.cycle_status = 'active'")
        && str_contains($migration, "cycle.cycle_status = 'closed'"),
    'migration recupera conversa aberta sem ciclo' => str_contains($migration, "conversation.status <> 'closed'")
        && str_contains($migration, "'migration_070_status_sync'"),
    'trigger sincroniza mesmo em atualizações subsequentes' => str_contains($migration, "IF NEW.status = 'closed'")
        && str_contains($migration, 'active_cycle_count > 0')
        && str_contains($migration, "'status_close_recovery'"),
    'ator do encerramento não depende apenas do responsável atual' => str_contains($migration, 'NEW.status_changed_by_user_id')
        && str_contains($migration, 'OLD.assigned_user_id'),
    'backend fecha ciclo antes de liberar responsável' => str_contains($controller, 'closeActiveCycle')
        && strpos($controller, 'closeActiveCycle') < strpos($controller, 'releaseWhenClosed'),
    'reabertura do backend garante ciclo ativo' => str_contains($ownership, 'application_conversation_reopened')
        && str_contains($ownership, 'ensureActiveCycle'),
    'serviço tolera instalação antes da migration' => str_contains($cycleService, 'cycleTableExists')
        && str_contains($cycleService, 'information_schema.TABLES'),
    'diagnóstico detecta divergências' => str_contains($diagnostic, "conversation.status = 'closed' AND cycle.id IS NOT NULL")
        && str_contains($diagnostic, 'conversation.id = 1104'),
    'pacote exige migration 070' => str_contains($version, 'RS Connect 36.10.5')
        && str_contains($version, '071_utc_datetime_contract_compat.sql'),
    'cache atualizado' => str_contains($layout, 'app.css?v=36.10.5')
        && str_contains($layout, 'app.js?v=36.10.5'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - status da conversa e ciclo operacional sincronizados com defesa em profundidade.\n";
