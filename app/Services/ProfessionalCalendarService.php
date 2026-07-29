<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database;
use PDO;
use Throwable;

/**
 * Agenda individual opcional por profissional.
 *
 * O recurso não atribui conversas automaticamente. Quando habilitado, apenas
 * permite selecionar o profissional do compromisso e aplicar as regras de
 * disponibilidade daquele usuário.
 */
final class ProfessionalCalendarService
{
    public function tenantSettings(int $tenantId): array
    {
        $defaults = [
            'enabled' => false,
            'require_owner' => true,
            'auto_from_conversation' => false,
            'prevent_contact_overlap' => true,
        ];
        if ($tenantId < 1 || !$this->hasColumn('tenants', 'professional_calendar_enabled')) {
            return $defaults;
        }

        try {
            $contactOverlapSelect = $this->hasColumn('tenants', 'professional_calendar_prevent_contact_overlap')
                ? 'professional_calendar_prevent_contact_overlap'
                : '1 AS professional_calendar_prevent_contact_overlap';
            $statement = Database::connection()->prepare(
                'SELECT professional_calendar_enabled,
                        professional_calendar_require_owner,
                        professional_calendar_auto_from_conversation,
                        ' . $contactOverlapSelect . '
                 FROM tenants WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'enabled' => (int) ($row['professional_calendar_enabled'] ?? 0) === 1,
                'require_owner' => (int) ($row['professional_calendar_require_owner'] ?? 1) === 1,
                'auto_from_conversation' => (int) ($row['professional_calendar_auto_from_conversation'] ?? 0) === 1,
                'prevent_contact_overlap' => (int) ($row['professional_calendar_prevent_contact_overlap'] ?? 1) === 1,
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    public function saveTenantSettings(int $tenantId, array $data): void
    {
        if ($tenantId < 1 || !$this->hasColumn('tenants', 'professional_calendar_enabled')) {
            throw new \RuntimeException('Execute a migration 065 antes de ativar a agenda por profissional.');
        }

        $enabled = !empty($data['professional_calendar_enabled']) ? 1 : 0;
        $requireOwner = !empty($data['professional_calendar_require_owner']) ? 1 : 0;
        $autoFromConversation = !empty($data['professional_calendar_auto_from_conversation']) ? 1 : 0;
        $preventContactOverlap = !empty($data['professional_calendar_prevent_contact_overlap']) ? 1 : 0;
        if ($enabled !== 1) {
            $autoFromConversation = 0;
        }

        $sets = [
            'professional_calendar_enabled = :enabled',
            'professional_calendar_require_owner = :require_owner',
            'professional_calendar_auto_from_conversation = :auto_from_conversation',
        ];
        $params = [
            'enabled' => $enabled,
            'require_owner' => $requireOwner,
            'auto_from_conversation' => $autoFromConversation,
            'tenant_id' => $tenantId,
        ];
        if ($this->hasColumn('tenants', 'professional_calendar_prevent_contact_overlap')) {
            $sets[] = 'professional_calendar_prevent_contact_overlap = :prevent_contact_overlap';
            $params['prevent_contact_overlap'] = $preventContactOverlap;
        }

        Database::connection()->prepare(
            'UPDATE tenants SET ' . implode(', ', $sets) . ' WHERE id = :tenant_id'
        )->execute($params);

        Audit::log('calendar.professional_settings_updated', [
            'enabled' => $enabled,
            'require_owner' => $requireOwner,
            'auto_from_conversation' => $autoFromConversation,
            'prevent_contact_overlap' => $preventContactOverlap,
        ], $tenantId);
    }

    /** @return array<int, array<string, mixed>> */
    public function teamProfiles(int $tenantId): array
    {
        if ($tenantId < 1) {
            return [];
        }
        $tenantAvailability = (new CalendarAvailabilityService())->settings($tenantId);
        try {
            $hasProfiles = $this->tableExists('user_calendar_profiles');
            $sql = $hasProfiles
                ? 'SELECT u.id, u.name, u.email, u.role, u.status,
                          p.accepting_appointments, p.timezone, p.google_calendar_id,
                          p.default_duration_minutes, p.slot_interval_minutes, p.buffer_minutes,
                          p.min_notice_hours, p.search_days_ahead, p.max_suggestions,
                          p.workdays_json, p.working_hours_json, p.updated_at AS profile_updated_at
                   FROM users u
                   LEFT JOIN user_calendar_profiles p ON p.user_id = u.id AND p.tenant_id = u.tenant_id
                   WHERE u.tenant_id = :tenant_id AND u.status = "active"
                   ORDER BY u.name'
                : 'SELECT u.id, u.name, u.email, u.role, u.status
                   FROM users u
                   WHERE u.tenant_id = :tenant_id AND u.status = "active"
                   ORDER BY u.name';
            $statement = Database::connection()->prepare($sql);
            $statement->execute(['tenant_id' => $tenantId]);
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_map(fn (array $row): array => $this->hydrateProfile($row, $tenantAvailability), $rows);
        } catch (Throwable) {
            return [];
        }
    }

    public function profile(int $tenantId, int $userId, ?array $tenantAvailability = null): ?array
    {
        if ($tenantId < 1 || $userId < 1 || !$this->userBelongsToTenant($tenantId, $userId)) {
            return null;
        }
        $tenantAvailability ??= (new CalendarAvailabilityService())->settings($tenantId);
        try {
            if (!$this->tableExists('user_calendar_profiles')) {
                $statement = Database::connection()->prepare('SELECT id, name, email, role, status FROM users WHERE id = :id AND tenant_id = :tenant_id LIMIT 1');
            } else {
                $statement = Database::connection()->prepare(
                    'SELECT u.id, u.name, u.email, u.role, u.status,
                            p.accepting_appointments, p.timezone, p.google_calendar_id,
                            p.default_duration_minutes, p.slot_interval_minutes, p.buffer_minutes,
                            p.min_notice_hours, p.search_days_ahead, p.max_suggestions,
                            p.workdays_json, p.working_hours_json, p.updated_at AS profile_updated_at
                     FROM users u
                     LEFT JOIN user_calendar_profiles p ON p.user_id = u.id AND p.tenant_id = u.tenant_id
                     WHERE u.id = :id AND u.tenant_id = :tenant_id LIMIT 1'
                );
            }
            $statement->execute(['id' => $userId, 'tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ? $this->hydrateProfile($row, $tenantAvailability) : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function saveProfile(int $tenantId, int $userId, array $data): void
    {
        if (!$this->tableExists('user_calendar_profiles')) {
            throw new \RuntimeException('Execute a migration 065 antes de configurar horários individuais.');
        }
        if (!$this->userBelongsToTenant($tenantId, $userId)) {
            throw new \RuntimeException('O profissional selecionado não pertence à empresa.');
        }

        $tenantAvailability = (new CalendarAvailabilityService())->settings($tenantId);
        $current = $this->profile($tenantId, $userId, $tenantAvailability) ?: [];
        $postedDays = array_values(array_unique(array_filter(
            array_map('intval', (array) ($data['workdays'] ?? [])),
            static fn (int $day): bool => $day >= 0 && $day <= 6
        )));
        sort($postedDays);

        $starts = is_array($data['working_start'] ?? null) ? $data['working_start'] : [];
        $ends = is_array($data['working_end'] ?? null) ? $data['working_end'] : [];
        $currentHours = json_decode((string) ($current['working_hours_json'] ?? '{}'), true);
        $currentByDay = is_array($currentHours) && isset($currentHours['by_day']) && is_array($currentHours['by_day'])
            ? $currentHours['by_day']
            : [];
        $byDay = [];
        foreach (range(0, 6) as $day) {
            $fallbackStart = (string) ($currentByDay[(string) $day]['start'] ?? '08:00');
            $fallbackEnd = (string) ($currentByDay[(string) $day]['end'] ?? ($day === 6 ? '12:00' : '18:00'));
            $start = $this->normalizeHour((string) ($starts[$day] ?? $starts[(string) $day] ?? $fallbackStart), $fallbackStart);
            $end = $this->normalizeHour((string) ($ends[$day] ?? $ends[(string) $day] ?? $fallbackEnd), $fallbackEnd);
            $enabled = in_array($day, $postedDays, true);
            if ($enabled && $end <= $start) {
                throw new \RuntimeException('O horário final precisa ser posterior ao inicial em cada dia ativo.');
            }
            $byDay[(string) $day] = ['enabled' => $enabled ? 1 : 0, 'start' => $start, 'end' => $end];
        }

        $timezone = trim((string) ($data['timezone'] ?? $current['timezone'] ?? $tenantAvailability['timezone'] ?? 'America/Sao_Paulo'));
        if ($timezone === '') {
            $timezone = 'America/Sao_Paulo';
        }
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            throw new \RuntimeException('Informe um fuso horário válido, como America/Sao_Paulo.');
        }
        $calendarId = trim((string) ($data['google_calendar_id'] ?? ''));

        Database::connection()->prepare(
            'INSERT INTO user_calendar_profiles
                (user_id, tenant_id, accepting_appointments, timezone, google_calendar_id,
                 default_duration_minutes, slot_interval_minutes, buffer_minutes,
                 min_notice_hours, search_days_ahead, max_suggestions,
                 workdays_json, working_hours_json)
             VALUES
                (:user_id, :tenant_id, :accepting_appointments, :timezone, :google_calendar_id,
                 :default_duration_minutes, :slot_interval_minutes, :buffer_minutes,
                 :min_notice_hours, :search_days_ahead, :max_suggestions,
                 :workdays_json, :working_hours_json)
             ON DUPLICATE KEY UPDATE
                tenant_id = VALUES(tenant_id),
                accepting_appointments = VALUES(accepting_appointments),
                timezone = VALUES(timezone),
                google_calendar_id = VALUES(google_calendar_id),
                default_duration_minutes = VALUES(default_duration_minutes),
                slot_interval_minutes = VALUES(slot_interval_minutes),
                buffer_minutes = VALUES(buffer_minutes),
                min_notice_hours = VALUES(min_notice_hours),
                search_days_ahead = VALUES(search_days_ahead),
                max_suggestions = VALUES(max_suggestions),
                workdays_json = VALUES(workdays_json),
                working_hours_json = VALUES(working_hours_json),
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'accepting_appointments' => !empty($data['accepting_appointments']) ? 1 : 0,
            'timezone' => mb_substr($timezone, 0, 80),
            'google_calendar_id' => $calendarId !== '' ? mb_substr($calendarId, 0, 255) : null,
            'default_duration_minutes' => max(15, min(240, (int) ($data['default_duration_minutes'] ?? 50))),
            'slot_interval_minutes' => max(5, min(240, (int) ($data['slot_interval_minutes'] ?? 30))),
            'buffer_minutes' => max(0, min(180, (int) ($data['buffer_minutes'] ?? 10))),
            'min_notice_hours' => max(0, min(720, (int) ($data['min_notice_hours'] ?? 4))),
            'search_days_ahead' => max(1, min(90, (int) ($data['search_days_ahead'] ?? 14))),
            'max_suggestions' => max(1, min(20, (int) ($data['max_suggestions'] ?? 5))),
            'workdays_json' => json_encode($postedDays, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'working_hours_json' => json_encode(['by_day' => $byDay], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        Audit::log('calendar.professional_profile_updated', ['user_id' => $userId], $tenantId);
    }

    /**
     * Resolve as regras aplicáveis ao compromisso. Quando a agenda individual
     * está desativada, devolve as configurações gerais sem mudar o comportamento.
     */
    public function contextForAppointment(int $tenantId, array $appointment, array $tenantAvailability, bool $allowPaused = false): array
    {
        $tenantSettings = $this->tenantSettings($tenantId);
        if (empty($tenantSettings['enabled'])) {
            return [
                'ok' => true,
                'settings' => $tenantAvailability,
                'professional' => null,
                'tenant_settings' => $tenantSettings,
            ];
        }

        $ownerUserId = (int) ($appointment['owner_user_id'] ?? 0);
        if ($ownerUserId < 1) {
            if (!empty($tenantSettings['require_owner'])) {
                return [
                    'ok' => false,
                    'code' => 'professional_required',
                    'message' => 'Selecione o profissional responsável antes de consultar a disponibilidade.',
                    'settings' => $tenantAvailability,
                    'professional' => null,
                    'tenant_settings' => $tenantSettings,
                ];
            }
            return [
                'ok' => true,
                'settings' => $tenantAvailability,
                'professional' => null,
                'tenant_settings' => $tenantSettings,
            ];
        }

        $profile = $this->profile($tenantId, $ownerUserId, $tenantAvailability);
        if (!$profile) {
            return [
                'ok' => false,
                'code' => 'professional_invalid',
                'message' => 'O profissional selecionado não está ativo nesta empresa.',
                'settings' => $tenantAvailability,
                'professional' => null,
                'tenant_settings' => $tenantSettings,
            ];
        }
        if (!$allowPaused && empty($profile['accepting_appointments'])) {
            return [
                'ok' => false,
                'code' => 'professional_unavailable',
                'message' => $profile['name'] . ' não está recebendo novos agendamentos no momento.',
                'settings' => $tenantAvailability,
                'professional' => $profile,
                'tenant_settings' => $tenantSettings,
            ];
        }

        $settings = array_merge($tenantAvailability, [
            'timezone' => $profile['timezone'],
            'google_calendar_id' => $profile['google_calendar_id'] !== ''
                ? $profile['google_calendar_id']
                : (string) ($tenantAvailability['google_calendar_id'] ?? 'primary'),
            'default_duration_minutes' => (int) $profile['default_duration_minutes'],
            'slot_interval_minutes' => (int) $profile['slot_interval_minutes'],
            'buffer_minutes' => (int) $profile['buffer_minutes'],
            'min_notice_hours' => (int) $profile['min_notice_hours'],
            'search_days_ahead' => (int) $profile['search_days_ahead'],
            'max_suggestions' => (int) $profile['max_suggestions'],
            'workdays_json' => (string) $profile['workdays_json'],
            'working_hours_json' => (string) $profile['working_hours_json'],
            'professional_user_id' => $ownerUserId,
            'professional_name' => (string) $profile['name'],
        ]);

        return [
            'ok' => true,
            'settings' => $settings,
            'professional' => $profile,
            'tenant_settings' => $tenantSettings,
        ];
    }

    public function conflict(int $tenantId, int $ownerUserId, string $startsAt, string $endsAt, int $ignoreAppointmentId = 0): ?array
    {
        if ($tenantId < 1 || $ownerUserId < 1 || $startsAt === '' || $endsAt === '') {
            return null;
        }
        try {
            $sql = 'SELECT id, title, starts_at, ends_at
                    FROM calendar_appointments
                    WHERE tenant_id = :tenant_id
                      AND owner_user_id = :owner_user_id
                      AND status IN ("scheduled", "confirmed")
                      AND starts_at < :ends_at
                      AND ends_at > :starts_at';
            $params = [
                'tenant_id' => $tenantId,
                'owner_user_id' => $ownerUserId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
            if ($ignoreAppointmentId > 0) {
                $sql .= ' AND id <> :ignore_id';
                $params['ignore_id'] = $ignoreAppointmentId;
            }
            $sql .= ' ORDER BY starts_at LIMIT 1';
            $statement = Database::connection()->prepare($sql);
            $statement->execute($params);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Impede o mesmo contato de ocupar dois atendimentos sobrepostos,
     * mesmo quando os profissionais são diferentes.
     */
    public function contactConflict(
        int $tenantId,
        int $contactId,
        string $startsAt,
        string $endsAt,
        int $ignoreAppointmentId = 0
    ): ?array {
        if ($tenantId < 1 || $contactId < 1 || $startsAt === '' || $endsAt === '') {
            return null;
        }

        try {
            $sql = 'SELECT a.id, a.title, a.starts_at, a.ends_at, a.status, a.owner_user_id,
                           u.name AS professional_name
                    FROM calendar_appointments a
                    LEFT JOIN users u ON u.id = a.owner_user_id AND u.tenant_id = a.tenant_id
                    WHERE a.tenant_id = :tenant_id
                      AND a.contact_id = :contact_id
                      AND (
                            a.status IN ("scheduled", "confirmed")
                            OR (
                                a.status IN ("pre_scheduled", "awaiting_approval")
                                AND (
                                    COALESCE(a.pre_schedule_source, "") = "manual"
                                    OR (
                                        COALESCE(a.preferred_day_text, "") <> ""
                                        AND COALESCE(a.preferred_time_text, "") <> ""
                                    )
                                    OR COALESCE(a.chosen_availability_slot_id, 0) > 0
                                    OR COALESCE(a.availability_status, "") IN ("slot_selected", "validated")
                                )
                            )
                      )
                      AND a.starts_at < :ends_at
                      AND a.ends_at > :starts_at';
            $params = [
                'tenant_id' => $tenantId,
                'contact_id' => $contactId,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];
            if ($ignoreAppointmentId > 0) {
                $sql .= ' AND a.id <> :ignore_id';
                $params['ignore_id'] = $ignoreAppointmentId;
            }
            $sql .= ' ORDER BY a.starts_at LIMIT 1';

            $statement = Database::connection()->prepare($sql);
            $statement->execute($params);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function contactConflictMessage(array $conflict): string
    {
        $professional = trim((string) ($conflict['professional_name'] ?? ''));
        $title = trim((string) ($conflict['title'] ?? 'outro atendimento')) ?: 'outro atendimento';
        $startsAt = trim((string) ($conflict['starts_at'] ?? ''));

        $message = 'Este cliente já possui “' . $title . '”';
        if ($professional !== '') {
            $message .= ' com ' . $professional;
        }
        if ($startsAt !== '' && strtotime($startsAt) !== false) {
            $message .= ' em ' . date('d/m/Y H:i', strtotime($startsAt));
        }

        return $message . '. Escolha outro horário.';
    }

    public function contactConflictCustomerMessage(array $conflict): string
    {
        $startsAt = trim((string) ($conflict['starts_at'] ?? ''));
        $when = $startsAt !== '' && strtotime($startsAt) !== false
            ? ' em ' . date('d/m/Y \à\s H:i', strtotime($startsAt))
            : ' nesse horário';

        return 'Você já possui um atendimento marcado' . $when . '. Envie outra opção de dia ou horário, por favor.';
    }

    public function userBelongsToTenant(int $tenantId, int $userId): bool
    {
        if ($tenantId < 1 || $userId < 1) {
            return false;
        }
        try {
            $statement = Database::connection()->prepare('SELECT COUNT(*) FROM users WHERE id = :id AND tenant_id = :tenant_id AND status = "active"');
            $statement->execute(['id' => $userId, 'tenant_id' => $tenantId]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function hydrateProfile(array $row, array $tenantAvailability): array
    {
        $fallbackWorkdays = (string) ($tenantAvailability['workdays_json'] ?? json_encode([1, 2, 3, 4, 5]));
        $fallbackHours = (string) ($tenantAvailability['working_hours_json'] ?? json_encode(['start' => '08:00', 'end' => '18:00']));
        return array_merge($row, [
            'id' => (int) ($row['id'] ?? 0),
            'accepting_appointments' => !array_key_exists('accepting_appointments', $row) || $row['accepting_appointments'] === null
                ? 1
                : (int) $row['accepting_appointments'],
            'timezone' => trim((string) ($row['timezone'] ?? '')) ?: (string) ($tenantAvailability['timezone'] ?? 'America/Sao_Paulo'),
            'google_calendar_id' => trim((string) ($row['google_calendar_id'] ?? '')),
            'default_duration_minutes' => (int) ($row['default_duration_minutes'] ?? $tenantAvailability['default_duration_minutes'] ?? 50),
            'slot_interval_minutes' => (int) ($row['slot_interval_minutes'] ?? $tenantAvailability['slot_interval_minutes'] ?? 30),
            'buffer_minutes' => (int) ($row['buffer_minutes'] ?? $tenantAvailability['buffer_minutes'] ?? 10),
            'min_notice_hours' => (int) ($row['min_notice_hours'] ?? $tenantAvailability['min_notice_hours'] ?? 4),
            'search_days_ahead' => (int) ($row['search_days_ahead'] ?? $tenantAvailability['search_days_ahead'] ?? 14),
            'max_suggestions' => (int) ($row['max_suggestions'] ?? $tenantAvailability['max_suggestions'] ?? 5),
            'workdays_json' => (string) ($row['workdays_json'] ?? $fallbackWorkdays),
            'working_hours_json' => (string) ($row['working_hours_json'] ?? $fallbackHours),
            'profile_configured' => !empty($row['profile_updated_at']),
        ]);
    }

    private function normalizeHour(string $value, string $fallback): string
    {
        $value = trim($value);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            return $fallback;
        }
        return $value;
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
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
            );
            $statement->execute(['table' => $table, 'column' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
