<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/ProfessionalCalendarService.php');
$calendar = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$preSchedule = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/066_contact_schedule_overlap_guard_compat.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'regra ativa por padrão' => str_contains($migration, 'professional_calendar_prevent_contact_overlap TINYINT(1) NOT NULL DEFAULT 1'),
    'regra configurável por empresa' => str_contains($view, 'professional_calendar_prevent_contact_overlap'),
    'consulta filtra o contato' => str_contains($service, 'AND a.contact_id = :contact_id'),
    'consulta considera agendados e confirmados' => str_contains($service, 'a.status IN ("scheduled", "confirmed")'),
    'pré-agendamento manual também bloqueia' => str_contains($service, 'COALESCE(a.pre_schedule_source, "") = "manual"'),
    'pré-agendamento incompleto não bloqueia por placeholder' => str_contains($service, 'COALESCE(a.preferred_day_text, "") <> ""')
        && str_contains($service, 'COALESCE(a.preferred_time_text, "") <> ""')
        && str_contains($service, 'COALESCE(a.chosen_availability_slot_id, 0) > 0'),
    'criação manual valida conflito do contato' => str_contains($calendar, '$professionalCalendarService->contactConflict('),
    'confirmação valida conflito do contato' => str_contains($calendar, '$professionalCalendarService->contactConflictMessage($contactConflict)'),
    'aplicação de horário valida contato' => str_contains($availability, '$professionalService->contactConflict('),
    'agenda interna remove horários ocupados pelo cliente' => str_contains($availability, '$this->contactBusyPeriods('),
    'pré-agendamento da conversa valida conflito' => str_contains($preSchedule, "'contact_schedule_conflict'"),
    'versão atualizada' => str_contains($version, '36.12.0'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - conflito de horário do mesmo cliente bloqueado em todos os fluxos principais.\n";
