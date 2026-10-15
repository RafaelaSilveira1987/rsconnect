<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Services/PublishedSlotCapacityPolicy.php';
use App\Services\PublishedSlotCapacityPolicy;

$policy = new PublishedSlotCapacityPolicy();
$checks = [
    'one place: first client allowed' => $policy->evaluateHold(1, 0, false)['allowed'] === true,
    'one place: simultaneous second client blocked' => $policy->evaluateHold(1, 1, false)['code'] === 'capacity_full',
    'four places: fourth client allowed' => $policy->evaluateHold(4, 3, false)['allowed'] === true,
    'four places: fifth client blocked' => $policy->evaluateHold(4, 4, false)['code'] === 'capacity_full',
    'confirmed retry does not consume a new place' => $policy->evaluateHold(4, 4, true)['code'] === 'already_booked',
    'invalid capacity fails closed' => !$policy->evaluateHold(0, 0, false)['allowed'],
    'expired hold cannot be confirmed' => !$policy->canConfirm('held', false),
    'valid hold can be confirmed' => $policy->canConfirm('held', true),
    'booked confirmation is idempotent' => $policy->canConfirm('booked', false),
    'released cannot be confirmed' => !$policy->canConfirm('released', true),
];
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/app/Services/InternalCalendarSlotService.php');
$checks += [
    'appointment row locked' => str_contains($source, 'SELECT id FROM calendar_appointments WHERE id = :id AND tenant_id = :tenant_id LIMIT 1 FOR UPDATE'),
    'slot row locked' => str_contains($source, 'SELECT id FROM calendar_internal_slots WHERE id = :id AND tenant_id = :tenant_id LIMIT 1 FOR UPDATE'),
    'capacity decision used within slot lock' => str_contains($source, '(new PublishedSlotCapacityPolicy())->evaluateHold('),
    'hold expiration validated atomically in SQL' => str_contains($source, 'AND (expires_at IS NULL OR expires_at >= NOW())'),
    'release uses transaction' => str_contains($source, 'private function releaseAllocationForAppointment') && str_contains($source, 'Vaga devolvida à capacidade do horário publicado.'),
    'unique allocation in migration' => str_contains((string) file_get_contents(dirname(__DIR__, 2) . '/database/migrations/124_calendar_slot_capacity_mode.sql'), 'UNIQUE KEY uq_internal_slot_appointment'),
];
$fail = false;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
    $fail = $fail || !$ok;
}
exit($fail ? 1 : 0);
