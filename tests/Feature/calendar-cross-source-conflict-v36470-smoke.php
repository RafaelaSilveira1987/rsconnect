<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$slot = file_get_contents($root . '/app/Services/InternalCalendarSlotService.php');
$presenter = file_get_contents($root . '/app/Services/PublishedSlotStatusService.php');
$controller = file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php');
$view = file_get_contents($root . '/app/Views/calendar_availability/index.php');
$cross = file_get_contents($root . '/app/Services/PublishedSlotAppointmentConflictService.php');
$mustContain = [
    [$cross, 'a.status IN ("scheduled", "confirmed")', 'confirmed appointments'],
    [$cross, 'a.starts_at < s.ends_at AND a.ends_at > s.starts_at', 'overlap semantics'],
    [$cross, 'al.appointment_id = a.id', 'no double count for already allocated appointments'],
    [$cross, 'a.owner_user_id = s.owner_user_id', 'professional matching'],
    [$slot, 'Há compromisso na agenda sem vínculo com esta vaga', 'hold blocking'],
    [$slot, 'Revise a agenda antes de remover', 'cancellation blocking'],
    [$controller, "['unlinked_appointment_count']", 'dashboard enrichment'],
    [$presenter, "'conflict' => 'Conflito com agendamento'", 'safe display'],
    [$view, 'Não ofertar até conciliar a agenda', 'operator explanation'],
];
foreach ($mustContain as [$text, $needle, $description]) {
    if (!str_contains((string) $text, (string) $needle)) {
        throw new RuntimeException('Missing safeguard: ' . $description);
    }
}
echo 'OK: ' . count($mustContain) . " verificações estruturais de conflito entre compromissos e vagas\n";
