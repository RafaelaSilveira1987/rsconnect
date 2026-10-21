<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $s, ?string $encoding = null): string { return strtolower($s); } }
if (!function_exists('mb_substr')) { function mb_substr(string $s, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($s, $start) : substr($s, $start, $length); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $s, ?string $encoding = null): int { return strlen($s); } }

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;
use App\Services\AppointmentReminderEligibilityService;

$profile = [
    'status' => 'active', 'interaction_mode' => 'hybrid',
    'capabilities' => ['triage.enabled' => true, 'eligibility.enabled' => false],
    'policies' => [],
    'triage_fields' => [
        ['field_key' => 'is_for_self', 'label' => 'Atendimento para quem', 'field_type' => 'boolean', 'prompt_text' => 'O atendimento é para você?', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1],
        ['field_key' => 'custom_phone', 'label' => 'Telefone para contato', 'field_type' => 'text', 'prompt_text' => 'Qual é o seu telefone?', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1],
        ['field_key' => 'custom_first_consultation', 'label' => 'Primeira consulta', 'field_type' => 'text', 'prompt_text' => 'É a sua primeira consulta?', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1],
    ],
];
$s = new AgentTriageService();
$checks = [];
$initial = ['collected' => [], 'current_field_key' => 'is_for_self', 'last_intent' => 'schedule', 'status' => 'collecting'];
$first = $s->simulateTurn($profile, $initial, 'Bom dia, sim, é para mim');
$checks['saudação com confirmação preenche somente atendimento para quem'] = ($first['collected']['is_for_self'] ?? null) === true && !isset($first['collected']['custom_phone']);
$second = $s->simulateTurn($profile, $first['state'], 'Gostaria de agendar uma consulta');
$checks['pedido de agendamento não é aceito como telefone'] = !isset($second['collected']['custom_phone']);
$third = $s->simulateTurn($profile, $second['state'], '(32) 98888-7777');
$checks['telefone válido é normalizado'] = ($third['collected']['custom_phone'] ?? null) === '32988887777';
$fourth = $s->simulateTurn($profile, $third['state'], 'Sim, é minha primeira consulta');
$checks['primeira consulta sim é concluída'] = str_starts_with((string) ($fourth['collected']['custom_first_consultation'] ?? ''), 'Sim');
$checks['não repete primeira consulta quando respondida'] = !in_array('custom_first_consultation', $fourth['missing_before_schedule'] ?? [], true);
$checks['pré-reserva pendente nunca recebe lembrete'] = !AppointmentReminderEligibilityService::isConfirmed(['status' => 'awaiting_approval', 'is_pre_schedule' => 1, 'approval_status' => 'pending']);
$checks['confirmado sem aprovação não libera lembrete'] = !AppointmentReminderEligibilityService::isConfirmed(['status' => 'confirmed', 'is_pre_schedule' => 1, 'approval_status' => 'pending']);
$checks['confirmado e aprovado libera lembrete'] = AppointmentReminderEligibilityService::isConfirmed(['status' => 'confirmed', 'is_pre_schedule' => 1, 'approval_status' => 'approved']);
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
