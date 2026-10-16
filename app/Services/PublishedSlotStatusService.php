<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Projeção SOMENTE-LEITURA dos horários explicitamente publicados.
 * Não determina disponibilidade final para agendamento: antecedência,
 * modalidade, profissional e conflitos devem ser verificados novamente pelo
 * CalendarAvailabilityService no momento de oferecer/selecionar a vaga.
 */
final class PublishedSlotStatusService
{
    /**
     * @param array<string,mixed> $slot Linha de InternalCalendarSlotService::listSlots()
     * @return array{state:string,label:string,capacity:int,occupied:int,remaining:int,held:int,booked:int,offerable:bool}
     */
    public function describe(array $slot, string $localNow, bool $allocationsAvailable): array
    {
        $rawStatus = strtolower(trim((string) ($slot['status'] ?? '')));
        $capacity = max(1, (int) ($slot['capacity_total'] ?? 1));
        $held = max(0, (int) ($slot['held_count'] ?? 0));
        $booked = max(0, (int) ($slot['booked_count'] ?? 0));

        if ($allocationsAvailable) {
            $occupied = max(0, (int) ($slot['occupied_count'] ?? 0));
            $remaining = max(0, $capacity - $occupied);
        } else {
            // Bases legadas sem controle de alocações: não inferir vagas
            // adicionais para slots com capacidade > 1.
            $occupied = in_array($rawStatus, ['booked', 'held'], true) ? $capacity : 0;
            $held = $rawStatus === 'held' ? $capacity : 0;
            $booked = $rawStatus === 'booked' ? $capacity : 0;
            // Sem tabela de alocações, o motor legado só permite uma vaga.
            $remaining = $occupied > 0 ? 0 : 1;
        }

        $state = 'unknown';
        if ($rawStatus === 'blocked') {
            $state = 'blocked';
        } elseif (in_array($rawStatus, ['expired', 'cancelled'], true)) {
            $state = 'expired';
        } elseif (!in_array($rawStatus, ['available', 'held', 'booked'], true)
            || ($allocationsAvailable && ($occupied > $capacity || $occupied !== ($held + $booked)
                || (in_array($rawStatus, ['held', 'booked'], true) && $occupied === 0)))) {
            $state = 'unknown';
        } elseif ($this->validSqlDate((string) ($slot['ends_at'] ?? ''))
            && $this->validSqlDate($localNow)
            && (string) $slot['ends_at'] <= $localNow) {
            $state = 'past';
        } elseif (!$this->validSqlDate((string) ($slot['starts_at'] ?? ''))
            || !$this->validSqlDate((string) ($slot['ends_at'] ?? ''))) {
            $state = 'unknown';
        } elseif ($remaining === 0) {
            $state = $held > 0 ? 'held' : 'occupied';
        } else {
            $state = 'available';
        }

        $labels = [
            'available' => 'Com vagas',
            'occupied' => 'Lotado',
            'held' => 'Pré-reservado',
            'blocked' => 'Bloqueado',
            'expired' => 'Expirado/removido',
            'past' => 'Horário encerrado',
            'unknown' => 'Verificar situação',
        ];
        $offerable = $state === 'available';
        return [
            'state' => $state,
            'label' => $labels[$state],
            'capacity' => $capacity,
            'occupied' => min($capacity, $occupied),
            'remaining' => $offerable ? $remaining : 0,
            'held' => $held,
            'booked' => $booked,
            'offerable' => $offerable,
        ];
    }

    private function validSqlDate(string $date): bool
    {
        return preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d$/D', $date) === 1;
    }
}
