<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/ConversationAutomationMessageService.php');
$schema = (string) file_get_contents($root . '/database/schema.sql');
$triage = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');

$checks = [
    'schema usa attendance_mode' => str_contains($schema, 'attendance_mode ENUM'),
    'mensagem automática consulta attendance_mode' => str_contains($service, 'SELECT attendance_mode, status FROM conversations'),
    'mensagem automática não consulta coluna mode inexistente' => !str_contains($service, 'SELECT mode, status FROM conversations'),
    'regra de agenda continua usando envio configurado' => str_contains($triage, "'agent.policy.calendar_restricted'"),
    'regra de agenda mantém conversa ativa' => str_contains($triage, "'conversation_continues'"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "\nFalhas: " . implode(', ', $failed) . "\n");
    exit(1);
}

echo "\nOK - mensagens de políticas usam attendance_mode e não deixam a conversa sem resposta.\n";
