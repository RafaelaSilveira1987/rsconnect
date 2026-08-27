<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require_once dirname(__DIR__, 2) . '/app/Services/AutomationWebhookService.php';

use App\Services\AutomationWebhookService;

$root = dirname(__DIR__, 2);
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$afterHours = (string) file_get_contents($root . '/app/Services/AiAfterHoursRecoveryService.php');
$agentController = (string) file_get_contents($root . '/app/Controllers/AgentController.php');
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$afterHoursPosition = strpos($automation, 'if ($afterHoursRecovery && $storedMessageId > 0)');
$quotaPosition = strpos($automation, '$quota = $usageService->reserveAutoReply');
$assert($afterHoursPosition !== false, 'Recuperação pós-horário deve reentrar na Agenda antes da IA.');
$assert($quotaPosition !== false && $afterHoursPosition < $quotaPosition, 'Agenda pós-horário deve ser processada antes de reservar/chamar IA.');
$assert(str_contains($automation, "'calendar.recovery.handled'"), 'Retomada de Agenda deve registrar conclusão própria.');
$assert(str_contains($automation, "'availability_request_result'"), 'Resultado do request de disponibilidade deve ficar observável.');
$assert(str_contains($afterHours, 'pendingConversationContent'), 'Recuperação deve reunir mensagens fragmentadas da janela fechada.');
$assert(str_contains($afterHours, "calendar.recovery.handled"), 'Fila pós-horário deve reconhecer Agenda como recuperação concluída.');
$assert(str_contains($availability, 'calendar.availability_missing_request_recovered'), 'Fila rápida deve reparar pré-agendamento sem request de disponibilidade.');
$assert(str_contains($availability, "'conversation_recovery'"), 'Request reparado deve ter origem conversacional auditável.');

$service = new AutomationWebhookService();
$method = new ReflectionMethod($service, 'explicitTargetGuard');
$method->setAccessible(true);
$writerUrl = 'https://n8n.exemplo.com/webhook/rsconnect-agenda-cliente';
$blocked = $method->invoke($service, 2, $writerUrl, 'ai.replied');
$allowed = $method->invoke($service, 2, $writerUrl, 'calendar.appointment.created');
$assert(!empty($blocked['blocked']), 'Writer conhecido deve bloquear ai.replied mesmo sem cadastro de fluxo.');
$assert(empty($allowed['blocked']), 'Writer conhecido deve aceitar somente calendar.appointment.created.');
$assert(str_contains($agentController, "str_contains(\$path, 'rsconnect-agenda-cliente')"), 'Assistente não deve aceitar writer como integração externa legada.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - recuperação pós-horário reentra na Agenda e writer não recebe ai.replied.\n";
