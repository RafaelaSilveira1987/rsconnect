<?php

declare(strict_types=1);

namespace App\Services\Calendar;

use InvalidArgumentException;

/**
 * Fábrica independente de framework para montar os payloads dos dois workflows do ZIP 28.
 * Integre esta classe ao controller/serviço de Agenda Inteligente criado no ZIP 27.
 */
final class SmartCalendarGooglePayloadFactory
{
    /** @param array<string, mixed> $settings @param array<string, mixed> $context */
    public function buildAvailabilityPayload(array $settings, array $context): array
    {
        $mode = (string) ($settings['availability_mode'] ?? 'free_slots');

        return match ($mode) {
            'free_slots' => $this->buildFreeSlotsPayload($settings, $context),
            'marked_events' => $this->buildMarkedSearchPayload($settings, $context),
            default => throw new InvalidArgumentException('Modo de disponibilidade inválido.'),
        };
    }

    /** @param array<string, mixed> $settings @param array<string, mixed> $context */
    public function buildFreeSlotsPayload(array $settings, array $context): array
    {
        return [
            'tenant_id' => (int) ($context['tenant_id'] ?? 0),
            'request_id' => (string) ($context['request_id'] ?? $this->requestId()),
            'appointment_id' => $this->nullableInt($context['appointment_id'] ?? null),
            'conversation_id' => $this->nullableInt($context['conversation_id'] ?? null),
            'contact_id' => $this->nullableInt($context['contact_id'] ?? null),
            'date' => $this->requiredDate($context['date'] ?? null),
            'work_start' => (string) ($settings['work_start'] ?? '08:00'),
            'work_end' => (string) ($settings['work_end'] ?? '18:00'),
            'duration_minutes' => (int) ($settings['duration_minutes'] ?? 60),
            'interval_minutes' => (int) ($settings['interval_minutes'] ?? 0),
            'minimum_notice_minutes' => (int) ($settings['minimum_notice_minutes'] ?? 0),
            'allowed_weekdays' => $settings['allowed_weekdays'] ?? [1, 2, 3, 4, 5],
            'calendar_id' => (string) ($settings['google_calendar_id'] ?? 'primary'),
            'timezone' => (string) ($settings['google_timezone'] ?? 'America/Sao_Paulo'),
            'utc_offset' => (string) ($settings['google_utc_offset'] ?? '-03:00'),
            'ignore_transparent' => (bool) ($settings['ignore_transparent_events'] ?? true),
            'callback_url' => (string) ($context['callback_url'] ?? ''),
            'callback_token' => (string) ($context['callback_token'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $settings @param array<string, mixed> $context */
    public function buildMarkedSearchPayload(array $settings, array $context): array
    {
        return [
            'action' => 'search',
            'tenant_id' => (int) ($context['tenant_id'] ?? 0),
            'request_id' => (string) ($context['request_id'] ?? $this->requestId()),
            'appointment_id' => $this->nullableInt($context['appointment_id'] ?? null),
            'conversation_id' => $this->nullableInt($context['conversation_id'] ?? null),
            'contact_id' => $this->nullableInt($context['contact_id'] ?? null),
            'date' => $this->requiredDate($context['date'] ?? null),
            'work_start' => (string) ($settings['work_start'] ?? '00:00'),
            'work_end' => (string) ($settings['work_end'] ?? '23:59'),
            'calendar_id' => (string) ($settings['google_calendar_id'] ?? 'primary'),
            'modality' => (string) ($context['modality'] ?? ''),
            'online_title' => (string) ($settings['marked_online_title'] ?? 'VAGO — ONLINE'),
            'in_person_title' => (string) ($settings['marked_in_person_title'] ?? 'VAGO — PRESENCIAL'),
            'require_transparent' => (bool) ($settings['marked_require_transparent'] ?? true),
            'timezone' => (string) ($settings['google_timezone'] ?? 'America/Sao_Paulo'),
            'utc_offset' => (string) ($settings['google_utc_offset'] ?? '-03:00'),
            'callback_url' => (string) ($context['callback_url'] ?? ''),
            'callback_token' => (string) ($context['callback_token'] ?? ''),
        ];
    }

    /** @param array<string, mixed> $settings @param array<string, mixed> $context */
    public function buildMarkedUpdatePayload(string $action, array $settings, array $context): array
    {
        if (!in_array($action, ['hold', 'confirm', 'release'], true)) {
            throw new InvalidArgumentException('Ação inválida para evento marcado.');
        }

        $eventId = trim((string) ($context['google_event_id'] ?? ''));
        if ($eventId === '') {
            throw new InvalidArgumentException('google_event_id é obrigatório.');
        }

        return [
            'action' => $action,
            'tenant_id' => (int) ($context['tenant_id'] ?? 0),
            'request_id' => (string) ($context['request_id'] ?? $this->requestId()),
            'appointment_id' => $this->nullableInt($context['appointment_id'] ?? null),
            'conversation_id' => $this->nullableInt($context['conversation_id'] ?? null),
            'contact_id' => $this->nullableInt($context['contact_id'] ?? null),
            'calendar_id' => (string) ($settings['google_calendar_id'] ?? 'primary'),
            'google_event_id' => $eventId,
            'modality' => (string) ($context['modality'] ?? ''),
            'customer_name' => (string) ($context['customer_name'] ?? 'Cliente'),
            'customer_phone' => (string) ($context['customer_phone'] ?? ''),
            'notes' => (string) ($context['notes'] ?? ''),
            'online_title' => (string) ($settings['marked_online_title'] ?? 'VAGO — ONLINE'),
            'in_person_title' => (string) ($settings['marked_in_person_title'] ?? 'VAGO — PRESENCIAL'),
            'hold_prefix' => (string) ($settings['marked_hold_prefix'] ?? 'PRÉ-RESERVADO'),
            'confirmed_prefix' => (string) ($settings['marked_confirmed_prefix'] ?? 'AGENDADO'),
            'require_transparent' => (bool) ($settings['marked_require_transparent'] ?? true),
            'callback_url' => (string) ($context['callback_url'] ?? ''),
            'callback_token' => (string) ($context['callback_token'] ?? ''),
        ];
    }

    private function requiredDate(mixed $value): string
    {
        $date = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Data inválida; use YYYY-MM-DD.');
        }
        return $date;
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function requestId(): string
    {
        return 'calendar-' . bin2hex(random_bytes(8));
    }
}
