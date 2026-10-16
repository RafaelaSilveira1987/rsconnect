<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Projeta uma grade textual de horários publicados para o WhatsApp.
 * Somente os slots que passaram pelo motor de disponibilidade recebem número
 * de seleção. A grade não concede permissão de reservar nem altera o banco.
 */
final class ConversationalSlotGridService
{
    private const MAX_DISPLAY_ROWS = 100;
    private const MAX_MESSAGE_LENGTH = 3500;

    /**
     * @param array<string,mixed> $appointment
     * @param list<array<string,mixed>> $eligibleSlots Slots de calendar_availability_slots já filtrados
     * @return string|null Null = não há diagnóstico completo e seguro para esta consulta
     */
    public function render(int $tenantId, array $appointment, array $eligibleSlots): ?string
    {
        if ($tenantId < 1 || $eligibleSlots === []
            || (string) ($appointment['availability_source'] ?? '') !== 'internal_published'
            || trim((string) ($appointment['preferred_day_text'] ?? '')) === '') {
            return null;
        }
        $date = substr((string) ($appointment['starts_at'] ?? ''), 0, 10);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return null;
        }
        try {
            $parsed = new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone('America/Sao_Paulo'));
            if ($parsed->format('Y-m-d') !== $date) {
                return null;
            }
            $to = $parsed->modify('+1 day')->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }

        try {
            $service = new InternalCalendarSlotService();
            if (!$service->tableAvailable()) {
                return null;
            }
            $published = $service->listSlots($tenantId, $date . ' 00:00:00', $to);
            if ($published === []) {
                return null;
            }
            $slotIds = array_values(array_filter(array_map(static fn (array $s): int => (int) ($s['id'] ?? 0), $published)));
            $hasAllocations = $service->allocationTableAvailable();
            $unlinked = (new PublishedSlotAppointmentConflictService())->counts($tenantId, $slotIds, $hasAllocations);
            // Diagnóstico falhou: jamais classificar como livre ou lotado por suposição.
            if ($unlinked === null) {
                return null;
            }
            foreach ($published as &$slot) {
                $slot['unlinked_appointment_count'] = $unlinked[(int) ($slot['id'] ?? 0)] ?? 0;
            }
            unset($slot);
            return $this->format(
                $published, $eligibleSlots, $date, $hasAllocations,
                (string) ($appointment['appointment_modality'] ?? $appointment['location_type'] ?? '')
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Construção pura e testável; usa exclusivamente horários publicados e opções
     * aprovadas pelo motor de disponibilidade, sem expor dados de pacientes.
     *
     * @param list<array<string,mixed>> $published
     * @param list<array<string,mixed>> $eligible
     */
    public function format(
        array $published, array $eligible, string $date, bool $hasAllocations,
        string $appointmentModality = ''
    ): ?string
    {
        if ($published === []) {
            return null;
        }
        $approved = [];
        foreach ($eligible as $index => $slot) {
            $raw = json_decode((string) ($slot['raw_json'] ?? '{}'), true);
            $slotId = is_array($raw) ? (int) ($raw['internal_slot_id'] ?? 0) : 0;
            if ($slotId > 0 && substr((string) ($slot['starts_at'] ?? ''), 0, 10) === $date) {
                $approved[$slotId] = [
                    'position' => (int) ($slot['suggestion_position'] ?? ($index + 1)),
                    'modality' => strtolower(trim((string) ($slot['modality'] ?? ''))),
                ];
            }
        }
        // Falha de vínculo (legado ou consulta mista): a nova grade não pode
        // omitir uma opção elegível. Use o formato original do motor.
        if ($eligible !== [] && $approved === []) {
            return null;
        }
        $publishedIds = array_fill_keys(array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $published), true);
        foreach (array_keys($approved) as $id) {
            if (!isset($publishedIds[$id])) {
                return null;
            }
        }

        $policy = new PublishedSlotStatusService();
        $requestedModality = strtolower(trim($appointmentModality !== '' ? $appointmentModality : (string) ($eligible[0]['modality'] ?? '')));
        $lines = ['*Horários de ' . date('d/m/Y', strtotime($date)) . '*'];
        $now = date('Y-m-d H:i:s');
        $shown = 0;
        $omitted = 0;
        foreach ($published as $slot) {
            if (substr((string) ($slot['starts_at'] ?? ''), 0, 10) !== $date) {
                continue;
            }
            $modality = strtolower(trim((string) ($slot['modality'] ?? '')));
            if (in_array($requestedModality, ['online', 'presencial'], true)
                && in_array($modality, ['online', 'presencial'], true)
                && $modality !== $requestedModality) {
                continue;
            }
            $status = $policy->describe($slot, $now, $hasAllocations);
            // Horários removidos ou vencidos não são opções da agenda publicada.
            if (in_array($status['state'], ['expired', 'past'], true)) {
                continue;
            }
            $id = (int) ($slot['id'] ?? 0);
            $time = substr((string) ($slot['starts_at'] ?? ''), 11, 5);
            if (preg_match('/^\d\d:\d\d$/D', $time) !== 1) {
                continue;
            }
            $modalityText = in_array($modality, ['online', 'presencial'], true) ? ' · ' . ucfirst($modality) : '';
            if (isset($approved[$id]) && !empty($status['offerable'])) {
                $position = $approved[$id]['position'];
                $capacity = max(1, (int) ($status['capacity'] ?? 1));
                $remaining = max(0, (int) ($status['remaining'] ?? 0));
                $line = $position . '. ' . $time . ' — ' . $remaining . '/' . $capacity . ' vagas livres' . $modalityText;
            } else {
                $label = match ((string) $status['state']) {
                    'occupied' => 'Lotado',
                    'held' => 'Pré-reservado / sem vagas',
                    'blocked' => 'Bloqueado',
                    'conflict', 'unknown' => 'Indisponível (em verificação)',
                    default => 'Não ofertável nesta consulta',
                };
                $line = '– ' . $time . ' — ' . $label . $modalityText;
            }
            if ($shown >= self::MAX_DISPLAY_ROWS || strlen(implode("\n", $lines)) + strlen($line) > self::MAX_MESSAGE_LENGTH - 155) {
                ++$omitted;
                continue;
            }
            $lines[] = $line;
            ++$shown;
        }
        if ($shown === 0) {
            return null;
        }
        if ($omitted > 0 || count($published) >= 1200) {
            $lines[] = 'Lista parcial: há mais horários. Informe um período para uma nova consulta.';
        }
        $lines[] = $approved === []
            ? 'Nenhuma opção desta consulta pode ser reservada agora. Você pode solicitar outro dia ou período.'
            : 'Somente opções numeradas podem ser escolhidas. A vaga será conferida novamente antes da reserva.';
        return implode("\n", $lines);
    }
}
