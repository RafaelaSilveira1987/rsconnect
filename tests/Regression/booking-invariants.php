<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/Services/PublishedSlotCapacityPolicy.php';
require dirname(__DIR__, 2) . '/app/Services/PublishedSlotReconciliationPolicy.php';

use App\Services\PublishedSlotCapacityPolicy;
use App\Services\PublishedSlotReconciliationPolicy;

$checks = [];
$capacity = new PublishedSlotCapacityPolicy();
$reconciliation = new PublishedSlotReconciliationPolicy();
$checks['a quarta pessoa consegue reservar uma turma de quatro'] = $capacity->evaluateHold(4, 3, false)['allowed'] === true;
$checks['a quinta pessoa não ultrapassa a capacidade'] = $capacity->evaluateHold(4, 4, false)['allowed'] === false;
$checks['retry de reserva não consome a quinta vaga'] = $capacity->evaluateHold(4, 4, true)['code'] === 'already_booked';
$checks['pré-reserva expirada não pode ser confirmada'] = $capacity->canConfirm('held', false) === false;
$checks['pré-reserva válida pode ser confirmada'] = $capacity->canConfirm('held', true) === true;

$slot = [
    'status' => 'available', 'capacity_total' => 4, 'owner_user_id' => 7,
    'modality' => 'presencial', 'starts_at' => '2027-11-03 10:00:00',
    'ends_at' => '2027-11-03 10:50:00',
];
$appointment = [
    'id' => 99, 'status' => 'confirmed', 'owner_user_id' => 7,
    'location_type' => 'presencial', 'starts_at' => '2027-11-03 10:00:00',
    'ends_at' => '2027-11-03 10:50:00',
];
$checks['uma conciliação inequívoca ocupa apenas uma vaga'] = $reconciliation->decide($slot, [$appointment], 0, 1)['ids'] === [99];
$checks['a mesma reserva repetida não entra duas vezes'] = !$reconciliation->decide($slot, [$appointment, $appointment], 0, 1)['allowed'];
$checks['profissional diferente impede conciliação'] = !$reconciliation->decide($slot, [[...$appointment, 'owner_user_id' => 8]], 0, 1)['allowed'];
$checks['modalidade diferente impede conciliação'] = !$reconciliation->decide($slot, [[...$appointment, 'location_type' => 'online']], 0, 1)['allowed'];
$checks['horário vizinho, mas sobreposto, não é conciliado'] = !$reconciliation->decide($slot, [[...$appointment, 'starts_at' => '2027-11-03 10:30:00']], 0, 1)['allowed'];
$checks['capacidade insuficiente impede conciliação'] = !$reconciliation->decide($slot, [$appointment], 4, 1)['allowed'];
$checks['múltiplos horários ambíguos impedem conciliação'] = !$reconciliation->decide($slot, [$appointment], 0, 2)['allowed'];

foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
exit(in_array(false, $checks, true) ? 1 : 0);
