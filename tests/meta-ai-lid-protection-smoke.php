<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Core/Autoloader.php';
App\Core\Autoloader::register(dirname(__DIR__) . '/app');

use App\Controllers\EvolutionWebhookController;
use App\Services\AiAutomationService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$webhook = new EvolutionWebhookController();
$preferred = new ReflectionMethod($webhook, 'preferredRemoteJid');
$preferred->setAccessible(true);
$isKnownSystem = new ReflectionMethod($webhook, 'isKnownSystemContact');
$isKnownSystem->setAccessible(true);
$isLid = new ReflectionMethod($webhook, 'isLidRemoteJid');
$isLid->setAccessible(true);

$resolved = $preferred->invoke($webhook, '75565788836063@lid', '', [
    'key' => ['remoteJid' => '75565788836063@lid'],
    'messageContextInfo' => ['senderPn' => '5575999999999@s.whatsapp.net'],
]);
$assert($resolved === '5575999999999@s.whatsapp.net', 'LID deve usar senderPn telefônico quando disponível.');

$unresolved = $preferred->invoke($webhook, '75565788836063@lid', '', [
    'key' => ['remoteJid' => '75565788836063@lid'],
]);
$assert($unresolved === '75565788836063@lid', 'LID sem telefone alternativo deve permanecer identificado como LID.');
$assert($isLid->invoke($webhook, $unresolved) === true, 'Identificador @lid deve ser reconhecido.');
$assert($isKnownSystem->invoke($webhook, '13135550002@s.whatsapp.net', 'Meta AI', []) === true, 'Meta AI deve ser classificada como contato de sistema.');

$automation = new AiAutomationService();
$recipientReason = new ReflectionMethod($automation, 'nonReplyableRecipientReason');
$recipientReason->setAccessible(true);
$providerError = new ReflectionMethod($automation, 'isNonRetryableRecipientError');
$providerError->setAccessible(true);

$reason = $recipientReason->invoke($automation, [
    'remote_jid' => '75565788836063@lid',
    'phone' => '75565788836063',
    'name' => null,
]);
$assert(is_string($reason) && str_contains($reason, 'LID'), 'Conversa histórica com @lid deve ser encerrada sem reprocessamento.');

$existsFalse = 'Evolution sendText HTTP 400: Bad Request — {"response":{"message":[{"jid":"75565788836063@s.whatsapp.net","exists":false}]}}';
$assert($providerError->invoke($automation, $existsFalse) === true, 'exists:false deve ser classificado como falha não repetível.');
$assert($providerError->invoke($automation, 'Evolution HTTP 500: timeout') === false, 'Falha temporária não deve ser classificada como destinatário inválido.');

$serviceSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/AiAutomationService.php');
$webhookSource = (string) file_get_contents(dirname(__DIR__) . '/app/Controllers/EvolutionWebhookController.php');
$versionSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/AppVersionService.php');
$afterHoursSource = (string) file_get_contents(dirname(__DIR__) . '/app/Services/AiAfterHoursRecoveryService.php');
$assert(str_contains($serviceSource, "'ai.recipient.unavailable'"), 'Serviço deve registrar evento não repetível do destinatário.');
$assert(str_contains($webhookSource, "'ignored' => 'lid_without_phone'"), 'Webhook deve ignorar LID sem telefone resolvido.');
$assert(str_contains($versionSource, 'RS Connect 36.15.1-r4'), 'Versão r4 deve estar publicada.');
$assert(str_contains($afterHoursSource, "\$event === 'ai.recipient.unavailable'") && str_contains($afterHoursSource, "'cancelled'"), 'Recuperação pós-horário deve encerrar destinatário não respondível.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - Meta AI, LID e exists:false protegidos contra filas repetitivas.\n";
