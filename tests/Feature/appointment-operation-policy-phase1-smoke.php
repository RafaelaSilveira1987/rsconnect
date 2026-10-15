<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Services/AppointmentOperationPolicyService.php';

use App\Services\AppointmentOperationPolicyService;

$policy = new AppointmentOperationPolicyService();
$appointment = [
    'id' => 9, 'tenant_id' => 3, 'status' => 'awaiting_approval',
    'is_pre_schedule' => 1, 'starts_at' => '2026-11-02 09:00:00', 'ends_at' => '2026-11-02 10:00:00',
];
$settings = ['enabled' => 1, 'require_human_approval' => 1, 'ai_can_confirm' => 1];
$checks = [];
$assert = static function (bool $ok, string $label) use (&$checks): void {
    $checks[$label] = $ok;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
};
$assert(!$policy->evaluate('confirm_ai', 3, $appointment, $settings)['allowed'], 'aprovação humana prevalece');
$settings['require_human_approval'] = 0;
$settings['ai_can_confirm'] = 0;
$assert(!$policy->evaluate('confirm_ai', 3, $appointment, $settings)['allowed'], 'confirmação automática desabilitada');
$settings['ai_can_confirm'] = 1;
$assert($policy->evaluate('confirm_ai', 3, $appointment, $settings)['allowed'], 'confirmação habilitada para pré-reserva válida');
$assert(!$policy->evaluate('confirm_ai', 4, $appointment, $settings)['allowed'], 'impede confirmar compromisso de outra empresa');
$assert(!$policy->evaluate('confirm_ai', 3, array_merge($appointment, ['status' => 'cancelled']), $settings)['allowed'], 'impede confirmar compromisso cancelado');
$assert(!$policy->evaluate('confirm_ai', 3, array_merge($appointment, ['status' => 'confirmed']), $settings)['allowed'], 'não confirma duas vezes');
$assert(!$policy->evaluate('confirm_ai', 3, array_merge($appointment, ['starts_at' => '']), $settings)['allowed'], 'intervalo obrigatório');
$assert(!$policy->evaluate('execute_cancel', 3, $appointment, $settings)['allowed'], 'texto livre nunca executa cancelamento');
$assert(!$policy->evaluate('execute_reschedule', 3, $appointment, $settings)['allowed'], 'texto livre nunca executa remarcação');
$assert($policy->evaluate('request_cancel', 3, $appointment)['allowed'], 'permite apenas pedido de cancelamento');
$assert(!$policy->evaluate('request_reschedule', 3, $appointment, [], false)['allowed'], 'reagendamento respeita capability desabilitada');
$assert($policy->evaluate('request_reschedule', 3, $appointment, [], true)['allowed'], 'pedido de remarcação permitido');
$assert(!$policy->validateManualTransition('cancelled', 'confirmed')['allowed'], 'não reativa cancelado');
$assert(!$policy->validateManualTransition('confirmed', 'pre_scheduled')['allowed'], 'não regride confirmação para pré-reserva');
$assert($policy->validateManualTransition('awaiting_approval', 'confirmed')['allowed'], 'equipe pode aprovar');
$assert($policy->validateManualTransition('confirmed', 'cancelled')['allowed'], 'equipe pode cancelar');
$assert(!$policy->validateManualTransition('confirmed', 'confirmed')['allowed'], 'evita reprocessar confirmação idempotente');

$conversation = file_get_contents(dirname(__DIR__, 2) . '/app/Services/CalendarConversationService.php');
$existing = file_get_contents(dirname(__DIR__, 2) . '/app/Services/ExistingAppointmentConversationService.php');
$controller = file_get_contents(dirname(__DIR__, 2) . '/app/Controllers/CalendarController.php');
$assert(str_contains($conversation, "'confirm_ai', $" . 'tenantId') && str_contains($conversation, '$currentAppointment = $this->appointmentForMessaging'), 'revalida política e estado imediatamente antes da confirmação');
$assert(str_contains($existing, "'request_cancel'") && str_contains($existing, "'request_reschedule'"), 'canal de compromissos existentes usa política central');
$assert(str_contains($controller, 'validateManualTransition(') && str_contains($controller, 'status = :expected_status'), 'painel valida transição e atualização condicional');

if (in_array(false, $checks, true)) {
    fwrite(STDERR, 'Falharam: ' . implode(', ', array_keys(array_filter($checks, static fn ($ok) => !$ok))) . "\n");
    exit(1);
}
echo 'OK: ' . count($checks) . " verificações.\n";
