<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/Services/PublishedSlotReconciliationPolicy.php';

use App\Services\PublishedSlotReconciliationPolicy;

function expectReconciliation(bool $condition, string $label): void
{
    if (!$condition) {
        throw new RuntimeException('Falhou: ' . $label);
    }
    echo "OK: {$label}\n";
}

$service = new PublishedSlotReconciliationPolicy();
$slot = [
    'status' => 'available', 'capacity_total' => 4,
    'owner_user_id' => 7, 'modality' => 'presencial',
    'starts_at' => '2026-10-17 10:00:00', 'ends_at' => '2026-10-17 10:50:00',
];
$appointment = [
    'id' => 100, 'status' => 'confirmed', 'owner_user_id' => 7,
    'location_type' => 'presencial',
    'starts_at' => '2026-10-17 10:00:00', 'ends_at' => '2026-10-17 10:50:00',
];
$decision = $service->decide($slot, [$appointment], 0, 1);
expectReconciliation($decision['allowed'] && $decision['ids'] === [100], '1 compromisso confirmado pode ocupar uma das 4 vagas após confirmação humana');
expectReconciliation(!$service->decide($slot, [$appointment], 4, 1)['allowed'], 'não excede capacidade');
expectReconciliation(!$service->decide($slot, [$appointment], 0, 2)['allowed'], 'não concilia quando há dois slots sobrepostos');
expectReconciliation(!$service->decide($slot, [array_replace($appointment, ['owner_user_id' => 9])], 0, 1)['allowed'], 'profissional divergente bloqueado');
expectReconciliation(!$service->decide($slot, [array_replace($appointment, ['location_type' => 'online'])], 0, 1)['allowed'], 'modalidade divergente bloqueada');
expectReconciliation(!$service->decide($slot, [array_replace($appointment, ['ends_at' => '2026-10-17 11:00:00'])], 0, 1)['allowed'], 'duração diferente bloqueada');
expectReconciliation(!$service->decide($slot, [array_replace($appointment, ['status' => 'cancelled'])], 0, 1)['allowed'], 'cancelados não entram na ocupação');
expectReconciliation(!$service->decide($slot, [$appointment, $appointment], 0, 1)['allowed'], 'compromisso duplicado bloqueado');
expectReconciliation(!$service->decide(array_replace($slot, ['owner_user_id' => null]), [$appointment], 0, 1)['allowed'], 'profissional indefinido bloqueado');
expectReconciliation(!$service->decide(array_replace($slot, ['status' => 'blocked']), [$appointment], 0, 1)['allowed'], 'horário bloqueado não é conciliado');
$appointment2 = array_replace($appointment, ['id' => 101]);
expectReconciliation($service->decide($slot, [$appointment, $appointment2], 2, 1)['allowed'], 'turma admite exatamente quatro ocupações');
expectReconciliation(!$service->decide($slot, [$appointment, $appointment2], 3, 1)['allowed'], 'quinta ocupação bloqueada');
