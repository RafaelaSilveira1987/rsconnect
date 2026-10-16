<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/PublishedSlotStatusService.php';

use App\Services\PublishedSlotStatusService;

$projector = new PublishedSlotStatusService();
$now = '2026-10-15 17:00:00';
$base = [
    'status' => 'available',
    'starts_at' => '2026-10-20 08:00:00',
    'ends_at' => '2026-10-20 09:00:00',
    'capacity_total' => 4,
    'occupied_count' => 0,
    'held_count' => 0,
    'booked_count' => 0,
];
$cases = [
    'vazio_publicado' => [$base, true, 'available', 4],
    'turma_2_4' => [array_merge($base, ['occupied_count' => 2, 'booked_count' => 2]), true, 'available', 2],
    'turma_lotada' => [array_merge($base, ['occupied_count' => 4, 'booked_count' => 4, 'status' => 'booked']), true, 'occupied', 0],
    'turma_lotada_pre_reserva' => [array_merge($base, ['occupied_count' => 4, 'held_count' => 1, 'booked_count' => 3]), true, 'held', 0],
    'bloqueado' => [array_merge($base, ['status' => 'blocked']), true, 'blocked', 0],
    'expirado' => [array_merge($base, ['status' => 'expired']), true, 'expired', 0],
    'encerrado' => [array_merge($base, ['starts_at' => '2026-10-14 08:00:00', 'ends_at' => '2026-10-14 09:00:00']), true, 'past', 0],
    'estado_desconhecido' => [array_merge($base, ['status' => 'bogus']), true, 'unknown', 0],
    'legado_status_booked_sem_alocacao' => [array_merge($base, ['status' => 'booked']), true, 'unknown', 0],
    'ocupacao_inconsistente' => [array_merge($base, ['occupied_count' => 4, 'booked_count' => 1]), true, 'unknown', 0],
    'legado_lotado' => [array_merge($base, ['status' => 'booked']), false, 'occupied', 0],
    'legado_pre_reservado' => [array_merge($base, ['status' => 'held']), false, 'held', 0],
    'legado_disponivel' => [$base, false, 'available', 1],
];
foreach ($cases as $name => [$input, $hasAllocations, $state, $remaining]) {
    $result = $projector->describe($input, $now, $hasAllocations);
    if ($result['state'] !== $state || $result['remaining'] !== $remaining) {
        throw new RuntimeException($name . ' failed: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    }
    if ($result['offerable'] !== ($state === 'available')) {
        throw new RuntimeException($name . ' offerable inconsistency');
    }
}
echo 'OK: ' . count($cases) . " cenários de disponibilidade publicada\n";
