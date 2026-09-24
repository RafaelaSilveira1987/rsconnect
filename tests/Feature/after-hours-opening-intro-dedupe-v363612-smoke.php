<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\FirstAutomatedReplyService;

$root = dirname(__DIR__, 2);
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$conversationAutomation = (string) file_get_contents($root . '/app/Services/ConversationAutomationMessageService.php');
$recovery = (string) file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$agent = [
    'name' => 'Rafa, Assistente da psicóloga Mariana Bernardes',
    'ai_greeting_mode' => 'all_contacts',
    'ai_greeting_reply' => 'Olá! Aqui é a Rafa, assistente do consultório da psicóloga Mariana Bernardes.',
    'after_hours_message' => 'Olá! Neste momento o consultório está fora do horário de atendimento.',
];

$composed = FirstAutomatedReplyService::compose('O atendimento seria para você mesmo?', $agent, false);
$check(str_contains($composed, 'Aqui é a Rafa'), 'primeira resposta conversacional mantém a apresentação configurada');
$check(substr_count($composed, 'Rafa') === 1, 'apresentação aparece apenas uma vez');

$check(
    str_contains($conversationAutomation, 'TRIM(content) <> :after_hours_message')
    && str_contains($conversationAutomation, 'O aviso de ausência fora do horário é operacional'),
    'aviso de ausência não conta como resposta conversacional anterior'
);

$check(
    str_contains($automation, 'hasPriorConversationalOutgoing')
    && str_contains($automation, 'if ($afterHoursRecovery)')
    && str_contains($automation, 'FirstAutomatedReplyService::compose'),
    'retomada gerada pela IA também passa pela apresentação única'
);

$check(
    str_contains($automation, 'equivalentOutgoingAfterLastIncoming')
    && str_contains($automation, '_duplicate_suppressed'),
    'envio final possui proteção contra resposta duplicada sem nova entrada'
);

$check(
    str_contains($recovery, 'Considera somente eventos que encerram ou adiam a tentativa de recuperação')
    && str_contains($recovery, "'calendar.recovery.handled'")
    && str_contains($recovery, "'ai.replied'"),
    'monitor pós-horário ignora logs auxiliares ao decidir se já recuperou a conversa'
);

$check(str_contains($version, 'RS Connect 36.36.12'), 'pacote identifica a correção 36.36.12');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - apresentação e deduplicação na retomada pós-horário validadas.\n";
