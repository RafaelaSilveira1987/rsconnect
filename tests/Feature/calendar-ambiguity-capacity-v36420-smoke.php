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

use App\Services\ExistingAppointmentConversationService;

$root = dirname(__DIR__, 2);
$existing = (string) file_get_contents($root . '/app/Services/ExistingAppointmentConversationService.php');
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$internal = (string) file_get_contents($root . '/app/Services/InternalCalendarSlotService.php');
$professional = (string) file_get_contents($root . '/app/Services/ProfessionalCalendarService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$availabilityController = (string) file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php');
$settingsView = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/124_calendar_slot_capacity_mode.sql');
$migrationManifest = (string) file_get_contents($root . '/database/migrations/manifest.php');
$appVersion = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$packageManifest = json_decode((string) file_get_contents($root . '/manifest.json'), true);

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$intent = new ExistingAppointmentConversationService();
$check(
    $intent->detectIntent('queria confirmar meu atendimento.', false) === 'status',
    '“confirmar meu atendimento” sem pedido de presença consulta o status'
);
$check(
    $intent->detectIntent('confirmo minha presença na consulta', true) === 'presence_confirm',
    'confirmação explícita de presença continua separada da consulta de status'
);
$check(
    str_contains($existing, 'findRelevantAppointments')
    && str_contains($existing, 'a.status IN ("pre_scheduled","awaiting_approval","scheduled","confirmed")')
    && str_contains($existing, 'calendar.existing_appointment_disambiguation')
    && str_contains($existing, 'calendar.existing_appointment_disambiguation_retry')
    && str_contains($existing, 'appointmentDisambiguationOptions'),
    'lookup ignora histórico remarcado e desambigua múltiplos compromissos ativos'
);
$pendingStart = strpos($existing, 'if ($pendingDisambiguation)');
$pendingReturn = strpos($existing, "'appointment_disambiguation_pending'", $pendingStart ?: 0);
$firstFallback = strpos($existing, '$appointment = $appointments[0] ?? null;', $pendingStart ?: 0);
$check(
    $pendingStart !== false && $pendingReturn !== false && $firstFallback !== false && $pendingReturn < $firstFallback,
    'resposta ambígua encerra a desambiguação antes de qualquer fallback para o primeiro compromisso'
);

$check(
    str_contains($migration, 'booking_capacity_mode')
    && str_contains($migration, 'default_slot_capacity')
    && str_contains($migration, 'capacity_total')
    && str_contains($migration, 'CREATE TABLE IF NOT EXISTS calendar_internal_slot_allocations')
    && str_contains($migration, 'UNIQUE KEY uq_internal_slot_appointment'),
    'migration cria modelo de capacidade e alocações por participante'
);
$check(
    str_contains($migrationManifest, "124_calendar_slot_capacity_mode.sql")
    && ($packageManifest['database']['required_migration'] ?? '') === '124_calendar_slot_capacity_mode.sql',
    'migration 124 está registrada como requisito do pacote'
);
$check(
    str_contains($settingsView, 'Atendimento individual')
    && str_contains($settingsView, 'Atendimento por capacidade / turma')
    && str_contains($settingsView, 'Capacidade padrão por horário')
    && str_contains($settingsView, 'ocupadas')
    && str_contains($settingsView, 'livre'),
    'interface oferece modelo individual ou turma e mostra ocupação sem duplicar horários'
);
$check(
    str_contains($availabilityController, "booking_capacity_mode")
    && str_contains($availabilityController, "capacity_total")
    && str_contains($availability, "'booking_capacity_mode' => 'single'")
    && str_contains($availability, 'default_slot_capacity'),
    'publicação herda capacidade da configuração da empresa'
);
$check(
    str_contains($internal, 'FOR UPDATE')
    && str_contains($internal, 'calendar_internal_slot_allocations')
    && str_contains($internal, 'occupiedByOthers >= $capacity')
    && str_contains($internal, 'activeAppointmentIdsForSlot')
    && str_contains($internal, 'remaining_capacity'),
    'consumo da vaga é transacional e respeita a capacidade máxima'
);
$check(
    str_contains($availability, 'sameSlotAppointmentIds')
    && str_contains($professional, 'allowedAppointmentIds')
    && str_contains($controller, 'allowedCapacityAppointmentIds'),
    'participantes da mesma turma não conflitam entre si, mas compromissos externos continuam protegidos'
);
$check(
    str_contains($calendarView, '$capacityGroups')
    && str_contains($calendarView, 'Ocupação da turma')
    && str_contains($calendarView, '_capacity_group_participants'),
    'grade visual consolida uma turma em um único bloco e mantém participantes na lista operacional'
);
$check(
    str_contains($appVersion, 'RS Connect 36.42.0')
    && str_contains($appVersion, 'RS Connect 36.42.1')
    && str_contains($appVersion, "REQUIRED_MIGRATION = '124_calendar_slot_capacity_mode.sql'")
    && str_contains($appVersion, 'RS Connect 36.42.2')
    && str_contains($appVersion, 'RS Connect 36.42.3')
    && ($packageManifest['package_version'] ?? '') === '36.42.3',
    'marcos 36.42.0/36.42.2 preservados e versão 36.42.3 mantém a migration 124'
);

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - confirmação/remarcação desambiguadas e capacidade simultânea por horário estão presentes no pacote.\n";
