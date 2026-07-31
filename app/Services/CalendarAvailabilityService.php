<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Crypto;
use App\Core\Database;
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
            'availability_mode' => 'free_slots',
            'require_before_approval' => 1,
            'auto_request_on_pre_schedule' => 1,
            'use_n8n' => 1,
            'use_internal_fallback' => 1,
            'n8n_webhook_url' => '',
            'free_slots_webhook_url' => '',
            'marked_events_webhook_url' => '',
            'calendar_event_webhook_url' => '',
            'secret_token' => '',
            'google_calendar_id' => 'primary',
            'timezone' => 'America/Sao_Paulo',
            'google_utc_offset' => '-03:00',
            'ignore_transparent_events' => 1,
            'marked_require_transparent' => 0,
            'marked_online_title' => 'VAGO — ONLINE',
            'marked_in_person_title' => 'VAGO — PRESENCIAL',
            'marked_hold_prefix' => 'PRÉ-RESERVADO',
            'marked_confirmed_prefix' => 'AGENDADO',
            'hold_minutes' => 30,
            'revalidate_before_update' => 1,
            'restore_on_cancel' => 1,
            'create_google_event_on_confirm' => 1,
            'require_google_sync_on_confirm' => 1,
            'update_google_event_on_reschedule' => 1,
            'delete_google_event_on_cancel' => 1,
            'maintenance_enabled' => 1,
            'maintenance_interval_minutes' => 10,
            'max_sync_attempts' => 3,
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

            foreach ([
                'n8n_webhook_url_encrypted' => 'n8n_webhook_url',
                'free_slots_webhook_url_encrypted' => 'free_slots_webhook_url',
                'marked_events_webhook_url_encrypted' => 'marked_events_webhook_url',
                'calendar_event_webhook_url_encrypted' => 'calendar_event_webhook_url',
                'secret_token_encrypted' => 'secret_token',
            ] as $encryptedKey => $plainKey) {
                if (!empty($row[$encryptedKey])) {
                    $row[$plainKey] = Crypto::decrypt((string) $row[$encryptedKey]) ?: '';
                }
                unset($row[$encryptedKey]);
            }

            $row['availability_mode'] = $this->normalizeMode((string) ($row['availability_mode'] ?? 'free_slots'));
            return array_merge($defaults, $row);
        } catch (Throwable) {
            return $defaults;
        }
    }

    /** @param array<string, mixed> $data */
    public function configureInternalMode(int $tenantId, array $data): void
    {
        $current = $this->settings($tenantId);
        $existingHours = json_decode((string) ($current['working_hours_json'] ?? '{}'), true);
        $existingByDay = is_array($existingHours) && isset($existingHours['by_day']) && is_array($existingHours['by_day'])
            ? $existingHours['by_day']
            : [];

        $postedDays = array_values(array_unique(array_filter(array_map('intval', (array) ($data['internal_days'] ?? [])), static fn (int $day): bool => $day >= 0 && $day <= 6)));
        if ($postedDays === []) {
            if ($existingByDay !== []) {
                foreach ($existingByDay as $day => $row) {
                    if (!empty($row['enabled'])) {
                        $postedDays[] = (int) $day;
                    }
                }
            }
            if ($postedDays === []) {
                $postedDays = [1, 2, 3, 4, 5];
            }
        }
        sort($postedDays);

        $starts = is_array($data['internal_start'] ?? null) ? $data['internal_start'] : [];
        $ends = is_array($data['internal_end'] ?? null) ? $data['internal_end'] : [];
        $byDay = [];
        foreach (range(0, 6) as $day) {
            $fallbackStart = (string) ($existingByDay[(string) $day]['start'] ?? $existingByDay[$day]['start'] ?? ($day === 6 ? '08:00' : '08:00'));
            $fallbackEnd = (string) ($existingByDay[(string) $day]['end'] ?? $existingByDay[$day]['end'] ?? ($day === 6 ? '12:00' : '18:00'));
            $start = $this->normalizeHour((string) ($starts[$day] ?? $starts[(string) $day] ?? $fallbackStart), $fallbackStart);
            $end = $this->normalizeHour((string) ($ends[$day] ?? $ends[(string) $day] ?? $fallbackEnd), $fallbackEnd);
            $enabled = in_array($day, $postedDays, true);
            if ($enabled && $end <= $start) {
                throw new \RuntimeException('O horário final deve ser posterior ao inicial em todos os dias ativos da Agenda interna.');
            }
            $byDay[(string) $day] = ['enabled' => $enabled ? 1 : 0, 'start' => $start, 'end' => $end];
        }

        $firstDay = $postedDays[0];
        $payload = array_merge($current, [
            'enabled' => 1,
            'availability_mode' => 'free_slots',
            'require_before_approval' => 1,
            'auto_request_on_pre_schedule' => 1,
            'use_n8n' => 0,
            'use_internal_fallback' => 1,
            'create_google_event_on_confirm' => 0,
            'require_google_sync_on_confirm' => 0,
            'update_google_event_on_reschedule' => 0,
            'delete_google_event_on_cancel' => 0,
            'maintenance_enabled' => 0,
            'default_duration_minutes' => max(15, min(240, (int) ($data['default_duration_minutes'] ?? $current['default_duration_minutes'] ?? 60))),
            'slot_interval_minutes' => max(5, min(240, (int) ($data['slot_interval_minutes'] ?? $current['slot_interval_minutes'] ?? 30))),
            'buffer_minutes' => max(0, min(180, (int) ($data['buffer_minutes'] ?? $current['buffer_minutes'] ?? 0))),
            'search_days_ahead' => max(1, min(90, (int) ($data['search_days_ahead'] ?? $current['search_days_ahead'] ?? 30))),
            'min_notice_hours' => max(0, min(720, (int) ($data['min_notice_hours'] ?? $current['min_notice_hours'] ?? 2))),
            'max_suggestions' => max(1, min(20, (int) ($data['max_suggestions'] ?? $current['max_suggestions'] ?? 5))),
            'workdays' => $postedDays,
            'working_start' => $byDay[(string) $firstDay]['start'],
            'working_end' => $byDay[(string) $firstDay]['end'],
        ]);
        $this->saveSettings($tenantId, $payload, true);

        Database::connection()->prepare(
            'UPDATE tenant_calendar_availability_settings
             SET working_hours_json = :hours,
                 use_n8n = 0,
                 use_internal_fallback = 1,
                 create_google_event_on_confirm = 0,
                 require_google_sync_on_confirm = 0,
                 update_google_event_on_reschedule = 0,
                 delete_google_event_on_cancel = 0,
                 maintenance_enabled = 0,
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id'
        )->execute([
            'hours' => json_encode(['by_day' => $byDay], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'tenant_id' => $tenantId,
        ]);
    }

    public function configureSmartMode(int $tenantId): void
    {
        $current = $this->settings($tenantId);
        $payload = array_merge($current, [
            'enabled' => 1,
            'use_n8n' => 1,
            'workdays' => array_map('intval', (array) (json_decode((string) ($current['workdays_json'] ?? '[]'), true) ?: [1, 2, 3, 4, 5])),
            'working_start' => '08:00',
            'working_end' => '18:00',
        ]);
        $hours = json_decode((string) ($current['working_hours_json'] ?? '{}'), true);
        if (is_array($hours) && empty($hours['by_day'])) {
            $payload['working_start'] = (string) ($hours['start'] ?? '08:00');
            $payload['working_end'] = (string) ($hours['end'] ?? '18:00');
        }
        $this->saveSettings($tenantId, $payload, true);
    }

    public function disableAvailability(int $tenantId): void
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_calendar_availability_settings')) {
            return;
        }
        Database::connection()->prepare(
            'UPDATE tenant_calendar_availability_settings SET enabled = 0, updated_at = NOW() WHERE tenant_id = :tenant_id'
        )->execute(['tenant_id' => $tenantId]);
    }

    public function saveSettings(int $tenantId, array $data, bool $canManageIntegration = true): void
    {
        if ($tenantId < 1 || !$this->tableExists('tenant_calendar_availability_settings')) {
            return;
        }
        if (!$this->hasColumn('tenant_calendar_availability_settings', 'availability_mode')) {
            throw new \RuntimeException('Execute a migration 030 antes de salvar os modos do Google Agenda.');
        }

        $current = $this->settings($tenantId);
        $mode = $this->normalizeMode((string) ($data['availability_mode'] ?? $current['availability_mode'] ?? 'free_slots'));
        $duration = max(15, min(240, (int) ($data['default_duration_minutes'] ?? 50)));
        $interval = max(5, min(240, (int) ($data['slot_interval_minutes'] ?? 30)));
        $buffer = max(0, min(180, (int) ($data['buffer_minutes'] ?? 10)));
        $searchDays = max(1, min(90, (int) ($data['search_days_ahead'] ?? 14)));
        $notice = max(0, min(720, (int) ($data['min_notice_hours'] ?? 4)));
        $maxSuggestions = max(1, min(200, (int) ($data['max_suggestions'] ?? 5)));
        $holdMinutes = max(5, min(1440, (int) ($data['hold_minutes'] ?? 30)));

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

        // Integração é administrada somente pela operação RS. Ao salvar como usuário da empresa,
        // os campos técnicos que não aparecem na tela são preservados.
        $freeSlotsUrl = $canManageIntegration
            ? trim((string) ($data['free_slots_webhook_url'] ?? ''))
            : trim((string) ($current['free_slots_webhook_url'] ?? ''));
        $markedEventsUrl = $canManageIntegration
            ? trim((string) ($data['marked_events_webhook_url'] ?? ''))
            : trim((string) ($current['marked_events_webhook_url'] ?? ''));
        $legacyUrl = $canManageIntegration
            ? trim((string) ($data['n8n_webhook_url'] ?? ''))
            : trim((string) ($current['n8n_webhook_url'] ?? ''));
        if ($legacyUrl === '') {
            $legacyUrl = $mode === 'marked_events' ? $markedEventsUrl : $freeSlotsUrl;
        }
        $secret = $canManageIntegration
            ? trim((string) ($data['secret_token'] ?? ''))
            : trim((string) ($current['secret_token'] ?? ''));
        $calendarId = $canManageIntegration
            ? trim((string) ($data['google_calendar_id'] ?? 'primary'))
            : trim((string) ($current['google_calendar_id'] ?? 'primary'));
        $timezone = $canManageIntegration
            ? trim((string) ($data['timezone'] ?? 'America/Sao_Paulo'))
            : trim((string) ($current['timezone'] ?? 'America/Sao_Paulo'));
        $utcOffset = $canManageIntegration
            ? trim((string) ($data['google_utc_offset'] ?? '-03:00'))
            : trim((string) ($current['google_utc_offset'] ?? '-03:00'));
        if (preg_match('/^[+-](0\d|1\d|2[0-3]):[0-5]\d$/', $utcOffset) !== 1) {
            $utcOffset = '-03:00';
        }
        $useN8n = $canManageIntegration ? !empty($data['use_n8n']) : !empty($current['use_n8n']);
        $useInternalFallback = $canManageIntegration ? !empty($data['use_internal_fallback']) : !empty($current['use_internal_fallback']);
        $calendarEventUrl = $canManageIntegration
            ? trim((string) ($data['calendar_event_webhook_url'] ?? ''))
            : trim((string) ($current['calendar_event_webhook_url'] ?? ''));
        $createGoogleEvent = $canManageIntegration ? !empty($data['create_google_event_on_confirm']) : !empty($current['create_google_event_on_confirm']);
        $requireGoogleSync = $canManageIntegration ? !empty($data['require_google_sync_on_confirm']) : !empty($current['require_google_sync_on_confirm']);
        $updateGoogleEvent = $canManageIntegration ? !empty($data['update_google_event_on_reschedule']) : !empty($current['update_google_event_on_reschedule']);
        $deleteGoogleEvent = $canManageIntegration ? !empty($data['delete_google_event_on_cancel']) : !empty($current['delete_google_event_on_cancel']);
        $maintenanceEnabled = $canManageIntegration ? !empty($data['maintenance_enabled']) : !empty($current['maintenance_enabled']);
        $maintenanceInterval = $canManageIntegration
            ? max(5, min(1440, (int) ($data['maintenance_interval_minutes'] ?? 10)))
            : max(5, min(1440, (int) ($current['maintenance_interval_minutes'] ?? 10)));
        $maxSyncAttempts = $canManageIntegration
            ? max(1, min(10, (int) ($data['max_sync_attempts'] ?? 3)))
            : max(1, min(10, (int) ($current['max_sync_attempts'] ?? 3)));

        $statement = Database::connection()->prepare(
            'INSERT INTO tenant_calendar_availability_settings
                (tenant_id, enabled, availability_mode, require_before_approval, auto_request_on_pre_schedule,
                 use_n8n, use_internal_fallback, n8n_webhook_url_encrypted, free_slots_webhook_url_encrypted,
                 marked_events_webhook_url_encrypted, secret_token_encrypted, google_calendar_id, timezone,
                 google_utc_offset, ignore_transparent_events, marked_require_transparent, marked_online_title,
                 marked_in_person_title, marked_hold_prefix, marked_confirmed_prefix, hold_minutes,
                 revalidate_before_update, restore_on_cancel, default_duration_minutes, slot_interval_minutes,
                 buffer_minutes, search_days_ahead, workdays_json, working_hours_json, min_notice_hours, max_suggestions)
             VALUES
                (:tenant_id, :enabled, :availability_mode, :require_before_approval, :auto_request_on_pre_schedule,
                 :use_n8n, :use_internal_fallback, :n8n_webhook_url_encrypted, :free_slots_webhook_url_encrypted,
                 :marked_events_webhook_url_encrypted, :secret_token_encrypted, :google_calendar_id, :timezone,
                 :google_utc_offset, :ignore_transparent_events, :marked_require_transparent, :marked_online_title,
                 :marked_in_person_title, :marked_hold_prefix, :marked_confirmed_prefix, :hold_minutes,
                 :revalidate_before_update, :restore_on_cancel, :default_duration_minutes, :slot_interval_minutes,
                 :buffer_minutes, :search_days_ahead, :workdays_json, :working_hours_json, :min_notice_hours, :max_suggestions)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                availability_mode = VALUES(availability_mode),
                require_before_approval = VALUES(require_before_approval),
                auto_request_on_pre_schedule = VALUES(auto_request_on_pre_schedule),
                use_n8n = VALUES(use_n8n),
                use_internal_fallback = VALUES(use_internal_fallback),
                n8n_webhook_url_encrypted = VALUES(n8n_webhook_url_encrypted),
                free_slots_webhook_url_encrypted = VALUES(free_slots_webhook_url_encrypted),
                marked_events_webhook_url_encrypted = VALUES(marked_events_webhook_url_encrypted),
                secret_token_encrypted = VALUES(secret_token_encrypted),
                google_calendar_id = VALUES(google_calendar_id),
                timezone = VALUES(timezone),
                google_utc_offset = VALUES(google_utc_offset),
                ignore_transparent_events = VALUES(ignore_transparent_events),
                marked_require_transparent = VALUES(marked_require_transparent),
                marked_online_title = VALUES(marked_online_title),
                marked_in_person_title = VALUES(marked_in_person_title),
                marked_hold_prefix = VALUES(marked_hold_prefix),
                marked_confirmed_prefix = VALUES(marked_confirmed_prefix),
                hold_minutes = VALUES(hold_minutes),
                revalidate_before_update = VALUES(revalidate_before_update),
                restore_on_cancel = VALUES(restore_on_cancel),
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
            'availability_mode' => $mode,
            'require_before_approval' => !empty($data['require_before_approval']) ? 1 : 0,
            'auto_request_on_pre_schedule' => !empty($data['auto_request_on_pre_schedule']) ? 1 : 0,
            'use_n8n' => $useN8n ? 1 : 0,
            'use_internal_fallback' => $useInternalFallback ? 1 : 0,
            'n8n_webhook_url_encrypted' => $legacyUrl !== '' ? Crypto::encrypt($legacyUrl) : null,
            'free_slots_webhook_url_encrypted' => $freeSlotsUrl !== '' ? Crypto::encrypt($freeSlotsUrl) : null,
            'marked_events_webhook_url_encrypted' => $markedEventsUrl !== '' ? Crypto::encrypt($markedEventsUrl) : null,
            'secret_token_encrypted' => $secret !== '' ? Crypto::encrypt($secret) : null,
            'google_calendar_id' => mb_substr($calendarId !== '' ? $calendarId : 'primary', 0, 255),
            'timezone' => mb_substr($timezone !== '' ? $timezone : 'America/Sao_Paulo', 0, 80),
            'google_utc_offset' => $utcOffset,
            'ignore_transparent_events' => !empty($data['ignore_transparent_events']) ? 1 : 0,
            'marked_require_transparent' => !empty($data['marked_require_transparent']) ? 1 : 0,
            'marked_online_title' => mb_substr(trim((string) ($data['marked_online_title'] ?? $current['marked_online_title'] ?? 'VAGO — ONLINE')) ?: 'VAGO — ONLINE', 0, 190),
            'marked_in_person_title' => mb_substr(trim((string) ($data['marked_in_person_title'] ?? $current['marked_in_person_title'] ?? 'VAGO — PRESENCIAL')) ?: 'VAGO — PRESENCIAL', 0, 190),
            'marked_hold_prefix' => mb_substr(trim((string) ($data['marked_hold_prefix'] ?? $current['marked_hold_prefix'] ?? 'PRÉ-RESERVADO')) ?: 'PRÉ-RESERVADO', 0, 120),
            'marked_confirmed_prefix' => mb_substr(trim((string) ($data['marked_confirmed_prefix'] ?? $current['marked_confirmed_prefix'] ?? 'AGENDADO')) ?: 'AGENDADO', 0, 120),
            'hold_minutes' => $holdMinutes,
            'revalidate_before_update' => !empty($data['revalidate_before_update']) ? 1 : 0,
            'restore_on_cancel' => !empty($data['restore_on_cancel']) ? 1 : 0,
            'default_duration_minutes' => $duration,
            'slot_interval_minutes' => $interval,
            'buffer_minutes' => $buffer,
            'search_days_ahead' => $searchDays,
            'workdays_json' => json_encode($workdays, JSON_UNESCAPED_SLASHES),
            'working_hours_json' => json_encode(['start' => $start, 'end' => $end], JSON_UNESCAPED_SLASHES),
            'min_notice_hours' => $notice,
            'max_suggestions' => $maxSuggestions,
        ]);

        if ($this->hasColumn('tenant_calendar_availability_settings', 'calendar_event_webhook_url_encrypted')) {
            Database::connection()->prepare(
                'UPDATE tenant_calendar_availability_settings
                 SET calendar_event_webhook_url_encrypted = :calendar_event_webhook_url_encrypted,
                     create_google_event_on_confirm = :create_google_event_on_confirm,
                     require_google_sync_on_confirm = :require_google_sync_on_confirm,
                     update_google_event_on_reschedule = :update_google_event_on_reschedule,
                     delete_google_event_on_cancel = :delete_google_event_on_cancel,
                     maintenance_enabled = :maintenance_enabled,
                     maintenance_interval_minutes = :maintenance_interval_minutes,
                     max_sync_attempts = :max_sync_attempts,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE tenant_id = :tenant_id'
            )->execute([
                'calendar_event_webhook_url_encrypted' => $calendarEventUrl !== '' ? Crypto::encrypt($calendarEventUrl) : null,
                'create_google_event_on_confirm' => $createGoogleEvent ? 1 : 0,
                'require_google_sync_on_confirm' => $requireGoogleSync ? 1 : 0,
                'update_google_event_on_reschedule' => $updateGoogleEvent ? 1 : 0,
                'delete_google_event_on_cancel' => $deleteGoogleEvent ? 1 : 0,
                'maintenance_enabled' => $maintenanceEnabled ? 1 : 0,
                'maintenance_interval_minutes' => $maintenanceInterval,
                'max_sync_attempts' => $maxSyncAttempts,
                'tenant_id' => $tenantId,
            ]);
        }
    }

    public function requestForAppointment(int $tenantId, int $appointmentId, string $origin = 'manual'): array
    {
        if ($tenantId < 1 || $appointmentId < 1) {
            return ['ok' => false, 'message' => 'Empresa ou agendamento inválido.'];
        }
        if (!$this->tableExists('calendar_availability_requests')) {
            return ['ok' => false, 'message' => 'Migration de disponibilidade ainda não foi executada.'];
        }
        if (!$this->hasColumn('calendar_availability_requests', 'availability_mode')) {
            return ['ok' => false, 'message' => 'Execute a migration 030 para ativar os modos do Google Agenda.'];
        }

        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['ok' => false, 'message' => 'Agendamento não encontrado.'];
        }

        $settings = $this->settings($tenantId);
        if (empty($settings['enabled'])) {
            return ['ok' => false, 'message' => 'A busca automática de horários ainda não está ativada para esta empresa.'];
        }
        $professionalContext = (new ProfessionalCalendarService())->contextForAppointment($tenantId, $appointment, $settings);
        if (empty($professionalContext['ok'])) {
            return [
                'ok' => false,
                'skipped' => true,
                'code' => (string) ($professionalContext['code'] ?? 'professional_unavailable'),
                'message' => (string) ($professionalContext['message'] ?? 'Não foi possível identificar o profissional do agendamento.'),
            ];
        }
        $settings = (array) ($professionalContext['settings'] ?? $settings);

        $requestedModality = $this->normalizeModality((string) ($appointment['appointment_modality'] ?? $appointment['location_type'] ?? 'indefinida'));
        if (!in_array($requestedModality, ['online', 'presencial'], true)) {
            return [
                'ok' => false,
                'skipped' => true,
                'code' => 'modality_required',
                'message' => 'Defina se o atendimento é online ou presencial antes de consultar a disponibilidade.',
            ];
        }

        $mode = $this->normalizeMode((string) ($settings['availability_mode'] ?? 'free_slots'));
        $token = bin2hex(random_bytes(16));
        $window = $this->searchWindow($settings, $appointment);
        $pdo = Database::connection();

        $pdo->prepare(
            'INSERT INTO calendar_availability_requests
                (tenant_id, appointment_id, request_token, origin, availability_mode, action_name, status,
                 preferred_day_text, preferred_time_text, search_start_at, search_end_at, duration_minutes,
                 timezone, requested_payload_json, requested_at)
             VALUES
                (:tenant_id, :appointment_id, :request_token, :origin, :availability_mode, "search", "pending",
                 :preferred_day_text, :preferred_time_text, :search_start_at, :search_end_at, :duration_minutes,
                 :timezone, NULL, NOW())'
        )->execute([
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'request_token' => $token,
            'origin' => mb_substr($origin, 0, 60),
            'availability_mode' => $mode,
            'preferred_day_text' => $appointment['preferred_day_text'] ?? null,
            'preferred_time_text' => $appointment['preferred_time_text'] ?? null,
            'search_start_at' => $window['start'],
            'search_end_at' => $window['end'],
            'duration_minutes' => (int) ($settings['default_duration_minutes'] ?? 50),
            'timezone' => (string) ($settings['timezone'] ?? 'America/Sao_Paulo'),
        ]);
        $requestId = (int) $pdo->lastInsertId();
        $payload = $this->buildPayload($requestId, $tenantId, $appointment, $settings, $token, $window, $origin);
        $pdo->prepare('UPDATE calendar_availability_requests SET requested_payload_json = :payload WHERE id = :id')
            ->execute([
                'id' => $requestId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

        $this->updateAppointmentAvailability($tenantId, $appointmentId, 'requested', $requestId, 0, null, $mode === 'marked_events' ? 'google_marked_slots' : 'google_free_slots');

        $sent = false;
        $errors = [];
        $results = [];
        if (!empty($settings['use_n8n'])) {
            $explicitUrl = $this->webhookUrlForMode($settings, $mode);
            $results = (new AutomationWebhookService())->dispatch(
                'calendar.availability.requested',
                $payload,
                $explicitUrl !== '' ? $explicitUrl : null,
                $tenantId,
                trim((string) ($settings['secret_token'] ?? '')) ?: null
            );
            foreach ($results as $result) {
                if (!empty($result['ok'])) {
                    $sent = true;
                } elseif (!empty($result['error'])) {
                    $errors[] = (string) $result['error'];
                }
            }
        }

        if ($sent) {
            // O callback pode concluir antes do POST síncrono do n8n retornar.
            // Nunca rebaixa received/empty para sent.
            $pdo->prepare(
                'UPDATE calendar_availability_requests
                 SET status = "sent", sent_at = COALESCE(sent_at, NOW()), error_message = NULL
                 WHERE id = :id AND responded_at IS NULL AND status IN ("pending", "sent")'
            )->execute(['id' => $requestId]);
            $this->logGoogleSync($tenantId, $appointmentId, $requestId, null, 'search', 'success', $settings, $payload, ['dispatch' => $results]);
            Audit::log('calendar.availability_requested', ['request_id' => $requestId, 'appointment_id' => $appointmentId, 'mode' => $mode], $tenantId);
            return [
                'ok' => true,
                'request_id' => $requestId,
                'message' => $mode === 'marked_events'
                    ? 'Consulta enviada ao fluxo de eventos VAGO. Aguarde o retorno dos horários marcados.'
                    : 'Consulta enviada ao fluxo de espaços livres. Aguarde o retorno dos horários.',
            ];
        }

        $latestRequest = $this->findRequest($requestId, $token);
        if ($latestRequest && (!empty($latestRequest['responded_at']) || in_array((string) ($latestRequest['status'] ?? ''), ['received', 'empty'], true))) {
            return [
                'ok' => true,
                'request_id' => $requestId,
                'message' => 'O fluxo concluiu pelo callback mesmo depois de a conexão síncrona encerrar.',
            ];
        }

        $timedOut = false;
        foreach ($errors as $dispatchError) {
            $normalizedError = mb_strtolower((string) $dispatchError);
            if (str_contains($normalizedError, 'timed out') || str_contains($normalizedError, 'timeout') || str_contains($normalizedError, 'tempo limite')) {
                $timedOut = true;
                break;
            }
        }
        if ($timedOut) {
            // Timeout do POST não significa falha do fluxo: o n8n pode continuar e devolver o callback.
            $pdo->prepare(
                'UPDATE calendar_availability_requests
                 SET status = "sent", sent_at = COALESCE(sent_at, NOW()), error_message = NULL
                 WHERE id = :id AND responded_at IS NULL AND status IN ("pending", "sent")'
            )->execute(['id' => $requestId]);
            $this->logGoogleSync($tenantId, $appointmentId, $requestId, null, 'search', 'pending', $settings, $payload, $results, 'Conexão síncrona encerrada; aguardando callback do n8n.');
            return [
                'ok' => true,
                'request_id' => $requestId,
                'message' => 'Consulta enviada. O n8n ainda está processando e o retorno será aplicado pelo callback.',
                'awaiting_callback' => true,
            ];
        }

        if ($mode === 'free_slots' && !empty($settings['use_internal_fallback'])) {
            $slots = $this->generateInternalSlots(
                $tenantId,
                $window,
                $settings,
                (int) ($appointment['owner_user_id'] ?? 0),
                (int) ($appointment['contact_id'] ?? 0),
                $appointmentId
            );
            $this->storeSlots($requestId, $tenantId, $appointmentId, $slots, 'internal_fallback');
            $internalOnly = empty($settings['use_n8n']);
            $message = $slots === []
                ? ($internalOnly ? 'Nenhum horário livre encontrado na Agenda interna.' : 'Nenhum horário livre encontrado pelo fallback interno.')
                : ($internalOnly ? 'Horários disponíveis gerados pela Agenda interna do RS Connect.' : 'O n8n não respondeu; horários gerados pelo fallback interno.');
            $request = $this->findRequest($requestId, $token) ?: [
                'id' => $requestId,
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId,
                'origin' => $origin,
            ];
            $conversation = (new CalendarConversationService())->handleAvailabilityResult($request, $message);
            return ['ok' => $slots !== [], 'request_id' => $requestId, 'message' => $message, 'conversation' => $conversation];
        }

        $error = mb_substr(implode(' | ', $errors) ?: (
            $mode === 'marked_events'
                ? 'O fluxo de eventos VAGO não respondeu. O fallback interno não é aplicado nesse modo.'
                : 'Nenhum fluxo n8n respondeu com sucesso.'
        ), 0, 700);
        $pdo->prepare('UPDATE calendar_availability_requests SET status = "failed", error_message = :error WHERE id = :id')
            ->execute(['id' => $requestId, 'error' => $error]);
        $this->updateAppointmentAvailability($tenantId, $appointmentId, 'failed', $requestId, null, $error, $mode === 'marked_events' ? 'google_marked_slots' : 'google_free_slots');
        $this->logGoogleSync($tenantId, $appointmentId, $requestId, null, 'search', 'error', $settings, $payload, $results, $error);
        return ['ok' => false, 'request_id' => $requestId, 'message' => $error];
    }

    public function handleCallback(array $payload, ?string $token = null, bool $deferConversation = false): array
    {
        if (!$this->tableExists('calendar_availability_requests') || !$this->tableExists('calendar_availability_slots')) {
            return ['ok' => false, 'message' => 'Tabelas de disponibilidade não encontradas.'];
        }

        $event = trim((string) ($payload['event'] ?? 'calendar.availability.result'));
        if ($event === 'calendar.free_slot.updated') {
            return (new CalendarGoogleLifecycleService())->handleCallback($payload, $token);
        }
        return $event === 'calendar.marked_slot.updated'
            ? $this->handleMarkedUpdateCallback($payload, $token)
            : $this->handleAvailabilityCallback($payload, $token, $deferConversation);
    }

    public function processDeferredConversation(int $requestId, string $requestToken, string $diagnostic = ''): array
    {
        if ($requestId < 1 || trim($requestToken) === '') {
            return ['handled' => false, 'code' => 'invalid_deferred_request'];
        }

        $request = $this->findRequest($requestId, trim($requestToken));
        if (!$request) {
            return ['handled' => false, 'code' => 'deferred_request_not_found'];
        }

        return (new CalendarConversationService())->handleAvailabilityResult($request, $diagnostic);
    }

    /**
     * Recupera consultas conversacionais cujo callback já chegou, mas cuja mensagem
     * de retorno não foi enviada, e reenvia solicitações que ficaram aguardando callback.
     * É chamada pela Fila rápida da IA para tornar a agenda resiliente a encerramentos
     * prematuros do request HTTP do callback.
     *
     * @return array<string,int>
     */
    public function recoverConversationalAvailability(int $limit = 25): array
    {
        $limit = max(1, min(200, $limit));
        $summary = [
            'missing_requests_started' => 0,
            'missing_request_errors' => 0,
            'retried_requests' => 0,
            'retry_errors' => 0,
            'results_processed' => 0,
            'messages_completed' => 0,
            'processing_errors' => 0,
        ];
        if (!$this->tableExists('calendar_availability_requests') || !$this->tableExists('calendar_appointments')) {
            return $summary;
        }

        $pdo = Database::connection();
        $retryMinutes = max(1, min(30, (int) \App\Core\Env::get('CALENDAR_AVAILABILITY_RETRY_MINUTES', 2)));

        // 0) Repara pré-agendamentos conversacionais que já prometeram consultar
        // disponibilidade, mas ficaram sem request atual. Esse cenário ocorria quando a
        // recuperação pós-horário chamava a IA geral e ela respondia "vou verificar"
        // sem passar pela máquina de Agenda. Também reinicia requests que terminaram em falha.
        try {
            $missingSql = 'SELECT a.id, a.tenant_id, a.conversation_id, a.availability_request_id,
                                  a.availability_status, a.appointment_modality, a.location_type
                           FROM calendar_appointments a
                           INNER JOIN conversations c
                              ON c.id = a.conversation_id AND c.tenant_id = a.tenant_id
                           LEFT JOIN calendar_availability_requests current_request
                              ON current_request.id = a.availability_request_id
                             AND current_request.tenant_id = a.tenant_id
                           WHERE a.is_pre_schedule = 1
                             AND a.status IN ("pre_scheduled","awaiting_approval","rescheduled")
                             AND c.attendance_mode = "ai"
                             AND c.status <> "closed"
                             AND COALESCE(NULLIF(a.appointment_modality, ""), NULLIF(a.location_type, ""), "indefinida") IN ("online","presencial")
                             AND a.starts_at IS NOT NULL
                             AND a.ends_at IS NOT NULL
                             AND a.chosen_availability_slot_id IS NULL
                             AND a.availability_options_sent_at IS NULL
                             AND (
                                  a.availability_request_id IS NULL
                                  OR current_request.id IS NULL
                                  OR current_request.status = "failed"
                                  OR a.availability_status = "failed"
                             )
                             AND a.updated_at <= DATE_SUB(NOW(), INTERVAL ' . $retryMinutes . ' MINUTE)
                             AND a.updated_at >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
                           ORDER BY a.updated_at ASC, a.id ASC
                           LIMIT ' . $limit;
            $missing = $pdo->query($missingSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($missing as $appointment) {
                $tenantId = (int) ($appointment['tenant_id'] ?? 0);
                $appointmentId = (int) ($appointment['id'] ?? 0);
                $settings = $this->settings($tenantId);
                if (empty($settings['enabled']) || empty($settings['auto_request_on_pre_schedule'])) {
                    continue;
                }
                try {
                    $result = $this->requestForAppointment($tenantId, $appointmentId, 'conversation_recovery');
                    if (!empty($result['ok'])) {
                        $summary['missing_requests_started']++;
                        Audit::log('calendar.availability_missing_request_recovered', [
                            'appointment_id' => $appointmentId,
                            'previous_request_id' => (int) ($appointment['availability_request_id'] ?? 0),
                            'new_request_id' => (int) ($result['request_id'] ?? 0),
                        ], $tenantId);
                    } else {
                        $summary['missing_request_errors']++;
                    }
                } catch (Throwable $exception) {
                    $summary['missing_request_errors']++;
                    Audit::log('calendar.availability_missing_request_recovery_failed', [
                        'appointment_id' => $appointmentId,
                        'error' => mb_substr($exception->getMessage(), 0, 700),
                    ], $tenantId);
                }
            }
        } catch (Throwable) {
            $summary['missing_request_errors']++;
        }

        // 1) Se o n8n recebeu a busca, mas o callback não voltou, reenvia o MESMO request/token.
        // sent_at vira cooldown; não cria outra solicitação nem duplica o pré-agendamento.
        try {
            $retrySql = 'SELECT r.*, a.availability_request_id, a.status AS appointment_status,
                                a.appointment_modality, a.location_type
                         FROM calendar_availability_requests r
                         INNER JOIN calendar_appointments a
                            ON a.id = r.appointment_id AND a.tenant_id = r.tenant_id
                         WHERE r.origin IN ("pre_schedule_ai","conversation","whatsapp_ai")
                           AND r.status IN ("pending","sent")
                           AND r.responded_at IS NULL
                           AND a.is_pre_schedule = 1
                           AND a.status IN ("pre_scheduled","awaiting_approval","rescheduled")
                           AND a.availability_request_id = r.id
                           AND COALESCE(r.sent_at, r.requested_at, r.created_at) <= DATE_SUB(NOW(), INTERVAL ' . $retryMinutes . ' MINUTE)
                           AND r.created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
                         ORDER BY COALESCE(r.sent_at, r.requested_at, r.created_at) ASC
                         LIMIT ' . $limit;
            $requests = $pdo->query($retrySql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($requests as $request) {
                $payload = json_decode((string) ($request['requested_payload_json'] ?? ''), true);
                if (!is_array($payload) || $payload === []) {
                    $summary['retry_errors']++;
                    continue;
                }
                $tenantId = (int) ($request['tenant_id'] ?? 0);
                $settings = $this->settings($tenantId);
                $mode = $this->normalizeMode((string) ($request['availability_mode'] ?? $settings['availability_mode'] ?? 'free_slots'));
                $explicitUrl = $this->webhookUrlForMode($settings, $mode);
                if (empty($settings['enabled']) || empty($settings['use_n8n']) || $explicitUrl === '') {
                    $summary['retry_errors']++;
                    continue;
                }

                $results = (new AutomationWebhookService())->dispatch(
                    'calendar.availability.requested',
                    $payload,
                    $explicitUrl,
                    $tenantId,
                    trim((string) ($settings['secret_token'] ?? '')) ?: null
                );
                $sent = false;
                foreach ($results as $result) {
                    if (!empty($result['ok'])) {
                        $sent = true;
                        break;
                    }
                }
                if ($sent) {
                    $summary['retried_requests']++;
                    $pdo->prepare(
                        'UPDATE calendar_availability_requests
                         SET status = CASE WHEN responded_at IS NULL THEN "sent" ELSE status END,
                             sent_at = CASE WHEN responded_at IS NULL THEN NOW() ELSE sent_at END,
                             error_message = CASE WHEN responded_at IS NULL THEN NULL ELSE error_message END
                         WHERE id = :id'
                    )->execute(['id' => (int) $request['id']]);
                    Audit::log('calendar.availability_request_retried', [
                        'request_id' => (int) $request['id'],
                        'appointment_id' => (int) $request['appointment_id'],
                    ], $tenantId);
                } else {
                    $summary['retry_errors']++;
                }
            }
        } catch (Throwable) {
            $summary['retry_errors']++;
        }

        // 2) O callback pode ter gravado os horários mas o PHP/Apache encerrar a execução
        // antes de handleAvailabilityResult enviar a resposta ao WhatsApp. Reprocessa aqui.
        try {
            $completedSql = 'SELECT r.*
                             FROM calendar_availability_requests r
                             INNER JOIN calendar_appointments a
                                ON a.id = r.appointment_id AND a.tenant_id = r.tenant_id
                             WHERE r.origin IN ("pre_schedule_ai","conversation","whatsapp_ai")
                               AND r.status IN ("received","empty")
                               AND r.responded_at IS NOT NULL
                               AND a.is_pre_schedule = 1
                               AND a.status IN ("pre_scheduled","awaiting_approval","rescheduled")
                               AND a.availability_request_id = r.id
                               AND (
                                    a.availability_options_request_id IS NULL
                                    OR a.availability_options_request_id <> r.id
                                    OR a.availability_options_sent_at IS NULL
                               )
                               AND r.responded_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                             ORDER BY r.responded_at ASC
                             LIMIT ' . $limit;
            $completed = $pdo->query($completedSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($completed as $request) {
                try {
                    $result = (new CalendarConversationService())->handleAvailabilityResult($request, 'Recuperado automaticamente pela fila rápida.');
                    $summary['results_processed']++;
                    if (!empty($result['handled'])) {
                        $summary['messages_completed']++;
                    }
                } catch (Throwable $exception) {
                    $summary['processing_errors']++;
                    Audit::log('calendar.availability_conversation_recovery_failed', [
                        'request_id' => (int) ($request['id'] ?? 0),
                        'error' => mb_substr($exception->getMessage(), 0, 700),
                    ], (int) ($request['tenant_id'] ?? 0));
                }
            }
        } catch (Throwable) {
            $summary['processing_errors']++;
        }

        return $summary;
    }

    public function applySlot(int $tenantId, int $appointmentId, int $slotId): array
    {
        $slot = $this->findSlot($tenantId, $appointmentId, $slotId);
        if (!$slot) {
            return ['ok' => false, 'message' => 'Horário não encontrado.'];
        }

        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['ok' => false, 'message' => 'Pré-agendamento não encontrado.'];
        }

        $professionalService = new ProfessionalCalendarService();
        $professionalSettings = $professionalService->tenantSettings($tenantId);
        if (!empty($professionalSettings['enabled'])) {
            $ownerUserId = (int) ($appointment['owner_user_id'] ?? 0);
            if ($ownerUserId > 0) {
                $professionalConflict = $professionalService->conflict(
                    $tenantId,
                    $ownerUserId,
                    (string) ($slot['starts_at'] ?? ''),
                    (string) ($slot['ends_at'] ?? ''),
                    $appointmentId
                );
                if ($professionalConflict) {
                    return [
                        'ok' => false,
                        'message' => 'O profissional já possui “' . (string) ($professionalConflict['title'] ?? 'outro compromisso') . '” nesse horário.',
                    ];
                }
            }

            if (!empty($professionalSettings['prevent_contact_overlap'])
                && (int) ($appointment['contact_id'] ?? 0) > 0) {
                $contactConflict = $professionalService->contactConflict(
                    $tenantId,
                    (int) $appointment['contact_id'],
                    (string) ($slot['starts_at'] ?? ''),
                    (string) ($slot['ends_at'] ?? ''),
                    $appointmentId
                );
                if ($contactConflict) {
                    return ['ok' => false, 'message' => $professionalService->contactConflictMessage($contactConflict)];
                }
            }
        }

        $source = trim((string) ($slot['source'] ?? ''));
        $isMarked = $source === 'google_marked_slots' || trim((string) ($slot['google_event_id'] ?? '')) !== '';
        if ($isMarked) {
            $currentSlotId = (int) ($appointment['chosen_availability_slot_id'] ?? 0);
            if ($currentSlotId > 0 && $currentSlotId !== $slotId && in_array((string) ($appointment['google_event_state'] ?? ''), ['held', 'confirmed'], true)) {
                $release = $this->releaseMarkedAppointment($tenantId, $appointmentId, true);
                if (!empty($release['attempted']) && empty($release['ok'])) {
                    return ['ok' => false, 'message' => 'Não foi possível liberar o horário anterior: ' . ($release['message'] ?? 'erro desconhecido')];
                }
                $appointment = $this->appointment($tenantId, $appointmentId) ?: $appointment;
            }

            $this->updateAppointmentAvailability($tenantId, $appointmentId, 'hold_requested', (int) ($slot['request_id'] ?? 0), null, null, 'google_marked_slots');
            $dispatch = $this->dispatchMarkedAction('hold', $tenantId, $appointment, $slot);
            if (empty($dispatch['ok'])) {
                $this->updateAppointmentAvailability($tenantId, $appointmentId, 'received', (int) ($slot['request_id'] ?? 0), null, (string) ($dispatch['message'] ?? 'Falha ao pré-reservar.'), 'google_marked_slots');
                return ['ok' => false, 'message' => (string) ($dispatch['message'] ?? 'Não foi possível pré-reservar o evento VAGO.')];
            }

            $updated = $this->appointment($tenantId, $appointmentId);
            if (($updated['google_event_state'] ?? '') !== 'held' || (string) ($updated['google_event_id'] ?? '') !== (string) $slot['google_event_id']) {
                return ['ok' => false, 'message' => 'O n8n respondeu, mas o callback não confirmou a pré-reserva no RS Connect. Confira a execução do fluxo.'];
            }

            return ['ok' => true, 'message' => 'Horário pré-reservado no Google Agenda e aplicado ao pré-agendamento.'];
        }

        $pdo = Database::connection();
        $pdo->prepare('UPDATE calendar_availability_slots SET selected_at = NULL WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id')
            ->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $pdo->prepare(
            'UPDATE calendar_appointments
             SET starts_at = :starts_at,
                 ends_at = :ends_at,
                 availability_status = "slot_selected",
                 chosen_availability_slot_id = :slot_id,
                 availability_source = :availability_source,
                 appointment_modality = :modality,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :appointment_id AND tenant_id = :tenant_id'
        )->execute([
            'starts_at' => $slot['starts_at'],
            'ends_at' => $slot['ends_at'],
            'slot_id' => $slotId,
            'availability_source' => $source !== '' ? $source : 'n8n',
            'modality' => $this->normalizeModality((string) ($slot['modality'] ?? 'indefinida')),
            'appointment_id' => $appointmentId,
            'tenant_id' => $tenantId,
        ]);
        $pdo->prepare('UPDATE calendar_availability_slots SET selected_at = NOW(), event_state = "selected" WHERE id = :id')
            ->execute(['id' => $slotId]);
        Audit::log('calendar.availability_slot_selected', ['appointment_id' => $appointmentId, 'slot_id' => $slotId, 'source' => $source], $tenantId);
        return ['ok' => true, 'message' => 'Horário aplicado ao pré-agendamento. Agora ele pode ser aprovado.'];
    }

    public function releaseSelectedSlot(int $tenantId, int $appointmentId): array
    {
        return $this->releaseMarkedAppointment($tenantId, $appointmentId, true);
    }

    public function confirmMarkedAppointment(int $tenantId, int $appointmentId): array
    {
        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['attempted' => false, 'ok' => false, 'message' => 'Pré-agendamento não encontrado.'];
        }
        if ((string) ($appointment['availability_source'] ?? '') !== 'google_marked_slots') {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }
        if ((string) ($appointment['google_event_state'] ?? '') === 'confirmed') {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }
        if (trim((string) ($appointment['google_event_id'] ?? '')) === '' || (string) ($appointment['google_event_state'] ?? '') !== 'held') {
            return ['attempted' => true, 'ok' => false, 'message' => 'O evento VAGO precisa estar pré-reservado antes da aprovação.'];
        }

        $slot = $this->findSelectedSlot($tenantId, $appointmentId);
        $dispatch = $this->dispatchMarkedAction('confirm', $tenantId, $appointment, $slot ?: []);
        if (empty($dispatch['ok'])) {
            return ['attempted' => true, 'ok' => false, 'message' => (string) ($dispatch['message'] ?? 'Não foi possível confirmar o evento no Google Agenda.')];
        }

        $updated = $this->appointment($tenantId, $appointmentId);
        if (($updated['google_event_state'] ?? '') !== 'confirmed') {
            return ['attempted' => true, 'ok' => false, 'message' => 'O callback do n8n não confirmou a atualização do evento no Google Agenda.'];
        }
        return ['attempted' => true, 'ok' => true, 'message' => 'Evento confirmado no Google Agenda.'];
    }

    public function releaseMarkedAppointment(int $tenantId, int $appointmentId, bool $force = false): array
    {
        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['attempted' => false, 'ok' => false, 'message' => 'Pré-agendamento não encontrado.'];
        }
        if ((string) ($appointment['availability_source'] ?? '') !== 'google_marked_slots') {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }

        $settings = $this->settings($tenantId);
        if (!$force && empty($settings['restore_on_cancel'])) {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }
        if (trim((string) ($appointment['google_event_id'] ?? '')) === '') {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }
        if (!in_array((string) ($appointment['google_event_state'] ?? ''), ['held', 'confirmed', 'hold_requested', 'confirm_requested'], true)) {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }

        $slot = $this->findSelectedSlot($tenantId, $appointmentId);
        $dispatch = $this->dispatchMarkedAction('release', $tenantId, $appointment, $slot ?: []);
        if (empty($dispatch['ok'])) {
            return ['attempted' => true, 'ok' => false, 'message' => (string) ($dispatch['message'] ?? 'Não foi possível liberar o evento no Google Agenda.')];
        }

        $updated = $this->appointment($tenantId, $appointmentId);
        if (($updated['google_event_state'] ?? '') !== 'released') {
            return ['attempted' => true, 'ok' => false, 'message' => 'O callback do n8n não confirmou a liberação do evento VAGO.'];
        }
        return ['attempted' => true, 'ok' => true, 'message' => 'Evento restaurado como VAGO no Google Agenda.'];
    }

    /**
     * Remove da visualização normal do Google Agenda um evento VAGO que foi
     * pré-reservado ou confirmado. Diferente de releaseMarkedAppointment(), esta
     * ação não restaura o título VAGO: confirma o estado deleted antes da exclusão local.
     */
    public function deleteMarkedAppointment(int $tenantId, int $appointmentId): array
    {
        $appointment = $this->appointment($tenantId, $appointmentId);
        if (!$appointment) {
            return ['attempted' => false, 'ok' => false, 'message' => 'Agendamento não encontrado.'];
        }
        if ((string) ($appointment['availability_source'] ?? '') !== 'google_marked_slots') {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }

        $eventId = trim((string) ($appointment['google_event_id'] ?? ''));
        $state = trim((string) ($appointment['google_event_state'] ?? ''));
        if ($eventId === '' || $state === 'deleted') {
            return ['attempted' => false, 'ok' => true, 'message' => null];
        }

        $slot = $this->findSelectedSlot($tenantId, $appointmentId);
        $dispatch = $this->dispatchMarkedAction('delete', $tenantId, $appointment, $slot ?: []);
        if (empty($dispatch['ok'])) {
            return ['attempted' => true, 'ok' => false, 'message' => (string) ($dispatch['message'] ?? 'Não foi possível excluir o evento do Google Agenda.')];
        }

        $updated = $this->appointment($tenantId, $appointmentId);
        if (($updated['google_event_state'] ?? '') !== 'deleted' || trim((string) ($updated['google_event_id'] ?? '')) !== '') {
            return ['attempted' => true, 'ok' => false, 'message' => 'O callback do n8n não confirmou a exclusão do evento no Google Agenda.'];
        }
        return ['attempted' => true, 'ok' => true, 'message' => 'Evento removido do Google Agenda.'];
    }

    public function canApprove(int $tenantId, array $appointment): array
    {
        $settings = $this->settings($tenantId);
        $professionalContext = (new ProfessionalCalendarService())->contextForAppointment($tenantId, $appointment, $settings);
        if (empty($professionalContext['ok'])) {
            return ['ok' => false, 'message' => (string) ($professionalContext['message'] ?? 'Selecione um profissional disponível.')];
        }
        $settings = (array) ($professionalContext['settings'] ?? $settings);
        if (empty($settings['enabled']) || empty($settings['require_before_approval'])) {
            return ['ok' => true, 'message' => null];
        }
        if ((int) ($appointment['is_pre_schedule'] ?? 0) !== 1) {
            return ['ok' => true, 'message' => null];
        }

        $status = (string) ($appointment['availability_status'] ?? '');
        $chosenSlot = (int) ($appointment['chosen_availability_slot_id'] ?? 0);
        if (!in_array($status, ['slot_selected', 'validated'], true) || $chosenSlot < 1) {
            return ['ok' => false, 'message' => 'Antes de aprovar, busque disponibilidade e clique em “Usar este horário”.'];
        }

        if ((string) ($appointment['availability_source'] ?? '') === 'google_marked_slots'
            && !in_array((string) ($appointment['google_event_state'] ?? ''), ['held', 'confirmed'], true)) {
            return ['ok' => false, 'message' => 'O evento VAGO ainda não foi pré-reservado no Google Agenda.'];
        }
        return ['ok' => true, 'message' => null];
    }

    public function dashboard(int $tenantId): array
    {
        $settings = $this->settings($tenantId);
        $pdo = Database::connection();
        $pending = [];
        $requests = [];
        $slots = [];
        $googleLogs = [];
        $metrics = ['pending' => 0, 'requests' => 0, 'slots' => 0, 'selected' => 0, 'held' => 0];

        if ($tenantId > 0 && $this->tableExists('calendar_appointments')) {
            $statement = $pdo->prepare(
                'SELECT a.*, ct.name AS contact_name, ct.phone, u.name AS owner_name
                 FROM calendar_appointments a
                 LEFT JOIN contacts ct ON ct.id = a.contact_id
                 LEFT JOIN users u ON u.id = a.owner_user_id
                 WHERE a.tenant_id = :tenant_id
                   AND a.is_pre_schedule = 1
                   AND a.status IN ("pre_scheduled", "awaiting_approval", "rescheduled")
                 ORDER BY a.updated_at DESC, a.id DESC
                 LIMIT 80'
            );
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
                 INNER JOIN calendar_appointments a
                    ON a.id = s.appointment_id
                   AND a.tenant_id = s.tenant_id
                   AND a.availability_request_id = s.request_id
                 LEFT JOIN contacts ct ON ct.id = a.contact_id
                 WHERE s.tenant_id = :tenant_id
                 ORDER BY a.updated_at DESC, (s.selected_at IS NOT NULL) DESC, s.starts_at ASC, s.id DESC
                 LIMIT 160'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $slots = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        if ($tenantId > 0 && $this->tableExists('calendar_google_sync_logs')) {
            $statement = $pdo->prepare(
                'SELECT l.*, a.title AS appointment_title
                 FROM calendar_google_sync_logs l
                 LEFT JOIN calendar_appointments a ON a.id = l.appointment_id
                 WHERE l.tenant_id = :tenant_id
                 ORDER BY l.id DESC
                 LIMIT 40'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $googleLogs = $statement->fetchAll(PDO::FETCH_ASSOC);
        }

        $metrics['pending'] = count($pending);
        $metrics['requests'] = count($requests);
        $metrics['slots'] = count($slots);
        foreach ($slots as $slot) {
            if (!empty($slot['selected_at'])) {
                $metrics['selected']++;
            }
            if (($slot['event_state'] ?? '') === 'held') {
                $metrics['held']++;
            }
        }

        $activeUrl = $this->webhookUrlForMode($settings, $this->normalizeMode((string) ($settings['availability_mode'] ?? 'free_slots')));
        $latestRequest = $requests[0] ?? null;
        $latestResponseMeta = [];
        if (is_array($latestRequest) && !empty($latestRequest['response_payload_json'])) {
            $decodedResponse = json_decode((string) $latestRequest['response_payload_json'], true);
            if (is_array($decodedResponse) && isset($decodedResponse['meta']) && is_array($decodedResponse['meta'])) {
                $latestResponseMeta = $decodedResponse['meta'];
            }
        }
        $integration = [
            'n8n_enabled' => !empty($settings['use_n8n']),
            'active_url_configured' => $activeUrl !== '',
            'token_configured' => trim((string) ($settings['secret_token'] ?? '')) !== '',
            'calendar_configured' => trim((string) ($settings['google_calendar_id'] ?? '')) !== '',
            'active_mode' => $this->normalizeMode((string) ($settings['availability_mode'] ?? 'free_slots')),
            'last_status' => is_array($latestRequest) ? (string) ($latestRequest['status'] ?? '') : '',
            'last_error' => is_array($latestRequest) ? (string) ($latestRequest['error_message'] ?? '') : '',
            'last_at' => is_array($latestRequest) ? (string) ($latestRequest['responded_at'] ?? $latestRequest['requested_at'] ?? '') : '',
            'last_online_title' => trim((string) ($latestResponseMeta['online_title'] ?? '')),
            'last_in_person_title' => trim((string) ($latestResponseMeta['in_person_title'] ?? '')),
            'last_shared_title' => !empty($latestResponseMeta['shared_title']),
            'last_requested_modality' => trim((string) ($latestResponseMeta['requested_modality'] ?? '')),
            'last_event_titles' => array_values(array_filter(array_map('strval', (array) ($latestResponseMeta['event_titles_sample'] ?? [])))),
        ];

        $maintenance = (new CalendarGoogleLifecycleService())->maintenanceSummary($tenantId);
        return compact('settings', 'pending', 'requests', 'slots', 'googleLogs', 'metrics', 'integration', 'maintenance');
    }

    private function handleAvailabilityCallback(array $payload, ?string $token, bool $deferConversation = false): array
    {
        $requestId = (int) ($payload['request_id'] ?? $payload['availability_request_id'] ?? 0);
        $requestToken = trim((string) ($payload['request_token'] ?? $payload['callback_token'] ?? $token ?? ''));
        $request = $this->findRequest($requestId, $requestToken);
        if (!$request) {
            return ['ok' => false, 'message' => 'Solicitação de disponibilidade não encontrada ou token inválido.'];
        }
        if ((string) ($request['status'] ?? '') === 'cancelled') {
            return [
                'ok' => true,
                'ignored' => 'cancelled_availability_request',
                'message' => 'Callback ignorado porque a preferência foi substituída por uma consulta mais recente.',
                'request_id' => (int) $request['id'],
            ];
        }

        $currentAppointment = $this->appointment((int) $request['tenant_id'], (int) $request['appointment_id']);
        $requestedModality = $this->normalizeModality((string) ($currentAppointment['appointment_modality'] ?? $currentAppointment['location_type'] ?? 'indefinida'));
        if (!in_array($requestedModality, ['online', 'presencial'], true)) {
            return [
                'ok' => true,
                'ignored' => 'modality_not_defined',
                'message' => 'Callback ignorado porque a modalidade ainda não foi definida como Online ou Presencial.',
                'request_id' => (int) $request['id'],
            ];
        }
        $currentRequestId = (int) ($currentAppointment['availability_request_id'] ?? 0);
        if ($currentRequestId > 0 && $currentRequestId !== (int) $request['id'] && $currentRequestId > (int) $request['id']) {
            return [
                'ok' => true,
                'ignored' => 'stale_availability_callback',
                'message' => 'Callback antigo ignorado porque já existe uma consulta mais recente para este pré-agendamento.',
                'request_id' => (int) $request['id'],
                'current_request_id' => $currentRequestId,
            ];
        }

        $slots = $payload['slots'] ?? $payload['available_slots'] ?? [];
        if (!is_array($slots)) {
            $slots = [];
        }

        $requestMode = $this->normalizeMode((string) ($request['availability_mode'] ?? 'free_slots'));
        $source = $requestMode === 'marked_events' ? 'google_marked_slots' : 'google_free_slots';
        $normalizedSlots = [];
        $deduplicated = [];
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
                $endSql = (new DateTimeImmutable($startSql))
                    ->add(new DateInterval('PT' . max(15, (int) $request['duration_minutes']) . 'M'))
                    ->format('Y-m-d H:i:s');
            }
            if ($endSql <= $startSql) {
                continue;
            }
            if (!empty($request['search_start_at']) && $startSql < (string) $request['search_start_at']) {
                continue;
            }
            if (!empty($request['search_end_at']) && $endSql > (string) $request['search_end_at']) {
                continue;
            }

            $googleEventId = trim((string) ($slot['google_event_id'] ?? ''));
            if ($requestMode === 'marked_events' && $googleEventId === '') {
                // O modo VAGO só aceita opções vinculadas a um evento real do Google.
                continue;
            }

            $modality = $this->normalizeModality((string) ($slot['modality'] ?? 'indefinida'));
            if ($modality === 'indefinida') {
                $modality = $requestedModality;
            }
            if ($modality !== $requestedModality) {
                continue;
            }
            $dedupeKey = $googleEventId !== ''
                ? 'google:' . $googleEventId
                : implode('|', [$startSql, $endSql, $modality]);
            if (isset($deduplicated[$dedupeKey])) {
                continue;
            }
            $deduplicated[$dedupeKey] = true;

            $normalizedSlots[] = [
                'start' => $startSql,
                'end' => $endSql,
                // Não confia no label vindo de fluxos antigos: ele podia chegar com deslocamento de fuso.
                'label' => $this->slotLabel($startSql, $modality),
                'source' => $source,
                'google_calendar_id' => trim((string) ($slot['google_calendar_id'] ?? $payload['google_calendar_id'] ?? '')) ?: null,
                'google_event_id' => $googleEventId !== '' ? $googleEventId : null,
                'google_event_etag' => trim((string) ($slot['google_event_etag'] ?? $slot['etag'] ?? '')) ?: null,
                'modality' => $modality,
                'event_summary' => trim((string) ($slot['event_summary'] ?? $slot['summary'] ?? '')) ?: null,
                'event_transparency' => trim((string) ($slot['event_transparency'] ?? $slot['transparency'] ?? '')) ?: null,
                'event_state' => 'available',
                'raw' => $slot,
            ];
        }

        $diagnostic = $this->availabilityDiagnostic($requestMode, $payload, count($normalizedSlots));
        $this->storeSlots(
            (int) $request['id'],
            (int) $request['tenant_id'],
            (int) $request['appointment_id'],
            $normalizedSlots,
            $source,
            $payload,
            $diagnostic
        );
        $this->logGoogleSync((int) $request['tenant_id'], (int) $request['appointment_id'], (int) $request['id'], null, 'callback', 'success', [], null, $payload);

        if ($deferConversation) {
            return [
                'ok' => true,
                'message' => $diagnostic !== '' ? $diagnostic : 'Disponibilidade registrada no RS Connect.',
                'slots' => count($normalizedSlots),
                '_deferred_conversation' => [
                    'request_id' => (int) $request['id'],
                    'request_token' => (string) $request['request_token'],
                    'diagnostic' => $diagnostic,
                ],
            ];
        }

        $conversation = (new CalendarConversationService())->handleAvailabilityResult($request, $diagnostic);
        return [
            'ok' => true,
            'message' => $diagnostic !== '' ? $diagnostic : 'Disponibilidade registrada no RS Connect.',
            'slots' => count($normalizedSlots),
            'conversation' => $conversation,
        ];
    }

    private function handleMarkedUpdateCallback(array $payload, ?string $token): array
    {
        $requestId = (int) ($payload['request_id'] ?? $payload['availability_request_id'] ?? 0);
        $requestToken = trim((string) ($payload['request_token'] ?? $payload['callback_token'] ?? $token ?? ''));
        $request = $this->findRequest($requestId, $requestToken);
        if (!$request) {
            return ['ok' => false, 'message' => 'Solicitação de disponibilidade não encontrada ou token inválido.'];
        }

        $tenantId = (int) $request['tenant_id'];
        $appointmentId = (int) ($payload['appointment_id'] ?? $request['appointment_id'] ?? 0);
        $googleEventId = trim((string) ($payload['google_event_id'] ?? ''));
        $state = trim((string) ($payload['state'] ?? ''));
        $action = trim((string) ($payload['action'] ?? ''));
        if ($appointmentId < 1 || $googleEventId === '' || !in_array($state, ['held', 'confirmed', 'released', 'deleted'], true)) {
            return ['ok' => false, 'message' => 'Callback de atualização do evento Google incompleto.'];
        }

        $slot = $this->findSlotByGoogleEvent($tenantId, $appointmentId, $googleEventId);
        $slotId = (int) ($slot['id'] ?? 0);
        $startsAt = $this->toSqlDateTime((string) ($payload['start'] ?? ($slot['starts_at'] ?? '')));
        $endsAt = $this->toSqlDateTime((string) ($payload['end'] ?? ($slot['ends_at'] ?? '')));
        $modality = $this->normalizeModality((string) ($payload['modality'] ?? ($slot['modality'] ?? 'indefinida')));
        $calendarId = trim((string) ($payload['google_calendar_id'] ?? ($slot['google_calendar_id'] ?? 'primary'))) ?: 'primary';
        $summary = mb_substr(trim((string) ($payload['current_summary'] ?? '')), 0, 255);
        $transparency = mb_substr(trim((string) ($payload['transparency'] ?? '')), 0, 30);
        $settings = $this->settings($tenantId);
        $holdExpiresAt = $state === 'held'
            ? date('Y-m-d H:i:s', time() + max(5, (int) ($settings['hold_minutes'] ?? 30)) * 60)
            : null;

        $pdo = Database::connection();
        if ($state === 'held') {
            $pdo->prepare('UPDATE calendar_availability_slots SET selected_at = NULL WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id')
                ->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
            if ($slotId > 0) {
                $pdo->prepare(
                    'UPDATE calendar_availability_slots
                     SET selected_at = NOW(), event_state = "held", hold_expires_at = :hold_expires_at,
                         event_summary = :event_summary, event_transparency = :event_transparency
                     WHERE id = :id'
                )->execute([
                    'id' => $slotId,
                    'hold_expires_at' => $holdExpiresAt,
                    'event_summary' => $summary !== '' ? $summary : null,
                    'event_transparency' => $transparency !== '' ? $transparency : null,
                ]);
            }
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET starts_at = COALESCE(:starts_at, starts_at),
                     ends_at = COALESCE(:ends_at, ends_at),
                     availability_status = "slot_selected",
                     availability_source = "google_marked_slots",
                     chosen_availability_slot_id = :slot_id,
                     google_calendar_id = :google_calendar_id,
                     google_event_id = :google_event_id,
                     google_event_state = "held",
                     google_event_summary = :google_event_summary,
                     appointment_modality = :modality,
                     location_type = CASE WHEN :modality_location_check IN ("online", "presencial") THEN :modality_location_value ELSE location_type END,
                     google_hold_expires_at = :hold_expires_at,
                     availability_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id AND tenant_id = :tenant_id'
            )->execute([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'slot_id' => $slotId > 0 ? $slotId : null,
                'google_calendar_id' => $calendarId,
                'google_event_id' => $googleEventId,
                'google_event_summary' => $summary !== '' ? $summary : null,
                'modality' => $modality,
                'modality_location_check' => $modality,
                'modality_location_value' => $modality,
                'hold_expires_at' => $holdExpiresAt,
                'appointment_id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        } elseif ($state === 'confirmed') {
            if ($slotId > 0) {
                $pdo->prepare(
                    'UPDATE calendar_availability_slots
                     SET event_state = "confirmed", hold_expires_at = NULL,
                         event_summary = :event_summary, event_transparency = :event_transparency
                     WHERE id = :id'
                )->execute([
                    'id' => $slotId,
                    'event_summary' => $summary !== '' ? $summary : null,
                    'event_transparency' => $transparency !== '' ? $transparency : null,
                ]);
            }
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET google_event_state = "confirmed",
                     google_event_summary = :google_event_summary,
                     google_hold_expires_at = NULL,
                     availability_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id AND tenant_id = :tenant_id'
            )->execute([
                'google_event_summary' => $summary !== '' ? $summary : null,
                'appointment_id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        } elseif ($state === 'deleted') {
            if ($slotId > 0) {
                $pdo->prepare(
                    'UPDATE calendar_availability_slots
                     SET selected_at = NULL, event_state = "deleted", hold_expires_at = NULL,
                         event_summary = NULL, event_transparency = NULL
                     WHERE id = :id'
                )->execute(['id' => $slotId]);
            }
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET chosen_availability_slot_id = NULL,
                     google_event_id = NULL,
                     google_event_state = "deleted",
                     google_event_summary = NULL,
                     google_hold_expires_at = NULL,
                     availability_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id AND tenant_id = :tenant_id'
            )->execute([
                'appointment_id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        } else {
            if ($slotId > 0) {
                $pdo->prepare(
                    'UPDATE calendar_availability_slots
                     SET selected_at = NULL, event_state = "released", hold_expires_at = NULL,
                         event_summary = :event_summary, event_transparency = :event_transparency
                     WHERE id = :id'
                )->execute([
                    'id' => $slotId,
                    'event_summary' => $summary !== '' ? $summary : null,
                    'event_transparency' => $transparency !== '' ? $transparency : null,
                ]);
            }
            $pdo->prepare(
                'UPDATE calendar_appointments
                 SET availability_status = "received",
                     chosen_availability_slot_id = NULL,
                     google_event_id = NULL,
                     google_event_state = "released",
                     google_event_summary = :google_event_summary,
                     google_hold_expires_at = NULL,
                     availability_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :appointment_id AND tenant_id = :tenant_id'
            )->execute([
                'google_event_summary' => $summary !== '' ? $summary : null,
                'appointment_id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        }

        $this->logGoogleSync($tenantId, $appointmentId, (int) $request['id'], $slotId ?: null, $action !== '' ? $action : 'callback', 'success', $settings, null, $payload);
        Audit::log('calendar.google_marked_slot_' . $state, ['appointment_id' => $appointmentId, 'google_event_id' => $googleEventId], $tenantId);
        return ['ok' => true, 'message' => 'Estado do evento Google atualizado no RS Connect.', 'state' => $state];
    }

    private function storeSlots(int $requestId, int $tenantId, int $appointmentId, array $slots, string $source, ?array $rawPayload = null, ?string $diagnostic = null): void
    {
        $pdo = Database::connection();
        $pdo->prepare('DELETE FROM calendar_availability_slots WHERE request_id = :request_id')->execute(['request_id' => $requestId]);
        $insert = $pdo->prepare(
            'INSERT INTO calendar_availability_slots
                (tenant_id, request_id, appointment_id, starts_at, ends_at, label, source,
                 google_calendar_id, google_event_id, google_event_etag, modality, event_summary,
                 event_transparency, event_state, raw_json)
             VALUES
                (:tenant_id, :request_id, :appointment_id, :starts_at, :ends_at, :label, :source,
                 :google_calendar_id, :google_event_id, :google_event_etag, :modality, :event_summary,
                 :event_transparency, :event_state, :raw_json)'
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
                'google_calendar_id' => !empty($slot['google_calendar_id']) ? mb_substr((string) $slot['google_calendar_id'], 0, 255) : null,
                'google_event_id' => !empty($slot['google_event_id']) ? mb_substr((string) $slot['google_event_id'], 0, 255) : null,
                'google_event_etag' => !empty($slot['google_event_etag']) ? mb_substr((string) $slot['google_event_etag'], 0, 255) : null,
                'modality' => $this->normalizeModality((string) ($slot['modality'] ?? 'indefinida')),
                'event_summary' => !empty($slot['event_summary']) ? mb_substr((string) $slot['event_summary'], 0, 255) : null,
                'event_transparency' => !empty($slot['event_transparency']) ? mb_substr((string) $slot['event_transparency'], 0, 30) : null,
                'event_state' => mb_substr((string) ($slot['event_state'] ?? 'available'), 0, 30),
                'raw_json' => json_encode($slot['raw'] ?? $slot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }

        $status = $slots === [] ? 'empty' : 'received';
        $pdo->prepare(
            'UPDATE calendar_availability_requests
             SET status = :status,
                 response_payload_json = :response_payload_json,
                 responded_at = NOW(),
                 error_message = :diagnostic
             WHERE id = :id'
        )->execute([
            'id' => $requestId,
            'status' => $status,
            'diagnostic' => $slots === [] && trim((string) $diagnostic) !== '' ? mb_substr((string) $diagnostic, 0, 700) : null,
            'response_payload_json' => json_encode($rawPayload ?? ['slots' => $slots, 'source' => $source], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $appointmentMessage = $slots === [] && trim((string) $diagnostic) !== '' ? mb_substr((string) $diagnostic, 0, 700) : null;
        $this->updateAppointmentAvailability($tenantId, $appointmentId, $status, $requestId, count($slots), $appointmentMessage, $source);
        Audit::log('calendar.availability_received', ['request_id' => $requestId, 'slots' => count($slots), 'source' => $source], $tenantId);
    }

    private function buildPayload(int $requestId, int $tenantId, array $appointment, array $settings, string $token, array $window, string $origin): array
    {
        $workdays = json_decode((string) ($settings['workdays_json'] ?? '[]'), true);
        if (!is_array($workdays) || $workdays === []) {
            $workdays = [1, 2, 3, 4, 5];
        }
        $hours = json_decode((string) ($settings['working_hours_json'] ?? '{}'), true);
        if (!is_array($hours)) {
            $hours = ['start' => '08:00', 'end' => '18:00'];
        }
        $mode = $this->normalizeMode((string) ($settings['availability_mode'] ?? 'free_slots'));

        return [
            'tenant_id' => $tenantId,
            'event' => 'calendar.availability.requested',
            'action' => 'search',
            'availability_mode' => $mode,
            'origin' => $origin,
            'appointment_id' => (int) $appointment['id'],
            'conversation_id' => !empty($appointment['conversation_id']) ? (int) $appointment['conversation_id'] : null,
            'contact_id' => !empty($appointment['contact_id']) ? (int) $appointment['contact_id'] : null,
            'owner_user_id' => !empty($appointment['owner_user_id']) ? (int) $appointment['owner_user_id'] : null,
            'professional' => !empty($appointment['owner_user_id']) ? [
                'user_id' => (int) $appointment['owner_user_id'],
                'name' => (string) ($settings['professional_name'] ?? ''),
                'calendar_id' => (string) ($settings['google_calendar_id'] ?? 'primary'),
            ] : null,
            'request_id' => $requestId,
            'request_token' => $token,
            'appointment' => $appointment,
            'preference' => [
                'day_text' => $appointment['preferred_day_text'] ?? null,
                'time_text' => $appointment['preferred_time_text'] ?? null,
                'starts_at' => $appointment['starts_at'] ?? null,
                'ends_at' => $appointment['ends_at'] ?? null,
                'modality' => $this->normalizeModality((string) ($appointment['appointment_modality'] ?? $appointment['location_type'] ?? 'indefinida')),
            ],
            'search' => [
                'start_at' => $window['start'],
                'end_at' => $window['end'],
                'duration_minutes' => (int) ($settings['default_duration_minutes'] ?? 50),
                'slot_interval_minutes' => (int) ($settings['slot_interval_minutes'] ?? 30),
                'buffer_minutes' => (int) ($settings['buffer_minutes'] ?? 10),
                'min_notice_hours' => (int) ($settings['min_notice_hours'] ?? 4),
                'timezone' => (string) ($settings['timezone'] ?? 'America/Sao_Paulo'),
                'utc_offset' => (string) ($settings['google_utc_offset'] ?? '-03:00'),
                'max_suggestions' => (int) ($settings['max_suggestions'] ?? 5),
                'workdays' => array_values(array_map('intval', $workdays)),
                'working_start' => $this->normalizeHour((string) ($hours['start'] ?? '08:00'), '08:00'),
                'working_end' => $this->normalizeHour((string) ($hours['end'] ?? '18:00'), '18:00'),
                'working_hours_by_day' => isset($hours['by_day']) && is_array($hours['by_day']) ? $hours['by_day'] : null,
                'calendar_id' => (string) ($settings['google_calendar_id'] ?? 'primary'),
                'ignore_transparent_events' => !empty($settings['ignore_transparent_events']),
                'marked_require_transparent' => !empty($settings['marked_require_transparent']),
                'marked_online_title' => (string) ($settings['marked_online_title'] ?? 'VAGO — ONLINE'),
                'marked_in_person_title' => (string) ($settings['marked_in_person_title'] ?? 'VAGO — PRESENCIAL'),
            ],
            'callback' => [
                'url' => Router::url('/webhooks/calendar/availability'),
                'token' => $token,
            ],
            'requested_at' => date('c'),
        ];
    }

    private function dispatchMarkedAction(string $action, int $tenantId, array $appointment, array $slot): array
    {
        if (!in_array($action, ['hold', 'confirm', 'release', 'delete'], true)) {
            return ['ok' => false, 'message' => 'Ação de evento Google inválida.'];
        }
        $settings = $this->settings($tenantId);
        $professionalContext = (new ProfessionalCalendarService())->contextForAppointment(
            $tenantId,
            $appointment,
            $settings,
            in_array($action, ['release', 'delete'], true)
        );
        if (empty($professionalContext['ok'])) {
            return ['ok' => false, 'message' => (string) ($professionalContext['message'] ?? 'Profissional indisponível para este agendamento.')];
        }
        $settings = (array) ($professionalContext['settings'] ?? $settings);
        $url = $this->webhookUrlForMode($settings, 'marked_events');
        if ($url === '') {
            return ['ok' => false, 'message' => 'Configure a URL do fluxo n8n “Eventos VAGO”.'];
        }

        $request = $this->latestRequestForAppointment($tenantId, (int) $appointment['id']);
        if (!$request) {
            return ['ok' => false, 'message' => 'Não foi encontrada a consulta de disponibilidade vinculada a este pré-agendamento.'];
        }
        $googleEventId = trim((string) ($slot['google_event_id'] ?? $appointment['google_event_id'] ?? ''));
        if ($googleEventId === '') {
            return ['ok' => false, 'message' => 'O horário selecionado não possui google_event_id. Faça uma nova busca.'];
        }

        $raw = [];
        if (!empty($slot['raw_json'])) {
            $decoded = json_decode((string) $slot['raw_json'], true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        $modality = $this->normalizeModality((string) ($slot['modality'] ?? $appointment['appointment_modality'] ?? $appointment['location_type'] ?? 'indefinida'));
        $payload = [
            'tenant_id' => $tenantId,
            'event' => 'calendar.marked_slot.action',
            'action' => $action,
            'availability_mode' => 'marked_events',
            'request_id' => (int) $request['id'],
            'request_token' => (string) $request['request_token'],
            'appointment_id' => (int) $appointment['id'],
            'conversation_id' => !empty($appointment['conversation_id']) ? (int) $appointment['conversation_id'] : null,
            'contact_id' => !empty($appointment['contact_id']) ? (int) $appointment['contact_id'] : null,
            'owner_user_id' => !empty($appointment['owner_user_id']) ? (int) $appointment['owner_user_id'] : null,
            'professional_name' => (string) ($settings['professional_name'] ?? ''),
            'calendar_id' => trim((string) ($slot['google_calendar_id'] ?? $appointment['google_calendar_id'] ?? $settings['google_calendar_id'] ?? 'primary')) ?: 'primary',
            'google_event_id' => $googleEventId,
            'google_event_etag' => trim((string) ($slot['google_event_etag'] ?? '')),
            'modality' => $modality,
            'customer_name' => trim((string) ($appointment['contact_name'] ?? 'Cliente')) ?: 'Cliente',
            'customer_phone' => trim((string) ($appointment['phone'] ?? '')),
            'notes' => trim((string) ($appointment['description'] ?? '')),
            'online_title' => (string) ($settings['marked_online_title'] ?? 'VAGO — ONLINE'),
            'in_person_title' => (string) ($settings['marked_in_person_title'] ?? 'VAGO — PRESENCIAL'),
            'hold_prefix' => (string) ($settings['marked_hold_prefix'] ?? 'PRÉ-RESERVADO'),
            'confirmed_prefix' => (string) ($settings['marked_confirmed_prefix'] ?? 'AGENDADO'),
            'require_transparent' => !empty($settings['marked_require_transparent']),
            'revalidate_before_update' => !empty($settings['revalidate_before_update']),
            'original_summary' => (string) ($raw['event_summary'] ?? $raw['summary'] ?? $slot['event_summary'] ?? ''),
            'original_description' => (string) ($raw['event_description'] ?? $raw['description'] ?? ''),
            'original_transparency' => (string) ($raw['event_transparency'] ?? $raw['transparency'] ?? $slot['event_transparency'] ?? 'transparent'),
            'callback' => [
                'url' => Router::url('/webhooks/calendar/availability'),
                'token' => (string) $request['request_token'],
            ],
            'requested_at' => date('c'),
        ];

        $this->updateAppointmentGoogleState($tenantId, (int) $appointment['id'], $action . '_requested', null);
        $results = (new AutomationWebhookService())->dispatch(
            'calendar.marked_slot.' . $action,
            $payload,
            $url,
            $tenantId,
            trim((string) ($settings['secret_token'] ?? '')) ?: null
        );
        $success = false;
        $errors = [];
        foreach ($results as $result) {
            if (!empty($result['ok'])) {
                $success = true;
            } elseif (!empty($result['error'])) {
                $errors[] = (string) $result['error'];
            }
        }

        if (!$success) {
            $error = mb_substr(implode(' | ', $errors) ?: 'O fluxo n8n não respondeu com sucesso.', 0, 700);
            $this->updateAppointmentGoogleState($tenantId, (int) $appointment['id'], 'error', $error);
            $this->logGoogleSync($tenantId, (int) $appointment['id'], (int) $request['id'], !empty($slot['id']) ? (int) $slot['id'] : null, $action, 'error', $settings, $payload, $results, $error);
            return ['ok' => false, 'message' => $error];
        }

        $this->logGoogleSync($tenantId, (int) $appointment['id'], (int) $request['id'], !empty($slot['id']) ? (int) $slot['id'] : null, $action, 'success', $settings, $payload, $results);
        return ['ok' => true, 'message' => 'Ação enviada ao n8n.'];
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
                    $start = $preferred->setTime(0, 0, 0);
                }
            } catch (Throwable) {
            }
        }
        $end = $start->add(new DateInterval('P' . max(1, (int) ($settings['search_days_ahead'] ?? 14)) . 'D'));
        return ['start' => $start->format('Y-m-d H:i:s'), 'end' => $end->format('Y-m-d H:i:s')];
    }

    private function generateInternalSlots(
        int $tenantId,
        array $window,
        array $settings,
        int $ownerUserId = 0,
        int $contactId = 0,
        int $ignoreAppointmentId = 0
    ): array
    {
        $timezone = new DateTimeZone((string) ($settings['timezone'] ?? 'America/Sao_Paulo'));
        $globalStart = new DateTimeImmutable($window['start'], $timezone);
        $globalEnd = new DateTimeImmutable($window['end'], $timezone);
        $duration = max(15, (int) ($settings['default_duration_minutes'] ?? 50));
        $step = max(5, (int) ($settings['slot_interval_minutes'] ?? 30));
        $buffer = max(0, (int) ($settings['buffer_minutes'] ?? 10));
        $max = max(1, (int) ($settings['max_suggestions'] ?? 5));
        $workdays = json_decode((string) ($settings['workdays_json'] ?? '[]'), true);
        if (!is_array($workdays) || $workdays === []) {
            $workdays = [1, 2, 3, 4, 5];
        }
        $workdays = array_map('intval', $workdays);
        $hours = json_decode((string) ($settings['working_hours_json'] ?? '{}'), true);
        if (!is_array($hours)) {
            $hours = ['start' => '08:00', 'end' => '18:00'];
        }
        $byDay = isset($hours['by_day']) && is_array($hours['by_day']) ? $hours['by_day'] : [];
        $defaultDayStart = $this->normalizeHour((string) ($hours['start'] ?? '08:00'), '08:00');
        $defaultDayEnd = $this->normalizeHour((string) ($hours['end'] ?? '18:00'), '18:00');
        $busy = $this->busyPeriods($tenantId, $window['start'], $window['end'], $ownerUserId);
        $professionalSettings = (new ProfessionalCalendarService())->tenantSettings($tenantId);
        if (!empty($professionalSettings['enabled'])
            && !empty($professionalSettings['prevent_contact_overlap'])
            && $contactId > 0) {
            $busy = array_merge(
                $busy,
                $this->contactBusyPeriods(
                    $tenantId,
                    $contactId,
                    $window['start'],
                    $window['end'],
                    $ignoreAppointmentId
                )
            );
        }

        $slots = [];
        $day = $globalStart->setTime(0, 0, 0);
        while ($day < $globalEnd && count($slots) < $max) {
            $weekday = (int) $day->format('w');
            $dayConfig = $byDay[(string) $weekday] ?? $byDay[$weekday] ?? null;
            $dayEnabled = is_array($dayConfig) ? !empty($dayConfig['enabled']) : in_array($weekday, $workdays, true);
            if ($dayEnabled) {
                $dayStart = $this->normalizeHour((string) (is_array($dayConfig) ? ($dayConfig['start'] ?? $defaultDayStart) : $defaultDayStart), $defaultDayStart);
                $dayEnd = $this->normalizeHour((string) (is_array($dayConfig) ? ($dayConfig['end'] ?? $defaultDayEnd) : $defaultDayEnd), $defaultDayEnd);
                $businessStart = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $dayStart, $timezone);
                $businessEnd = new DateTimeImmutable($day->format('Y-m-d') . ' ' . $dayEnd, $timezone);
                $cursor = $businessStart;
                while ($cursor < $businessEnd && count($slots) < $max) {
                    $slotEnd = $cursor->add(new DateInterval('PT' . $duration . 'M'));
                    if ($slotEnd > $businessEnd) {
                        break;
                    }
                    if ($cursor >= $globalStart && $slotEnd <= $globalEnd && !$this->overlapsBusy($cursor, $slotEnd, $busy, $buffer)) {
                        $slots[] = [
                            'start' => $cursor->format('Y-m-d H:i:s'),
                            'end' => $slotEnd->format('Y-m-d H:i:s'),
                            'label' => $cursor->format('d/m/Y H:i'),
                            'source' => 'internal_fallback',
                            'modality' => 'indefinida',
                            'event_state' => 'available',
                            'raw' => ['generated_by' => 'RS Connect fallback'],
                        ];
                    }
                    $cursor = $cursor->add(new DateInterval('PT' . $step . 'M'));
                }
            }
            $day = $day->modify('+1 day');
        }
        return $slots;
    }

    private function busyPeriods(int $tenantId, string $start, string $end, int $ownerUserId = 0): array
    {
        try {
            $sql = 'SELECT starts_at, ends_at FROM calendar_appointments
                    WHERE tenant_id = :tenant_id
                      AND status IN ("scheduled", "confirmed")
                      AND starts_at < :end_at
                      AND ends_at > :start_at';
            $params = ['tenant_id' => $tenantId, 'start_at' => $start, 'end_at' => $end];
            if ($ownerUserId > 0) {
                $sql .= ' AND owner_user_id = :owner_user_id';
                $params['owner_user_id'] = $ownerUserId;
            }
            $statement = Database::connection()->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function contactBusyPeriods(
        int $tenantId,
        int $contactId,
        string $start,
        string $end,
        int $ignoreAppointmentId = 0
    ): array {
        if ($tenantId < 1 || $contactId < 1) {
            return [];
        }

        try {
            $sql = 'SELECT starts_at, ends_at
                    FROM calendar_appointments
                    WHERE tenant_id = :tenant_id
                      AND contact_id = :contact_id
                      AND (
                            status IN ("scheduled", "confirmed")
                            OR (
                                status IN ("pre_scheduled", "awaiting_approval")
                                AND (
                                    COALESCE(pre_schedule_source, "") = "manual"
                                    OR (
                                        COALESCE(preferred_day_text, "") <> ""
                                        AND COALESCE(preferred_time_text, "") <> ""
                                    )
                                    OR COALESCE(chosen_availability_slot_id, 0) > 0
                                    OR COALESCE(availability_status, "") IN ("slot_selected", "validated")
                                )
                            )
                      )
                      AND starts_at < :end_at
                      AND ends_at > :start_at';
            $params = [
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'start_at' => $start,
                'end_at' => $end,
            ];
            if ($ignoreAppointmentId > 0) {
                $sql .= ' AND id <> :ignore_id';
                $params['ignore_id'] = $ignoreAppointmentId;
            }

            $statement = Database::connection()->prepare($sql);
            $statement->execute($params);
            return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function overlapsBusy(DateTimeImmutable $start, DateTimeImmutable $end, array $busy, int $bufferMinutes = 0): bool
    {
        foreach ($busy as $row) {
            try {
                $busyStart = new DateTimeImmutable((string) $row['starts_at']);
                $busyEnd = new DateTimeImmutable((string) $row['ends_at']);
                if ($bufferMinutes > 0) {
                    $busyStart = $busyStart->sub(new DateInterval('PT' . $bufferMinutes . 'M'));
                    $busyEnd = $busyEnd->add(new DateInterval('PT' . $bufferMinutes . 'M'));
                }
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
        if ($requestToken === '') {
            return null;
        }
        if ($requestId > 0) {
            $statement = Database::connection()->prepare('SELECT * FROM calendar_availability_requests WHERE id = :id LIMIT 1');
            $statement->execute(['id' => $requestId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row && hash_equals((string) $row['request_token'], $requestToken)) {
                return $row;
            }
        }
        $statement = Database::connection()->prepare('SELECT * FROM calendar_availability_requests WHERE request_token = :token LIMIT 1');
        $statement->execute(['token' => $requestToken]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function latestRequestForAppointment(int $tenantId, int $appointmentId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM calendar_availability_requests
             WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findSlot(int $tenantId, int $appointmentId, int $slotId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.*, r.request_token, r.availability_mode
             FROM calendar_availability_slots s
             INNER JOIN calendar_availability_requests r ON r.id = s.request_id
             WHERE s.id = :id AND s.tenant_id = :tenant_id AND s.appointment_id = :appointment_id
             LIMIT 1'
        );
        $statement->execute(['id' => $slotId, 'tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findSelectedSlot(int $tenantId, int $appointmentId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT s.*, r.request_token, r.availability_mode
             FROM calendar_availability_slots s
             INNER JOIN calendar_availability_requests r ON r.id = s.request_id
             WHERE s.tenant_id = :tenant_id AND s.appointment_id = :appointment_id
               AND (s.selected_at IS NOT NULL OR s.id = (
                    SELECT chosen_availability_slot_id FROM calendar_appointments
                    WHERE id = :appointment_id_2 AND tenant_id = :tenant_id_2
               ))
             ORDER BY s.selected_at DESC, s.id DESC
             LIMIT 1'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'appointment_id_2' => $appointmentId,
            'tenant_id_2' => $tenantId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function findSlotByGoogleEvent(int $tenantId, int $appointmentId, string $googleEventId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT * FROM calendar_availability_slots
             WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id AND google_event_id = :google_event_id
             ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'google_event_id' => $googleEventId,
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function updateAppointmentAvailability(
        int $tenantId,
        int $appointmentId,
        string $status,
        ?int $requestId,
        ?int $slotCount,
        ?string $error,
        ?string $source = null
    ): void {
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
                     availability_source = COALESCE(:source, availability_source),
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $statement->execute([
                'status' => $status,
                'request_id' => $requestId,
                'slot_count' => $slotCount,
                'error' => $error,
                'source' => $source,
                'id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        } catch (Throwable) {
        }
    }

    private function updateAppointmentGoogleState(int $tenantId, int $appointmentId, string $state, ?string $error): void
    {
        try {
            Database::connection()->prepare(
                'UPDATE calendar_appointments
                 SET google_event_state = :state,
                     availability_error = :error,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND tenant_id = :tenant_id'
            )->execute([
                'state' => mb_substr($state, 0, 30),
                'error' => $error,
                'id' => $appointmentId,
                'tenant_id' => $tenantId,
            ]);
        } catch (Throwable) {
        }
    }

    private function logGoogleSync(
        int $tenantId,
        ?int $appointmentId,
        ?int $requestId,
        ?int $slotId,
        string $operation,
        string $status,
        array $settings = [],
        ?array $request = null,
        mixed $response = null,
        ?string $error = null
    ): void {
        if (!$this->tableExists('calendar_google_sync_logs')) {
            return;
        }
        try {
            Database::connection()->prepare(
                'INSERT INTO calendar_google_sync_logs
                    (tenant_id, appointment_id, request_id, slot_id, operation, status,
                     google_calendar_id, google_event_id, request_json, response_json, error_message)
                 VALUES
                    (:tenant_id, :appointment_id, :request_id, :slot_id, :operation, :status,
                     :google_calendar_id, :google_event_id, :request_json, :response_json, :error_message)'
            )->execute([
                'tenant_id' => $tenantId,
                'appointment_id' => $appointmentId && $appointmentId > 0 ? $appointmentId : null,
                'request_id' => $requestId && $requestId > 0 ? $requestId : null,
                'slot_id' => $slotId && $slotId > 0 ? $slotId : null,
                'operation' => mb_substr($operation, 0, 40),
                'status' => mb_substr($status, 0, 30),
                'google_calendar_id' => !empty($settings['google_calendar_id']) ? mb_substr((string) $settings['google_calendar_id'], 0, 255) : null,
                'google_event_id' => is_array($request) && !empty($request['google_event_id']) ? mb_substr((string) $request['google_event_id'], 0, 255) : null,
                'request_json' => $request !== null ? json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'response_json' => $response !== null ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'error_message' => $error !== null ? mb_substr($error, 0, 700) : null,
            ]);
        } catch (Throwable) {
        }
    }

    private function webhookUrlForMode(array $settings, string $mode): string
    {
        $mode = $this->normalizeMode($mode);
        $url = $mode === 'marked_events'
            ? trim((string) ($settings['marked_events_webhook_url'] ?? ''))
            : trim((string) ($settings['free_slots_webhook_url'] ?? ''));
        if ($url === '') {
            $url = trim((string) ($settings['n8n_webhook_url'] ?? ''));
        }
        return $url;
    }

    private function toSqlDateTime(string $value): ?string
    {
        if (trim($value) === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $timestamp = strtotime($value);
            return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
        }
    }

    private function slotLabel(string $startSql, string $modality = 'indefinida'): string
    {
        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $startSql);
            if (!$date) {
                $date = new DateTimeImmutable($startSql);
            }
            $label = $date->format('d/m/Y H:i');
            if ($modality === 'online') {
                return $label . ' · Online';
            }
            if ($modality === 'presencial') {
                return $label . ' · Presencial';
            }
            return $label;
        } catch (Throwable) {
            return $startSql;
        }
    }

    private function availabilityDiagnostic(string $mode, array $payload, int $slotCount): string
    {
        if ($slotCount > 0) {
            return $slotCount . ($slotCount === 1 ? ' horário disponível encontrado.' : ' horários disponíveis encontrados.');
        }

        $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];
        if ($mode === 'marked_events') {
            $eventsRead = (int) ($meta['events_read'] ?? 0);
            $titleMatches = (int) ($meta['title_matches'] ?? 0);
            $transparencyRejected = (int) ($meta['transparency_rejected'] ?? 0);
            $modalityRejected = (int) ($meta['modality_rejected'] ?? 0);
            $onlineTitle = trim((string) ($meta['online_title'] ?? ''));
            $inPersonTitle = trim((string) ($meta['in_person_title'] ?? ''));
            $configuredTitles = array_values(array_unique(array_filter([$onlineTitle, $inPersonTitle], static fn (string $title): bool => $title !== '')));
            $configuredLabel = $configuredTitles !== []
                ? '“' . implode('” e “', $configuredTitles) . '”'
                : 'os títulos configurados';
            $eventTitles = [];
            foreach ((array) ($meta['event_titles_sample'] ?? []) as $eventTitle) {
                $eventTitle = trim((string) $eventTitle);
                if ($eventTitle !== '' && !in_array($eventTitle, $eventTitles, true)) {
                    $eventTitles[] = $eventTitle;
                }
                if (count($eventTitles) >= 8) {
                    break;
                }
            }
            $foundLabel = $eventTitles !== []
                ? ' Títulos lidos: “' . implode('”, “', $eventTitles) . '”.'
                : '';

            if ($eventsRead === 0) {
                return 'O Google Agenda não retornou eventos no período pesquisado. Confira a data, a conta conectada e o ID do calendário.';
            }
            if ($titleMatches === 0) {
                return 'Foram lidos ' . $eventsRead . ' evento(s), mas nenhum correspondeu a ' . $configuredLabel . '.' . $foundLabel;
            }
            if ($transparencyRejected > 0) {
                return 'O título configurado foi encontrado, mas o evento está como Ocupado. No Google Agenda, altere “Mostrar como” para Disponível.';
            }
            if ($modalityRejected > 0) {
                return 'O título configurado foi encontrado, mas não corresponde à modalidade solicitada pelo cliente.';
            }
            if (!empty($meta['shared_title']) && (($meta['requested_modality'] ?? 'indefinida') === 'indefinida')) {
                return 'O título genérico foi encontrado, mas a modalidade ainda não está definida. Informe Online ou Presencial no pré-agendamento.';
            }
            return 'Nenhum evento VAGO válido foi encontrado para a preferência informada.';
        }

        $eventsRead = (int) ($meta['events_read'] ?? $meta['occupied_events_considered'] ?? 0);
        return 'Nenhum espaço livre foi encontrado dentro do expediente e das regras configuradas' . ($eventsRead > 0 ? ' após analisar ' . $eventsRead . ' compromisso(s).' : '.');
    }

    private function normalizeHour(string $value, string $fallback): string
    {
        if (preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($value), $match)) {
            return str_pad((string) (int) $match[1], 2, '0', STR_PAD_LEFT) . ':' . $match[2];
        }
        return $fallback;
    }

    private function normalizeMode(string $mode): string
    {
        return $mode === 'marked_events' ? 'marked_events' : 'free_slots';
    }

    private function normalizeModality(string $modality): string
    {
        $normalized = mb_strtolower(trim($modality));
        if (in_array($normalized, ['online', 'virtual'], true)) {
            return 'online';
        }
        if (in_array($normalized, ['presencial', 'in_person', 'in-person'], true)) {
            return 'presencial';
        }
        return 'indefinida';
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
