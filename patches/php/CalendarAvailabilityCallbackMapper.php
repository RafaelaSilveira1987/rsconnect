<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use InvalidArgumentException;

/** Normaliza callbacks dos dois templates para o formato consumido pelo ZIP 27. */
final class CalendarAvailabilityCallbackMapper
{
    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function map(array $payload): array
    {
        $event = (string) ($payload['event'] ?? 'calendar.availability.result');
        $source = (string) ($payload['source'] ?? 'unknown');

        if ($event === 'calendar.availability.result') {
            $slots = $payload['slots'] ?? [];
            if (!is_array($slots)) {
                throw new InvalidArgumentException('slots precisa ser uma lista.');
            }

            return [
                'type' => 'availability',
                'tenant_id' => (int) ($payload['tenant_id'] ?? 0),
                'request_id' => (string) ($payload['request_id'] ?? ''),
                'appointment_id' => $payload['appointment_id'] ?? null,
                'source' => $source,
                'slots' => array_values(array_map([$this, 'mapSlot'], $slots)),
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            ];
        }

        if ($event === 'calendar.marked_slot.updated') {
            return [
                'type' => 'event_update',
                'tenant_id' => (int) ($payload['tenant_id'] ?? 0),
                'request_id' => (string) ($payload['request_id'] ?? ''),
                'appointment_id' => $payload['appointment_id'] ?? null,
                'source' => $source,
                'action' => (string) ($payload['action'] ?? ''),
                'state' => (string) ($payload['state'] ?? ''),
                'google_event_id' => (string) ($payload['google_event_id'] ?? ''),
                'google_calendar_id' => (string) ($payload['google_calendar_id'] ?? 'primary'),
                'modality' => (string) ($payload['modality'] ?? 'indefinida'),
                'start' => $payload['start'] ?? null,
                'end' => $payload['end'] ?? null,
                'current_summary' => (string) ($payload['current_summary'] ?? ''),
                'transparency' => (string) ($payload['transparency'] ?? ''),
            ];
        }

        throw new InvalidArgumentException('Evento de callback não reconhecido.');
    }

    /** @param mixed $slot @return array<string, mixed> */
    private function mapSlot(mixed $slot): array
    {
        if (!is_array($slot)) {
            throw new InvalidArgumentException('Slot inválido.');
        }

        return [
            'start' => $slot['start'] ?? null,
            'end' => $slot['end'] ?? null,
            'label' => (string) ($slot['label'] ?? ''),
            'modality' => (string) ($slot['modality'] ?? 'indefinida'),
            'google_event_id' => $slot['google_event_id'] ?? null,
            'google_calendar_id' => $slot['google_calendar_id'] ?? null,
            'source' => (string) ($slot['source'] ?? 'unknown'),
            'metadata' => $slot,
        ];
    }
}
