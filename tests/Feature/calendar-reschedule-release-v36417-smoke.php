<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$internal = (string) file_get_contents($root . '/app/Services/InternalCalendarSlotService.php');
$calendar = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$checks = [
    'serviço reconcilia vagas booked de compromissos inativos' => str_contains($internal, 'releaseInactiveBookedSlots')
        && str_contains($internal, 'a.status IN ("cancelled","rejected","rescheduled")')
        && str_contains($internal, 's.status = "booked"'),
    'listagem corrige vaga antiga presa ao abrir disponibilidades' => substr_count($internal, '$this->releaseInactiveBookedSlots($tenantId);') >= 2,
    'finalização da remarcação força reconciliação após status rescheduled' => str_contains($calendar, '(new InternalCalendarSlotService())->releaseInactiveBookedSlots(')
        && str_contains($calendar, '$originalAppointmentId'),
    'compromissos ativos não entram na reconciliação' => !str_contains($internal, 'a.status IN ("scheduled","confirmed")'),
    'versão atual é 36.41.8' => str_contains($version, 'RS Connect 36.41.8')
        && str_contains($version, 'RS Connect 36.41.7')
        && ($manifest['package_version'] ?? '') === '36.41.8',
    'sem migration nova' => ($manifest['database']['required_migration'] ?? '') === '123_published_slots_min_notice_policy.sql',
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - remarcação libera a vaga anterior e reconcilia slots publicados presos.\n";
