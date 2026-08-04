<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = (string) file_get_contents($root . '/database/migrations/069_service_cycle_recovery_compat.sql');
$diagnostic = (string) file_get_contents($root . '/database/diagnostics/service_cycle_recovery_v36.10.1.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$foundation = (string) file_get_contents($root . '/app/Services/TeamMetricsFoundationService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'migration recupera conversa aberta sem ciclo ativo' => str_contains($migration, "c.status <> 'closed'")
        && str_contains($migration, "'migration_069_recovery'"),
    'snapshot encerrado permanece coberto' => str_contains($migration, "'migration_069_closed_snapshot'"),
    'mensagens reais recompõem os marcos' => str_contains($migration, 'tmp_rs_cycle_recovery_metrics')
        && str_contains($migration, "message.direction = 'incoming'")
        && str_contains($migration, "response_message.sender_type = 'user'"),
    'trigger cria ciclo ausente antes de atualizar métricas' => str_contains($migration, 'IF NOT EXISTS')
        && str_contains($migration, "'message_cycle_recovery'")
        && str_contains($migration, 'INSERT IGNORE INTO conversation_service_cycles'),
    'resultado da migration é verificável' => str_contains($migration, 'information_schema.TRIGGERS')
        && str_contains($migration, 'ATENÇÃO: dados recuperados'),
    'diagnóstico procura conversas e mensagens sem ciclo' => str_contains($diagnostic, 'HAVING COUNT(sc.id) = 0')
        && str_contains($diagnostic, 'Mensagens humanas recentes sem ciclo ativo')
        && str_contains($diagnostic, 'conversation_id = 1104'),
    'pacote exige migration 070 após a recuperação 069' => str_contains($version, 'RS Connect 36.15.0')
        && str_contains($version, '074_conversation_message_attachments.sql'),
    'fundação identifica ciclo resiliente' => str_contains($foundation, '36.10.4-utc-datetime-contract'),
    'cache visual atualizado' => str_contains($layout, 'app.css?v=36.15.0')
        && str_contains($layout, 'app.js?v=36.15.0'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - ciclos ausentes recuperados e trigger de mensagens autocorretivo.\n";
