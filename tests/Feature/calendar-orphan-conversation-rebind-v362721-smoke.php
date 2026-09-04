<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$migrationManifest = (string) file_get_contents($root . '/database/migrations/manifest.php');

$checks = [
    'pré-agendamento órfão é religado à conversa ativa' =>
        str_contains($pre, 'rebindOrphanPendingPreScheduleConversation')
        && str_contains($pre, 'SET conversation_id = :conversation_id')
        && str_contains($pre, 'currentConversation'),
    'religação exige conversa atual válida do mesmo tenant e contato' =>
        str_contains($pre, 'SELECT id, contact_id FROM conversations')
        && str_contains($pre, "currentConversation['contact_id']")
        && str_contains($pre, '!== $contactId'),
    'vínculo com outra conversa válida não é roubado' =>
        str_contains($pre, 'Só religa automaticamente quando o vínculo anterior ficou órfão')
        && str_contains($pre, 'SELECT COUNT(*) FROM conversations WHERE id = :id AND tenant_id = :tenant_id'),
    'evento de mudança de preferência usa conversa atual válida' =>
        str_contains($pre, "'conversation_id' => \$conversationId")
        && str_contains($pre, 'calendar.preference_changed'),
    'evento de atualização usa conversa atual em vez de appointment órfão' =>
        str_contains($pre, 'calendar.pre_schedule_updated')
        && !str_contains($pre, "'conversation_id' => (int) (\$updatedAppointment['conversation_id'] ?? 0)"),
    'update defensivo religa appointment nulo antes de continuar' =>
        str_contains($pre, 'conversation_id = CASE WHEN conversation_id IS NULL THEN :conversation_id ELSE conversation_id END'),
    'versão 36.27.21 preserva migration 101' =>
        str_contains($version, 'RS Connect 36.27.21')
        && str_contains($version, "REQUIRED_MIGRATION = '101_agent_scheduling_specialist_routing.sql'")
        && str_contains($migrationManifest, '101_agent_scheduling_specialist_routing.sql'),
];

$failures = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - pré-agendamento órfão religado com segurança na v36.27.21.\n";
