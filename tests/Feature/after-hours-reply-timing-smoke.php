<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Core/Env.php';
require_once __DIR__ . '/../../app/Core/Clock.php';
require_once __DIR__ . '/../../app/Services/AiReplyTimingService.php';
require_once __DIR__ . '/../../app/Services/AfterHoursAcknowledgementPolicyService.php';

use App\Services\AiReplyTimingService;
use App\Services\AfterHoursAcknowledgementPolicyService;

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$ack = new AfterHoursAcknowledgementPolicyService();
$tz = 'America/Sao_Paulo';
$assert($ack->shouldSend(null, '2026-07-25 12:56:00', $tz), 'primeira mensagem fora do horario deve receber aviso');
$assert(!$ack->shouldSend('2026-07-25 12:56:05', '2026-07-25 19:07:00', $tz), 'mesmo dia nao deve repetir aviso');
$assert($ack->shouldSend('2026-07-25 12:56:05', '2026-07-26 19:07:00', $tz), 'novo dia deve permitir novo aviso de ausencia');

$timing = new AiReplyTimingService();
$now = new DateTimeImmutable('2026-07-27 10:00:00');
$assert($timing->remainingSeconds(60, '2026-07-27 09:59:50', null, $now) === 50, 'primeira interacao deve aguardar 60s apos ultima mensagem');
$assert($timing->remainingSeconds(60, '2026-07-27 09:59:30', '2026-07-27 09:59:40', $now) === 40, 'ultima resposta de IA tambem protege contra resposta seguida');
$assert($timing->remainingSeconds(60, '2026-07-27 09:58:30', '2026-07-27 09:58:40', $now) === 0, 'depois de 60s deve liberar');
$assert($timing->remainingSeconds(60, '2026-07-27 09:59:55', '2026-07-27 09:58:00', $now) === 55, 'nova mensagem durante espera deve reiniciar o relogio');


// Regressão 36.36.11: sent_at é UTC no banco, enquanto o PHP roda no fuso da empresa.
// 11:32 UTC = 08:32 em São Paulo; às 09:06 o cooldown já expirou há muito tempo.
$openingNow = new DateTimeImmutable('2026-09-24 09:06:00', new DateTimeZone('America/Sao_Paulo'));
$assert(
    $timing->remainingSeconds(60, '2026-09-24 11:32:00', null, $openingNow) === 0,
    'timestamp UTC do banco nao pode manter cooldown ativo depois da abertura local'
);

$automationSource = file_get_contents(__DIR__ . '/../../app/Services/AiAutomationService.php') ?: '';
$afterHoursPos = strpos($automationSource, '$operatingPolicy = (new AgentOperatingPolicyService())->status($agent);');
$cooldownPos = strpos($automationSource, '$cooldownSeconds =');
$assert($afterHoursPos !== false && $cooldownPos !== false && $afterHoursPos < $cooldownPos, 'fora do horario deve ser validado antes do tempo de espera da IA');

$webhookSource = file_get_contents(__DIR__ . '/../../app/Controllers/EvolutionWebhookController.php') ?: '';
$assert(str_contains($webhookSource, 'reply_wait_deferred'), 'webhook deve adiar agenda enquanto a janela de interacao estiver ativa');
$assert(str_contains($automationSource, 'processSchedulingDuringReprocess'), 'fila deve reavaliar agenda depois do tempo de espera');

$recoverySource = file_get_contents(__DIR__ . '/../../app/Services/AiAfterHoursRecoveryService.php') ?: '';
$assert(str_contains($recoverySource, "'bypass_cooldown' => false"), 'recuperacao automatica nao pode ignorar tempo de espera');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - aviso diario fora do horario e espera de interacao validados.\n";
