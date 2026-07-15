<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use App\Core\Router;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class CalendarAvailabilityService
{
    public function settings(int $tenantId): array
    {
        $defaults = [
            'tenant_id' => $tenantId,
            'enabled' => 0,
            'require_before_approval' => 1,
            'auto_request_on_pre_schedule' => 1,
            'use_n8n' => 1,
            'use_internal_fallback' => 1,
            'n8n_webhook_url' => '',
            'secret_token' => '',
            'timezone' => 'America/Sao_Paulo',
            'default_duration_minutes' => 50,
            'slot_interval_minutes' => 30,
            'buffer_minutes' => 10,
            'search_days_ahead' => 14,
            'workdays_json' => json_encode([1, 2, 3, 4, 5], JSON_UNESCAPED_SLASHES),
            'working_hours_json' => json_encode(['start' => '08:00', 'end' => '18:00'], JSON_UNESCAPED_SLASHES),
            'min_notice_hours' => 4,
            'max_suggestions' => 5,
            'updated_at' => null,
        ];

        if ($tenantId < 1 || !$this->tableExists('tenant_calendar_availability_settings')) {
            return $defaults;
        }

        try {
            $statement = Database::connection()->prepare('SELECT * FROM tenant_calendar_availability_settings WHERE tenant_id = :tenant_id LIMIT 1');
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$row) {
                return $defaults;
            }
            if (!empty($row['n8n_webhook_url_encrypted'])) {
                $row['n8n_webhook_url'] = Crypto::decrypt((string) $row['n8n_webhook_url_encrypted']) ?: '';
            }
            if (!empty($row['secret_token_encrypted'])) {
                $row['secret_token'] = Crypto::decrypt((string) $row['secret_token_encrypted']) ?: '';
            }
            unset($row['n8n_webhook_url_encrypted'], $row['secret_token_encrypted']);
            return array_merge($defaults, $row);
        } catch (Throwable) {
            return $defaults;
        }
    }

    public function saveSettings(int $tenantId, array $data): void
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_calendar_availability_settings')) {
            return;
        }

        $duration = max(15, min(240, (int) ($data['default_duration_minutes'] ?? 50)));
        $interval = max(10, min(180, (int) ($data['slot_interval_minutes'] ?? 30)));
        $buffer = max(0, min(180, (int) ($data['buffer_minutes'] ?? 10)));
        $searchDays = max(1, min(90, (int) ($data['search_days_ahead'] ?? 14)));
        $notice = max(0, min(720, (int) ($data['min_notice_hours'] ?? 4)));
        $maxSuggestions = max(1, min(20, (int) ($data['max_suggestions'] ?? 5)));

        $workdays = [];
        foreach ((array) ($data['workdays'] ?? []) as $day) {
            $day = (int) $day;
            if ($day >= 0 && $day <= 6 && !in_array($day, $workdays, true)) {
                $workdays[] = $day;
            }
        }
        if ($workdays === []) {
            $workdays = [1, 2, 3, 4, 5];
        }
        sort($workdays);

        $start = $this->normalizeHour((string) ($data['working_start'] ?? '08:00'), '08:00');
        $end = $this->normalizeHour((string) ($data['working_end'] ?? '18:00'), '18:00');
        if ($end <= $start) {
            $start = '08:00';
            $end = '18:00';
        }

        $webhookUrl = trim((string) ($data['n8n_webhook_url'] ?? ''));
        $secret = trim((string) ($data['secret_token'] ?? ''));

        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_calendar_availability_settings
                (tenant_id, enabled, require_before_approval, auto_request_on_pre_schedule, use_n8n, use_internal_fallback,
                 n8n_webhook_url_encrypted, secret_token_encrypted, timezone, default_duration_minutes, slot_interval_minutes,
                 buffer_minutes, search_days_ahead, workdays_json, working_hours_json, min_notice_hours, max_suggestions)
             VALUES
                (:tenant_id, :enabled, :require_before_approval, :auto_request_on_pre_schedule, :use_n8n, :use_internal_fallback,
                 :n8n_webhook_url_encrypted, :secret_token_encrypted, :timezone, :default_duration_minutes, :slot_interval_minutes,
                 :buffer_minutes, :search_days_ahead, :workdays_json, :working_hours_json, :min_notice_hours, :max_suggestions)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                require_before_approval = VALUES(require_before_approval),
                auto_request_on_pre_schedule = VALUES(auto_request_on_pre_schedule),
                use_n8n = VALUES(use_n8n),
                use_internal_fallback = VALUES(use_internal_fallback),
                n8n_webhook_url_encrypted = VALUES(n8n_webhook_url_encrypted),
                secret_token_encrypted = VALUES(secret_token_encrypted),
                timezone = VALUES(timezone),
                default_duration_minutes = VALUES(default_duration_minutes),
                slot_interval_minutes = VALUES(slot_interval_minutes),
                buffer_minutes = VALUES(buffer_minutes),
                search_days_ahead = VALUES(search_days_ahead),
                workdays_json = VALUES(workdays_json),
                working_hours_json = VALUES(working_hours_json),
                min_notice_hours = VALUES(min_notice_hours),
                max_suggestions = VALUES(max_suggestions),
                updated_at = CURRENT_TIMESTAMP'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'require_before_approval' => !empty($data['require_before_approval']) ? 1 : 0,
            'auto_request_on_pre_schedule' => !empty($data['auto_request_on_pre_schedule']) ? 1 : 0,
            'use_n8n' => !empty($data['use_n8n']) ? 1 : 0,
            'use_internal_fallback' => !empty($data['use_internal_fallback']) ? 1 : 0,
            'n8n_webhook_url_encrypted' => $webhookUrl !== '' ? Crypto::encrypt($webhookUrl) : null,
            'secret_token_encrypted' => $secret !== '' ? Crypto::encrypt($secret) : null,
            'timezone' => trim((string) ($data['timezone'] ?? 'America/Sao_Paulo')) ?: 'America/Sao_Paulo',
            'default_duration_minutes' => $duration,
            'slot_interval_minutes' => $interval,
            'buffer_minutes' => $buffer,
            'search_days_ahead' => $searchDays,
            'workdays_json' => json_encode($workdays, JSON_UNESCAPED_SLASHES),
            'working_hours_json' => json_encode(['start' => $start, 'end' => $end], JSON_UNESCAPED_SLASHES),
            'min_notice_hours' => $notice,
            'max_suggestions' => $maxSuggestions,
        ]);
    }

    public function requestForAppointment(int $tenantId, int $appointmentId, string $origin = 'manual'): array
    {
        if ($tenantId < 1 || $appointmentId < 1) {
            return ['ok' => false, 'message' => 'Empresa ou agendamento inválido.'];
        }
        if (!$this->tableExists('calendar_availability_requests')) {
            return ['ok' => false, 'message' => 'Migration de disponibilidade ainda não foi executada.'];
        }

        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['ok' => false, 'message' => 'Agendamento não encontrado.'];
        }

        $settings = $this->settings($tenantId);
        if (empty($settings['enabled'])) {
            return ['ok' => false, 'message' => 'Agenda inteligente ainda não está ativada para esta empresa.'];
        }

        $token = bin2hex(random_bytes(16));
        $window = $this->searchWindow($settings, $appointment);
        $payload = $this->buildPayload($tenantId, $appointment, $settings, $token, $window, $origin);

        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO calendar_availability_requests
                (tenant_id, appointment_id, request_token, origin, status, preferred_day_text, preferred_time_text,
                 search_start_at, search_end_at, duration_minutes, timezone, requested_payload_json, requested_at)
             VALUES
                (:tenant_id, :appointment_id, :request_token, :origin, "pending", :preferred_day_text, :preferred_time_text,
                 :search_start_at, :search_end_at, :duration_minutes, :timezone, :requested_payload_json, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'request_token' => $token,
            'origin' => mb_substr($origin, 0, 60),
            'preferred_day_text' => $appointment['preferred_day_text'] ?? null,
            'preferred_time_text' => $appointment['preferred_time_text'] ?? null,
            'search_start_at' => $window['start'],
            'search_end_at' => $window['end'],
            'duration_minutes' => (int) ($settings['default_duration_minutes'] ?? 50),
            'timezone' => (string) ($settings['timezone'] ?? 'America/Sao_Paulo'),
            'requested_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $requestId = (int) $pdo->lastInsertId();

        $this->updateAppointmentAvailability($tenantId, $appointmentId, 'requested', $requestId, null, null);

        $sent = false;
        $errors = [];
        $results = [];

        if (!empty($settings['use_n8n'])) {
            $explicitUrl = trim((string) ($settings['n8n_webhook_url'] ?? ''));
            if ($explicitUrl !== '') {
                $results = (new AutomationWebhookService())->dispatch('calendar.availability.requested', $payload, $explicitUrl, $tenantId);
            } else {
                $results = (new AutomationWebhookService())->dispatch('calendar.availability.requested', $payload, null, $tenantId);
            }
            foreach ($results as $result) {
                if (!empty($result['ok'])) {
                    $sent = true;
                } elseif (!empty($result['error'])) {
                    $errors[] = (string) $result['error'];
                }
            }
        }

        if ($sent) {
            $pdo->prepare('UPDATE calendar_availability_requests SET status = "sent", sent_at = NOW(), error_message = NULL WHERE id = :id')
                ->execute(['id' => $requestId]);
            Audit::log('calendar.availability_requested', ['request_id' => $requestId, 'appointment_id' => $appointmentId], $tenantId);
            return ['ok' => true, 'request_id' => $requestId, 'message' => 'Consulta enviada ao n8n. Aguarde o callback com os horários.'];
        }

        if (!empty($settings['use_internal_fallback'])) {
            $slots = $this->generateInternalSlots($tenantId, $window, $settings);
            $this->storeSlots($requestId, $tenantId, $appointmentId, $slots, 'internal_fallback');
            $message = $slots === []
                ? 'Nenhum horário livre encontrado pelo fallback interno.'
                : 'Horários gerados pelo fallback interno. Revise as opções antes de aprovar.';
            return ['ok' => $slots !== [], 'request_id' => $requestId, 'message' => $message];
        }

        $error = mb_substr(implode(' | ', $errors) ?: 'Nenhum fluxo n8n respondeu com sucesso.', 0, 700);
        $pdo->prepare('UPDATE calendar_availability_requests SET status = "failed", error_message = :error WHERE id = :id')
            ->execute(['id' => $requestId, 'error' => $error]);
        $this->updateAppointmentAvailability($tenantId, $appointmentId, 'failed', $requestId, null, $error);
        return ['ok' => false, 'request_id' => $requestId, 'message' => $error];
    }

    public function handleCallback(array $payload, ?string $token = null): array
    {
        if (!$this->tableExists('calendar_availability_requests') || !$this->tableExists('calendar_availability_slots')) {
            return ['ok' => false, 'message' => 'Tabelas de disponibilidade não encontradas.'];
        }

        $requestId = (int) ($payload['request_id'] ?? $payload['availability_request_id'] ?? 0);
        $requestToken = trim((string) ($payload['request_token'] ?? $token ?? ''));
        $request = $this->findRequest($requestId, $requestToken);
        if (!$request) {
            return ['ok' => false, 'message' => 'Solicitação de disponibilidade não encontrada.'];
        }

        $slots = $payload['slots'] ?? $payload['available_slots'] ?? [];
        if (!is_array($slots)) {
            $slots = [];
        }

        $normalizedSlots = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $start = trim((string) ($slot['start'] ?? $slot['starts_at'] ?? $slot['start_at'] ?? ''));
            $end = trim((string) ($slot['end'] ?? $slot['ends_at'] ?? $slot['end_at'] ?? ''));
            if ($start === '') {
                continue;
            }
            $startSql = $this->toSqlDateTime($start);
            $endSql = $end !== '' ? $this->toSqlDateTime($end) : null;
            if ($startSql === null) {
                continue;
            }
            if ($endSql === null) {
                $endSql = (new DateTimeImmutable($startSql))->add(new DateInterval('PT' . max(15, (int) $request['duration_minutes']) . 'M'))->format('Y-m-d H:i:s');
            }
            $normalizedSlots[] = [
                'start' => $startSql,
                'end' => $endSql,
                'label' => trim((string) ($slot['label'] ?? date('d/m/Y H:i', strtotime($startSql)))),
                'source' => trim((string) ($slot['source'] ?? $payload['source'] ?? 'n8n')) ?: 'n8n',
                'raw' => $slot,
            ];
        }

        $source = trim((string) ($payload['source'] ?? 'n8n')) ?: 'n8n';
        $this->storeSlots((int) $request['id'], (int) $request['tenant_id'], (int) $request['appointment_id'], $normalizedSlots, $source, $payload);
        return ['ok' => true, 'message' => 'Disponibilidade registrada no RS Connect.', 'slots' => count($normalizedSlots)];
    }

    public function applySlot(int $tenantId, int $appointmentId, int $slotId): array
    {
        $slot = $this->findSlot($tenantId, $appointmentId, $slotId);
        if (!$slot) {
            return ['ok' => false, 'message' => 'Horário não encontrado.'];
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE calendar_appointments
             SET starts_at = :starts_at,
                 ends_at = :ends_at,
                 availability_status = "slot_selected",
                 chosen_availability_slot_id = :slot_id,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :appointment_id AND tenant_id = :tenant_id'
        )->execute([
            'starts_at' => $slot['starts_at'],
            'ends_at' => $slot['ends_at'],
            'slot_id' => $slotId,
            'appointment_id' => $appointmentId,
            'tenant_id' => $tenantId,
        ]);
        $pdo->prepare('UPDATE calendar_availability_slots SET selected_at = NOW() WHERE id = :id')
            ->execute(['id' => $slotId]);
        Audit::log('calendar.availability_slot_selected', ['appointment_id' => $appointmentId, 'slot_id' => $slotId], $tenantId);
        return ['ok' => true, 'message' => 'Horário aplicado ao pré-agendamento. Agora ele pode ser aprovado.'];
    }

    public function canApprove(int $tenantId, array $appointment): array
    {
        $settings = $this->settings($tenantId);
        if (empty($settings['enabled']) || empty($settings['require_before_approval'])) {
            return ['ok' => true, 'message' => null];
        }
        if ((int) ($appointment['is_pre_schedule'] ?? 0) !== 1) {
            return ['ok' => true, 'message' => null];
        }
        $status = (string) ($appointment['availability_status'] ?? '');
        if (in_array($status, ['slot_selected', 'validated', 'received'], true)) {
            return ['ok' => true, 'message' => null];
        }
        return ['ok' => false, 'message' => 'Antes de aprovar, busque disponibilidade e escolha/valide um horário livre.'];
    }

    public function dashboard(int $tenantId): array
    {
        $settings = $this->settings($tenantId);
        $pdo = Database::connection();
        $pending = [];
        $requests = [];
        $slots = [];
        $metrics = ['pending' => 0, 'requests' => 0, 'slots' => 0, 'selected' => 0];

        if ($tenantId > 0 && $this->tableExists('calendar_appointments')) {
            $sql = 'SELECT a.*, ct.name AS contact_name, ct.phone
                    FROM calendar_appointments a
                    LEFT JOIN contacts ct ON ct.id = a.contact_id
                    WHERE a.tenant_id = :tenant_id
                      AND a.is_pre_schedule = 1
                      AND a.status IN ("pre_scheduled", "awaiting_approval", "rescheduled")
                    ORDER BY a.updated_at DESC, a.id DESC
                    LIMIT 80';
            $statement = $pdo->prepare($sql);
            $statement->execute(['tenant_id' => $tenantId]);
            $pending = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($tenantId > 0 && $this->tableExists('calendar_availability_requests')) {
            $statement = $pdo->prepare(
                'SELECT r.*, a.title AS appointment_title, ct.name AS contact_name
                 FROM calendar_availability_requests r
                 LEFT JOIN calendar_appointments a ON a.id = r.appointment_id
                 LEFT JOIN contacts ct ON ct.id = a.contact_id
                 WHERE r.tenant_id = :tenant_id
                 ORDER BY r.id DESC
                 LIMIT 80'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $requests = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($tenantId > 0 && $this->tableExists('calendar_availability_slots')) {
            $statement = $pdo->prepare(
                'SELECT s.*, a.title AS appointment_title, ct.name AS contact_name
                 FROM calendar_availability_slots s
                 LEFT JOIN calendar_appointments a ON a.id = s.appointment_id
                 LEFT JOIN contacts ct ON ct.id = a.contact_id
                 WHERE s.tenant_id = :tenant_id
                 ORDER BY s.starts_at ASC
                 LIMIT 120'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $slots = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $metrics['pending'] = count($pending);
        $metrics['requests'] = count($requests);
        $metrics['slots'] = count($slots);
        foreach ($slots as $slot) {
            if (!empty($slot['selected_at'])) {
                $metrics['selected']++;
            }
        }

        return compact('settings', 'pending', 'requests', 'slots', 'metrics');
    }

    private function storeSlots(int $requestId, int $tenantId, int $appointmentId, array $slots, string $source, ?array $rawPayload = null): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM calendar_availability_slots WHERE request_id = :request_id')->execute(['request_id' => $requestId]);
        $insert = $pdo->prepare(
            'INSERT INTO calendar_availability_slots
                (tenant_id, request_id, appointment_id, starts_at, ends_at, label, source, raw_json)
             VALUES
                (:tenant_id, :request_id, :appointment_id, :starts_at, :ends_at, :label, :source, :raw_json)'
        );
        foreach ($slots as $slot) {
            $insert->execute([
                'tenant_id' => $tenantId,
                'request_id' => $requestId,
                'appointment_id' => $appointmentId,
                'starts_at' => $slot['start'],
                'ends_at' => $slot['end'],
                'label' => mb_substr((string) ($slot['label'] ?? ''), 0, 180),
                'source' => mb_substr((string) ($slot['source'] ?? $source), 0, 60),
                'raw_json' => json_encode($slot['raw'] ?? $slot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $status = $slots === [] ? 'empty' : 'received';
        $pdo->prepare(
            'UPDATE calendar_availability_requests
             SET status = :status,
                 response_payload_json = :response_payload_json,
                 responded_at = NOW(),
                 error_message = NULL
             WHERE id = :id'
        )->execute([
            'id' => $requestId,
            'status' => $status,
            'response_payload_json' => json_encode($rawPayload ?? ['slots' => $slots, 'source' => $source], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $this->updateAppointmentAvailability($tenantId, $appointmentId, $status, $requestId, count($slots), null);
        Audit::log('calendar.availability_received', ['request_id' => $requestId, 'slots' => count($slots)], $tenantId);
    }

    private function buildPayload(int $tenantId, array $appointment, array $settings, string $token, array $window, string $origin): array
    {
        return [
            'tenant_id' => $tenantId,
            'event' => 'calendar.availability.requested',
            'origin' => $origin,
            'appointment_id' => (int) $appointment['id'],
            'request_token' => $token,
            'appointment' => $appointment,
            'preference' => [
                'day_text' => $appointment['preferred_day_text'] ?? null,
                'time_text' => $appointment['preferred_time_text'] ?? null,
                'starts_at' => $appointment['starts_at'] ?? null,
                'ends_at' => $appointment['ends_at'] ?? null,
            ],
            'search' => [
                'start_at' => $window['start'],
                'end_at' => $window['end'],
                'duration_minutes' => (int) ($settings['default_duration_minutes'] ?? 50),
                'timezone' => (string) ($settings['timezone'] ?? 'America/Sao_Paulo'),
                'max_suggestions' => (int) ($settings['max_suggestions'] ?? 5),
                'buffer_minutes' => (int) ($settings['buffer_minutes'] ?? 10),
            ],
            'callback' => [
                'url' => Router::url('/webhooks/calendar/availability'),
                'token' => $token,
            ],
            'requested_at' => date('c'),
        ];
    }

    private function searchWindow(array $settings, array $appointment): array
    {
        $timezone = new DateTimeZone((string) ($settings['timezone'] ?? 'America/Sao_Paulo'));
        $now = new DateTimeImmutable('now', $timezone);
        $start = $now->add(new DateInterval('PT' . max(0, (int) ($settings['min_notice_hours'] ?? 4)) . 'H'));
        if (!empty($appointment['starts_at'])) {
            try {
                $preferred = new DateTimeImmutable((string) $appointment['starts_at'], $timezone);
                if ($preferred > $start) {
                    $start = $preferred->modify('00:00:00');
                }
            } catch (Throwable) {
            }
        }
        $end = $start->add(new DateInterval('P' . max(1, (int) ($settings['search_days_ahead'] ?? 14)) . 'D'));
        return ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
    }

    private function generateInternalSlots(int $tenantId, array $window, array $settings): array
    {
        $timezone = new DateTimeZone((string) ($settings['timezone'] ?? 'America/Sao_Paulo'));
        $start = new DateTimeImmutable($window['start'], $timezone);
        $end = new DateTimeImmutable($window['end'], $timezone);
        $duration = max(15, (int) ($settings['default_duration_minutes'] ?? 50));
        $interval = max(10, (int) ($settings['slot_interval_minutes'] ?? 30));
        $max = max(1, (int) ($settings['max_suggestions'] ?? 5));
        $workdays = json_decode((string) ($settings['workdays_json'] ?? '[]'), true);
        if (!is_array($workdays) || $workdays === []) {
            $workdays = [1, 2, 3, 4, 5];
        }
        $hours = json_decode((string) ($settings['working_hours_json'] ?? '{}'), true);
        if (!is_array($hours)) {
            $hours = ['start' => '08:00', 'end' => '18:00'];
        }
        $dayStart = $this->normalizeHour((string) ($hours['start'] ?? '08:00'), '08:00');
        $dayEnd = $this->normalizeHour((string) ($hours['end'] ?? '18:00'), '18:00');
        $busy = $this->busyPeriods($tenantId, $window['start'], $window['end']);

        $slots = [];
        $cursor = $start;
        while ($cursor < $end && count($slots) < $max) {
            $weekday = (int) $cursor->format('w');
            if (!in_array($weekday, array_map('intval', $workdays), true)) {
                $cursor = $cursor->modify('+1 day')->setTime((int) substr($dayStart, 0, 2), (int) substr($dayStart, 3, 2));
                continue;
            }

            $businessStart = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $dayStart, $timezone);
            $businessEnd = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $dayEnd, $timezone);
            if ($cursor < $businessStart) {
                $cursor = $businessStart;
            }
            $slotEnd = $cursor->add(new DateInterval('PT' . $duration . 'M'));
            if ($slotEnd > $businessEnd) {
                $cursor = $cursor->modify('+1 day')->setTime((int) substr($dayStart, 0, 2), (int) substr($dayStart, 3, 2));
                continue;
            }

            if (!$this->overlapsBusy($cursor, $slotEnd, $busy)) {
                $slots[] = [
                    'start' => $cursor->format('Y-m-d H:i:s'),
                    'end' => $slotEnd->format('Y-m-d H:i:s'),
                    'label' => $cursor->format('d/m/Y H:i'),
                    'source' => 'internal_fallback',
                    'raw' => ['generated_by' => 'RS Connect fallback'],
                ];
            }
            $cursor = $cursor->add(new DateInterval('PT' . $interval . 'M'));
        }
        return $slots;
    }

    private function busyPeriods(int $tenantId, string $start, string $end): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT starts_at, ends_at FROM calendar_appointments
                 WHERE tenant_id = :tenant_id
                   AND status IN ("scheduled", "confirmed")
                   AND starts_at < :end_at
                   AND ends_at > :start_at'
            );
            $statement->execute(['tenant_id' => $tenantId, 'start_at' => $start, 'end_at' => $end]);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function overlapsBusy(DateTimeImmutable $start, DateTimeImmutable $end, array $busy): bool
    {
        foreach ($busy as $row) {
            try {
                $busyStart = new DateTimeImmutable((string) $row['starts_at']);
                $busyEnd = new DateTimeImmutable((string) $row['ends_at']);
                if ($start < $busyEnd && $end > $busyStart) {
                    return true;
                }
            } catch (Throwable) {
            }
        }
        return false;
    }

    private function appointment(int $tenantId, int $appointmentId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT a.*, ct.name AS contact_name, ct.phone, ct.email
             FROM calendar_appointments a
             LEFT JOIN contacts ct ON ct.id = a.contact_id
             WHERE a.id = :id AND a.tenant_id = :tenant_id
             LIMIT 1'
        );
        $statement->execute(['id' => $appointmentId, 'tenant_id' => $tenantId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findRequest(int $requestId, string $requestToken): ?array
    {
        if ($requestId > 0) {
            $statement = Database::connection()->prepare('SELECT * FROM calendar_availability_requests WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $requestId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                if ($requestToken === '' || hash_equals((string) $row['request_token'], $requestToken)) {
                    return $row;
                }
            }
        }
        if ($requestToken !== '') {
            $statement = Database::connection()->prepare('SELECT * FROM calendar_availability_requests WHERE request_token = :token LIMIT 1');
            $statement->execute(['token' => $requestToken]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        return null;
    }

    private function findSlot(int $tenantId, int $appointmentId, int $slotId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM calendar_availability_slots
             WHERE id = :id AND tenant_id = :tenant_id AND appointment_id = :appointment_id
             LIMIT 1'
        );
        $statement->execute(['id' => $slotId, 'tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function updateAppointmentAvailability(int $tenantId, int $appointmentId, string $status, ?int $requestId, ?int $slotCount, ?string $error): void
    {
        try {
            if (!$this->hasColumn('calendar_appointments', 'availability_status')) {
                return;
            }
            $statement = Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET availability_status = :status,
                     availability_request_id = :request_id,
                     availability_slot_count = COALESCE(:slot_count, availability_slot_count),
                     availability_error = :error,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $statement->execute([
                'status' => $status,
                'request_id' => $requestId,
                'slot_count' => $slotCount,
                'error' => $error,
                'id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        } catch (Throwable) {
        }
    }

    private function toSqlDateTime(string $value): ?string
    {
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $timestamp = strtotime($value);
            return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
        }
    }

    private function normalizeHour(string $value, string $fallback): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $match)) {
            return str_pad((string) (int) $match[1], 2, '0', STR_PAD_LEFT) . ':' . $match[2];
        }
        return $fallback;
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table'
            );
            $statement->execute(['table' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
