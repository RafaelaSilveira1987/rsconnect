<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/067_operational_history_metrics_compat.sql');
$cycleMigration = (string) file_get_contents($root . '/database/migrations/068_conversation_service_cycles_compat.sql');
$calendar = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$conversations = (string) file_get_contents($root . '/app/Controllers/ConversationController.php');
$queue = (string) file_get_contents($root . '/app/Controllers/QueueController.php');
$foundation = (string) file_get_contents($root . '/app/Services/TeamMetricsFoundationService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'histórico de atribuições criado' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS conversation_assignment_history'),
    'histórico de status da conversa criado' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS conversation_status_history'),
    'histórico da agenda criado' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS calendar_appointment_history'),
    'primeira entrada e primeira resposta são registradas' => str_contains($migration, 'first_incoming_at')
        && str_contains($migration, 'first_response_at')
        && str_contains($migration, 'first_response_user_id')
        && str_contains($migration, 'trg_rs_messages_after_insert_metrics'),
    'reabertura reinicia campos da conversa' => str_contains($migration, 'SET NEW.first_incoming_at = NULL')
        && str_contains($migration, 'SET NEW.first_response_at = NULL'),
    'reabertura preserva ciclo anterior' => str_contains($cycleMigration, 'conversation_service_cycles')
        && str_contains($cycleMigration, 'conversation_reopened')
        && str_contains($cycleMigration, 'cycle_status'),
    'atribuições são capturadas por trigger' => str_contains($migration, 'trg_rs_conversations_after_update_history')
        && str_contains($migration, "ELSE 'transfer'"),
    'mudanças da agenda são capturadas por trigger' => str_contains($migration, 'trg_rs_appointments_after_update_history')
        && str_contains($migration, "'owner_changed'")
        && str_contains($migration, "'rescheduled'"),
    'não comparecimento possui marco próprio' => str_contains($migration, 'no_show_at'),
    'permissões próprias e da equipe foram criadas' => str_contains($migration, 'reports.team.view_own')
        && str_contains($migration, 'reports.team.view_all'),
    'status manual da conversa registra ator' => str_contains($conversations, 'status_changed_by_user_id = :status_changed_by_user_id'),
    'mudança manual do profissional da agenda registra ator' => str_contains($calendar, 'owner_changed_by_user_id = :owner_changed_by_user_id'),
    'mudança manual do status da agenda registra ator' => str_contains($calendar, 'status_changed_by_user_id = :status_changed_by_user_id'),
    'fila registra origem e ator da atribuição' => str_contains($queue, 'assignment_source = IF')
        && str_contains($queue, 'assignment_updated_by_user_id = :assignment_updated_by_user_id'),
    'serviço aplica escopo own/all' => str_contains($foundation, "'mode' => 'own'")
        && str_contains($foundation, "'mode' => 'all'")
        && str_contains($foundation, 'reports.team.view_all'),
    'versão e migrations atualizadas' => str_contains($version, 'RS Connect 36.15.0')
        && str_contains($version, '074_conversation_message_attachments.sql'),
    'cache atualizado' => str_contains($layout, 'app.css?v=36.15.0')
        && str_contains($layout, 'app.js?v=36.15.0'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - base histórica operacional, métricas e escopo de relatórios por profissional preparados.\n";
