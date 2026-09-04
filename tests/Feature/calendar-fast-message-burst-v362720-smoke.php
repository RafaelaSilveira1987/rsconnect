<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$ai = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'fila diferida continua processando somente o último disparo' =>
        str_contains($ai, 'if ($latestMessageId !== $messageId)')
        && str_contains($ai, "'status' => 'superseded'"),
    'agenda reconstrói bloco de mensagens consecutivas antes da última resposta' =>
        str_contains($ai, 'calendarBurstForMessage')
        && str_contains($ai, 'após a última saída do sistema')
        && str_contains($ai, 'direction = "incoming"'),
    'bloco da agenda é limitado ao trecho posterior à última saída' =>
        str_contains($ai, 'AND direction = "outgoing"')
        && str_contains($ai, 'AND id < :message_id')
        && str_contains($ai, 'AND id > :after_id'),
    'mensagens online e quinta às 14h podem ser entregues juntas à agenda' =>
        str_contains($ai, '$calendarContent = (string) ($calendarBurst[\'content\'] ?? $content);')
        && str_contains($ai, 'handleIncomingSelection(')
        && str_contains($ai, '$calendarContent,')
        && str_contains($pre, "return 'Online';")
        && str_contains($pre, 'preferred_time'),
    'telemetria registra ids e quantidade do bloco reconstruído' =>
        str_contains($ai, "calendar_burst_message_ids")
        && str_contains($ai, "calendar_burst_count"),
    'versão 36.27.20 preserva migration 101' =>
        (str_contains($version, 'RS Connect 36.27.21') || str_contains($version, 'RS Connect 36.27.20'))
        && str_contains($version, "REQUIRED_MIGRATION = '101_agent_scheduling_specialist_routing.sql'"),
    'cache de front-end atualizado' =>
        str_contains($layout, 'app.css?v=36.27.20')
        && str_contains($layout, 'app.js?v=36.27.20'),
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

echo "\nOK - mensagens rápidas preservadas no fluxo de agenda v36.27.20.\n";
