<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$runner = (string) file_get_contents($root . '/bin/evolution-e2e.php');
$wrapper = (string) file_get_contents($root . '/scripts/run-evolution-e2e.sh');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$evolution = (string) file_get_contents($root . '/app/Services/EvolutionService.php');

$assert(str_contains($runner, 'Evolution/WhatsApp E2E'), 'Runner deve identificar a homologação Evolution/WhatsApp.');
$assert(str_contains($runner, 'MESSAGES_UPSERT'), 'Runner deve validar MESSAGES_UPSERT.');
$assert(str_contains($runner, 'connectionState()'), 'Runner deve consultar o estado remoto da Evolution.');
$assert(str_contains($runner, 'findWebhook()'), 'Runner deve validar o webhook remoto da Evolution.');
$assert(str_contains($runner, 'publicWebhookProbe'), 'Runner deve testar a rota pública autenticada.');
$assert(str_contains($runner, 'latestIncomingAfter'), 'Runner deve aguardar uma mensagem real nova.');
$assert(str_contains($runner, 'latestOutgoingAfterIncoming'), 'Runner deve observar a resposta após a mensagem recebida.');
$assert(str_contains($runner, 'countAiRepliesAfterIncoming'), 'Runner deve detectar resposta duplicada.');
$assert(str_contains($runner, 'storage/logs/e2e'), 'Runner deve registrar evidências persistentes.');
$assert(str_contains($runner, '--instance-id'), 'Runner deve permitir seleção segura da instância.');
$assert(str_contains($runner, '--no-wait'), 'Runner deve permitir somente o pré-teste.');
$assert(str_contains($wrapper, 'docker exec'), 'Wrapper deve funcionar a partir do host Docker/EasyPanel.');
$assert(str_contains($wrapper, '/var/www/html/bin/evolution-e2e.php'), 'Wrapper deve executar o runner dentro do container.');
$assert(str_contains($webhook, 'conversation_messages') && str_contains($webhook, 'evolution_message_id'), 'Webhook deve persistir e deduplicar mensagens recebidas.');
$assert(str_contains($evolution, "'/webhook/find/'") && str_contains($evolution, "'/instance/connectionState/'"), 'EvolutionService deve suportar consultas usadas pela homologação.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - homologação assistida Evolution/WhatsApp E2E validada.\n";
