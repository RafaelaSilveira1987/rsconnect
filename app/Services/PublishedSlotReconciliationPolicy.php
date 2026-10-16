<?php

declare(strict_types=1);

namespace App\Services;

/** Regra pura: uma associação só é segura com identificação inequívoca. */
final class PublishedSlotReconciliationPolicy
{
    /** @param array<string,mixed> $slot @param list<array<string,mixed>> $appointments
     * @return array{allowed:bool,reason:string,ids:list<int>}
     */
    public function decide(array $slot, array $appointments, int $occupied, int $matchingSlotCount): array
    {
        $fail = static fn (string $message): array => ['allowed' => false, 'reason' => $message, 'ids' => []];
        if (!in_array((string) ($slot['status'] ?? ''), ['available', 'held', 'booked'], true)) {
            return $fail('Horário bloqueado, removido ou com situação incompatível.');
        }
        if ($matchingSlotCount !== 1) {
            return $fail('Existem horários publicados sobrepostos para o mesmo profissional. Revise manualmente.');
        }
        $capacity = (int) ($slot['capacity_total'] ?? 1);
        if ($capacity < 1 || $occupied < 0 || $occupied > $capacity) {
            return $fail('A capacidade deste horário está inconsistente.');
        }
        if ($appointments === []) {
            return $fail('Nenhum compromisso pendente de vínculo foi encontrado.');
        }
        $ownerId = (int) ($slot['owner_user_id'] ?? 0);
        $modality = (string) ($slot['modality'] ?? '');
        if ($ownerId < 1 || !in_array($modality, ['online', 'presencial', 'telefone'], true)) {
            return $fail('Defina profissional e modalidade específicos antes de conciliar.');
        }
        $ids = [];
        foreach ($appointments as $appointment) {
            $id = (int) ($appointment['id'] ?? 0);
            if ($id < 1 || isset($ids[$id])) {
                return $fail('Compromissos duplicados ou inválidos.');
            }
            if (!in_array((string) ($appointment['status'] ?? ''), ['scheduled', 'confirmed'], true)) {
                return $fail('Um compromisso não está ativo. Atualize a agenda.');
            }
            if ((string) ($appointment['starts_at'] ?? '') !== (string) ($slot['starts_at'] ?? '')
                || (string) ($appointment['ends_at'] ?? '') !== (string) ($slot['ends_at'] ?? '')) {
                return $fail('A duração de um compromisso não coincide exatamente com a vaga.');
            }
            if ((int) ($appointment['owner_user_id'] ?? 0) !== $ownerId) {
                return $fail('O profissional de um compromisso é diferente do horário publicado.');
            }
            if ((string) ($appointment['location_type'] ?? '') !== $modality) {
                return $fail('A modalidade de um compromisso é diferente da vaga.');
            }
            $ids[$id] = $id;
        }
        if ($occupied + count($ids) > $capacity) {
            return $fail('A soma das reservas existentes excede a capacidade do horário.');
        }
        return ['allowed' => true, 'reason' => 'Vínculos elegíveis para conferência e confirmação pela equipe.', 'ids' => array_values($ids)];
    }
}
