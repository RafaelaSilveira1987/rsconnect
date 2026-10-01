<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\SchedulingPreferenceResolverService;

$root = dirname(__DIR__, 2);
$existing = (string) file_get_contents($root . '/app/Services/ExistingAppointmentConversationService.php');
$preSchedule = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$reprocess = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$calendar = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$resolved = (new SchedulingPreferenceResolverService())->resolve(
    "queria reagendar minha consulta de amanhã\nconsigo ir mais cedo\npode ser as 10h",
    false
);
$check(!empty($resolved['has_intent']), 'bloco pós-horário mantém intenção de remarcação');
$check(($resolved['preferred_day'] ?? '') === 'amanhã', 'bloco preserva o dia solicitado');
$check(($resolved['preferred_time'] ?? '') === '10:00', 'bloco preserva o horário solicitado');

$check(
    str_contains($existing, "'route_to_pre_scheduling' => true")
    && str_contains($existing, "'reschedule_of_appointment_id' => \$appointmentId")
    && !str_contains($existing, 'Registrei seu pedido de remarcação do atendimento de '),
    'compromisso existente roteia remarcação para a Agenda real em vez de responder texto terminal'
);
$check(
    str_contains($webhook, 'routeExistingReschedule')
    && str_contains($webhook, "flowContext['reschedule_of_appointment_id']"),
    'webhook encaminha remarcação existente diretamente ao pré-agendamento'
);
$check(
    str_contains($reprocess, 'routeExistingReschedule')
    && str_contains($reprocess, 'calendarBurstForMessage')
    && str_contains($reprocess, "flowContext['reschedule_of_appointment_id']"),
    'retomada pós-horário usa o burst completo e preserva vínculo com o compromisso original'
);
$check(
    str_contains($preSchedule, "'ai_reschedule:' . \$rescheduleOfAppointmentId")
    && str_contains($preSchedule, "'reschedule_of_appointment_id' => \$rescheduleOfAppointmentId"),
    'novo pré-agendamento registra origem da remarcação sem migration nova'
);
$check(
    str_contains($calendar, 'finalizeOriginalAfterReschedule')
    && str_contains($calendar, 'Substituído por remarcação confirmada.')
    && str_contains($calendar, 'calendar.reschedule_completed'),
    'confirmação do novo horário encerra o compromisso antigo e seus lembretes'
);
$check(
    str_contains($version, 'RS Connect 36.41.6')
    && str_contains($version, "REQUIRED_MIGRATION = '123_published_slots_min_notice_policy.sql'"),
    'pacote identifica 36.41.6 sem exigir migration adicional'
);

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - remarcação existente agora percorre busca real de disponibilidade e mantém vínculo com o horário anterior.\n";
