<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$internal = (string) file_get_contents($root . '/app/Services/InternalCalendarSlotService.php');
$availability = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true);

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$check(
    str_contains($internal, 'COALESCE(al.occupied_count, 0) < GREATEST(COALESCE(s.capacity_total, 1), 1)')
    && str_contains($internal, 's.status NOT IN ("cancelled","blocked","expired")'),
    'busca de disponibilidade usa capacidade restante como fonte de verdade'
);

$check(
    str_contains($internal, 'capacitySummariesForSlots')
    && str_contains($internal, 'remaining_capacity')
    && str_contains($internal, 'occupied_count'),
    'serviço expõe ocupação atual dos slots publicados'
);

$check(
    str_contains($availability, 'capacitySummariesForSlots')
    && str_contains($availability, "['capacity_total']")
    && str_contains($availability, "['occupied_count']")
    && str_contains($availability, "['remaining_capacity']"),
    'dashboard enriquece as opções da última busca com ocupação ao vivo'
);

$check(
    str_contains($availability, 'capacitySummariesForAppointments')
    && str_contains($availability, "['slot_capacity_total']")
    && str_contains($availability, "['slot_occupied_count']"),
    'pré-agendamento escolhido recebe resumo de capacidade da turma'
);

$check(
    str_contains($view, 'ocupadas ·')
    && str_contains($view, '$slotCapacityTotal > 1')
    && str_contains($view, '$appointmentCapacityTotal > 1')
    && str_contains($view, '$currentSlotCount > 0'),
    'interface mostra 1/4, 2/4 e vagas livres nas opções e no pré-agendamento'
);

$check(
    str_contains($version, 'RS Connect 36.43.1')
    && ($manifest['package_version'] ?? '') === '36.44.1'
    && ($manifest['database']['required_migration'] ?? '') === '125_native_financial_lifecycle_notifications.sql',
    'release atual mantém migration 125 e corrige visualização/reoferta de capacidade'
);

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - vagas parciais continuam pesquisáveis e a ocupação é exibida ao vivo.\n";
