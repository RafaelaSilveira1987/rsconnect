<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$worker = (string) file_get_contents($root . '/bin/ai-deferred-reply.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$assert(str_contains($controller, '$shouldAutoResume'), 'webhook identifica mensagens adiadas que precisam de retomada automática');
$assert(str_contains($controller, 'respondJsonAndContinue'), 'webhook responde à Evolution antes do processamento lento');
$assert(str_contains($controller, 'dispatchDeferredAiReply'), 'webhook dispara o worker de retomada');
$assert(str_contains($controller, 'bin/ai-deferred-reply.php'), 'controller referencia o worker CLI interno');
$assert(str_contains($controller, "'deferred_ai_autoresume' => false"), 'resposta do webhook informa o estado da retomada');
$assert(str_contains($automation, 'public function resumeDeferredIncoming'), 'serviço possui retomada da mensagem adiada');
$assert(str_contains($automation, '$latestMessageId !== $messageId'), 'somente a mensagem recebida mais recente pode continuar');
$assert(str_contains($automation, "'bypass_cooldown' => true"), 'worker ignora apenas o intervalo que ele próprio já aguardou');
$assert(str_contains($automation, 'hasOutgoingAfterStoredMessage'), 'retomada impede resposta duplicada');
$assert(str_contains($worker, 'resumeDeferredIncoming'), 'worker CLI executa a retomada segura');
$assert(str_contains($version, 'RS Connect 36.21.2'), 'pacote identifica a versão v36.21.2');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - retomada automática da IA após o tempo de espera validada.\n";
