<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

/**
 * Política canônica de SLA da primeira resposta humana.
 *
 * A meta é tenant-wide nesta fase. O relógio pode contar tempo corrido ou
 * somente os intervalos de expediente configurados no onboarding. Os ciclos
 * recebem um snapshot da política para preservar a leitura histórica mesmo
 * quando a configuração futura for alterada.
 */
final class SlaPolicyService
{
    public const DEFAULT_TARGET_MINUTES = 30;
    public const DEFAULT_WARNING_PERCENT = 80;

    private PDO $pdo;
    /** @var array<int,array<string,mixed>> */
    private array $settingsCache = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    /** @return array<string,mixed> */
    public function settings(int $tenantId): array
    {
        if (isset($this->settingsCache[$tenantId])) {
            return $this->settingsCache[$tenantId];
        }

        $defaults = [
            'tenant_id' => $tenantId,
            'enabled' => 1,
            'target_minutes' => self::DEFAULT_TARGET_MINUTES,
            'warning_percent' => self::DEFAULT_WARNING_PERCENT,
            'count_outside_business_hours' => 0,
            'timezone' => 'America/Sao_Paulo',
            'business_hours_json' => $this->defaultBusinessHoursJson(),
            'updated_by_user_id' => null,
            'updated_at' => null,
        ];

        try {
            if ($this->tableExists('tenant_sla_settings')) {
                $statement = $this->pdo->prepare(
                    'SELECT * FROM tenant_sla_settings WHERE tenant_id = :tenant_id LIMIT 1'
                );
                $statement->execute(['tenant_id' => $tenantId]);
                $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($row !== []) {
                    return $this->settingsCache[$tenantId] = $this->normalizeSettings(array_merge($defaults, $row));
                }
            }

            if ($this->tableExists('tenant_onboarding_settings')) {
                $statement = $this->pdo->prepare(
                    'SELECT business_timezone, business_hours_json
                     FROM tenant_onboarding_settings
                     WHERE tenant_id = :tenant_id LIMIT 1'
                );
                $statement->execute(['tenant_id' => $tenantId]);
                $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
                if ($row !== []) {
                    $defaults['timezone'] = trim((string) ($row['business_timezone'] ?? '')) ?: $defaults['timezone'];
                    $defaults['business_hours_json'] = trim((string) ($row['business_hours_json'] ?? '')) ?: $defaults['business_hours_json'];
                }
            }
        } catch (Throwable) {
            // Configuração é tolerante durante janela de migration.
        }

        return $this->settingsCache[$tenantId] = $this->normalizeSettings($defaults);
    }

    /** @param array<string,mixed> $data */
    public function save(int $tenantId, array $data, ?int $userId = null): array
    {
        if ($tenantId < 1) {
            throw new \RuntimeException('Empresa inválida para configuração do SLA.');
        }

        $current = $this->settings($tenantId);
        $target = max(5, min(1440, (int) ($data['sla_target_minutes'] ?? $data['target_minutes'] ?? $current['target_minutes'])));
        $warning = max(50, min(99, (int) ($data['sla_warning_percent'] ?? $data['warning_percent'] ?? $current['warning_percent'])));
        $countOutside = !empty($data['sla_count_outside_hours']) || !empty($data['count_outside_business_hours']) ? 1 : 0;
        $timezone = Clock::safeTimezone(trim((string) ($data['business_timezone'] ?? $data['timezone'] ?? $current['timezone'])));
        $hoursJson = trim((string) ($data['business_hours_json'] ?? $current['business_hours_json']));
        if ($hoursJson === '' || json_decode($hoursJson, true) === null) {
            $hoursJson = $this->defaultBusinessHoursJson();
        }

        if (!$this->tableExists('tenant_sla_settings')) {
            throw new \RuntimeException('A migration da política de SLA ainda não foi aplicada.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_sla_settings
                (tenant_id, enabled, target_minutes, warning_percent,
                 count_outside_business_hours, timezone, business_hours_json,
                 updated_by_user_id, updated_at)
             VALUES
                (:tenant_id, 1, :target_minutes, :warning_percent,
                 :count_outside_business_hours, :timezone, :business_hours_json,
                 :updated_by_user_id, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                target_minutes = VALUES(target_minutes),
                warning_percent = VALUES(warning_percent),
                count_outside_business_hours = VALUES(count_outside_business_hours),
                timezone = VALUES(timezone),
                business_hours_json = VALUES(business_hours_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = VALUES(updated_at)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'target_minutes' => $target,
            'warning_percent' => $warning,
            'count_outside_business_hours' => $countOutside,
            'timezone' => $timezone,
            'business_hours_json' => $hoursJson,
            'updated_by_user_id' => ($userId ?? 0) > 0 ? $userId : null,
        ]);

        unset($this->settingsCache[$tenantId]);
        return $this->settings($tenantId);
    }

    /**
     * Sincroniza o horário do onboarding sem alterar a meta e o limiar do SLA.
     * @param array<string,mixed> $hours compact format: days/start/end
     */
    public function syncBusinessHours(int $tenantId, array $hours, string $timezone, ?int $userId = null): array
    {
        $current = $this->settings($tenantId);
        $payload = [
            'sla_target_minutes' => (int) $current['target_minutes'],
            'sla_warning_percent' => (int) $current['warning_percent'],
            'sla_count_outside_hours' => (int) $current['count_outside_business_hours'] === 1 ? '1' : '',
            'business_timezone' => $timezone,
            'business_hours_json' => json_encode($hours, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $this->defaultBusinessHoursJson(),
        ];
        return $this->save($tenantId, $payload, $userId);
    }

    /** @param array<string,mixed> $cycle */
    public function policyForCycle(int $tenantId, array $cycle = []): array
    {
        $settings = $this->settings($tenantId);
        $snapshot = [
            'target_minutes' => $cycle['sla_target_minutes'] ?? $cycle['sla_target_minutes_snapshot'] ?? null,
            'warning_percent' => $cycle['sla_warning_percent'] ?? $cycle['sla_warning_percent_snapshot'] ?? null,
            'count_outside_business_hours' => $cycle['sla_count_outside_business_hours'] ?? $cycle['sla_count_outside_hours_snapshot'] ?? null,
            'timezone' => $cycle['sla_timezone'] ?? $cycle['sla_timezone_snapshot'] ?? null,
            'business_hours_json' => $cycle['sla_business_hours_json'] ?? $cycle['sla_business_hours_json_snapshot'] ?? null,
        ];

        foreach ($snapshot as $key => $value) {
            if ($value !== null && $value !== '') {
                $settings[$key] = $value;
            }
        }
        return $this->normalizeSettings($settings);
    }

    /**
     * Tempo efetivo do SLA, em segundos. Entradas são UTC no contrato do RS Connect.
     * @param array<string,mixed>|null $policy
     */
    public function elapsedSeconds(int $tenantId, string $startUtc, ?string $endUtc = null, ?array $policy = null): int
    {
        $policy = $this->normalizeSettings($policy ?? $this->settings($tenantId));
        $startUtc = trim($startUtc);
        $endUtc = trim((string) ($endUtc ?? gmdate('Y-m-d H:i:s')));
        if ($startUtc === '' || $endUtc === '') {
            return 0;
        }

        try {
            $utc = new DateTimeZone('UTC');
            $start = new DateTimeImmutable($startUtc, $utc);
            $end = new DateTimeImmutable($endUtc, $utc);
        } catch (Throwable) {
            return 0;
        }
        if ($end <= $start) {
            return 0;
        }

        if ((int) ($policy['count_outside_business_hours'] ?? 0) === 1) {
            return max(0, $end->getTimestamp() - $start->getTimestamp());
        }

        return $this->businessSeconds($start, $end, $policy);
    }

    /** @param array<string,mixed>|null $policy */
    public function state(int $tenantId, string $firstIncomingAt, ?string $firstResponseAt = null, ?array $policy = null, ?string $asOfUtc = null): array
    {
        $policy = $this->normalizeSettings($policy ?? $this->settings($tenantId));
        $responded = trim((string) $firstResponseAt) !== '';
        $clockEnd = $responded ? $firstResponseAt : ($asOfUtc ?? gmdate('Y-m-d H:i:s'));
        $elapsed = $this->elapsedSeconds($tenantId, $firstIncomingAt, $clockEnd, $policy);
        $targetSeconds = max(300, (int) $policy['target_minutes'] * 60);
        $warningSeconds = max(1, (int) floor($targetSeconds * ((int) $policy['warning_percent'] / 100)));
        $percent = min(999.9, round(($elapsed / $targetSeconds) * 100, 1));

        if ($responded) {
            $status = $elapsed <= $targetSeconds ? 'met' : 'breached';
        } elseif ($elapsed >= $targetSeconds) {
            $status = 'breached';
        } elseif ($elapsed >= $warningSeconds) {
            $status = 'warning';
        } else {
            $status = 'normal';
        }

        return [
            'status' => $status,
            'responded' => $responded,
            'elapsed_seconds' => $elapsed,
            'target_seconds' => $targetSeconds,
            'warning_seconds' => $warningSeconds,
            'remaining_seconds' => max(0, $targetSeconds - $elapsed),
            'percent' => $percent,
            'target_minutes' => (int) $policy['target_minutes'],
            'warning_percent' => (int) $policy['warning_percent'],
            'count_outside_business_hours' => (int) $policy['count_outside_business_hours'],
            'timezone' => (string) $policy['timezone'],
        ];
    }

    /** @param array<string,mixed> $settings */
    private function normalizeSettings(array $settings): array
    {
        $settings['enabled'] = !isset($settings['enabled']) || (int) $settings['enabled'] === 1 ? 1 : 0;
        $settings['target_minutes'] = max(5, min(1440, (int) ($settings['target_minutes'] ?? self::DEFAULT_TARGET_MINUTES)));
        $settings['warning_percent'] = max(50, min(99, (int) ($settings['warning_percent'] ?? self::DEFAULT_WARNING_PERCENT)));
        $settings['count_outside_business_hours'] = (int) ($settings['count_outside_business_hours'] ?? 0) === 1 ? 1 : 0;
        $settings['timezone'] = Clock::safeTimezone((string) ($settings['timezone'] ?? 'America/Sao_Paulo'));
        $hours = trim((string) ($settings['business_hours_json'] ?? ''));
        $settings['business_hours_json'] = ($hours !== '' && is_array(json_decode($hours, true))) ? $hours : $this->defaultBusinessHoursJson();
        return $settings;
    }

    /** @param array<string,mixed> $policy */
    private function businessSeconds(DateTimeImmutable $startUtc, DateTimeImmutable $endUtc, array $policy): int
    {
        try {
            $tz = new DateTimeZone((string) $policy['timezone']);
        } catch (Throwable) {
            $tz = new DateTimeZone('UTC');
        }

        $start = $startUtc->setTimezone($tz);
        $end = $endUtc->setTimezone($tz);
        $ranges = $this->normalizedRanges((string) $policy['business_hours_json']);
        if ($ranges === []) {
            // Fail-safe: configuração de expediente inválida não deve congelar o SLA.
            return max(0, $endUtc->getTimestamp() - $startUtc->getTimestamp());
        }

        $seconds = 0;
        $cursor = $start->setTime(0, 0, 0);
        $lastDay = $end->setTime(0, 0, 0);
        $guard = 0;
        while ($cursor <= $lastDay && $guard < 370) {
            $dayKey = strtolower($cursor->format('D'));
            $dayKey = match ($dayKey) {
                'mon' => 'mon', 'tue' => 'tue', 'wed' => 'wed', 'thu' => 'thu',
                'fri' => 'fri', 'sat' => 'sat', 'sun' => 'sun', default => 'mon',
            };
            foreach ($ranges[$dayKey] ?? [] as $range) {
                [$open, $close] = $range;
                $rangeStart = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $open . ':00', $tz);
                $rangeEnd = new DateTimeImmutable($cursor->format('Y-m-d') . ' ' . $close . ':00', $tz);
                if ($rangeEnd <= $rangeStart) {
                    continue;
                }
                $overlapStart = $start > $rangeStart ? $start : $rangeStart;
                $overlapEnd = $end < $rangeEnd ? $end : $rangeEnd;
                if ($overlapEnd > $overlapStart) {
                    $seconds += $overlapEnd->getTimestamp() - $overlapStart->getTimestamp();
                }
            }
            $cursor = $cursor->add(new DateInterval('P1D'));
            $guard++;
        }

        return max(0, $seconds);
    }

    /** @return array<string,list<array{0:string,1:string}>> */
    private function normalizedRanges(string $json): array
    {
        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $result = ['mon' => [], 'tue' => [], 'wed' => [], 'thu' => [], 'fri' => [], 'sat' => [], 'sun' => []];
        if (isset($decoded['days'], $decoded['start'], $decoded['end']) && is_array($decoded['days'])) {
            $start = trim((string) $decoded['start']);
            $end = trim((string) $decoded['end']);
            if ($this->validTime($start) && $this->validTime($end) && $end > $start) {
                foreach ($decoded['days'] as $day) {
                    $key = strtolower(trim((string) $day));
                    if (array_key_exists($key, $result)) {
                        $result[$key][] = [$start, $end];
                    }
                }
            }
            return array_filter($result, static fn (array $items): bool => $items !== []);
        }

        foreach ($result as $day => $_) {
            $rawRanges = $decoded[$day] ?? [];
            if (!is_array($rawRanges)) {
                continue;
            }
            foreach ($rawRanges as $range) {
                if (!is_array($range) || count($range) < 2) {
                    continue;
                }
                $start = trim((string) ($range[0] ?? ''));
                $end = trim((string) ($range[1] ?? ''));
                if ($this->validTime($start) && $this->validTime($end) && $end > $start) {
                    $result[$day][] = [$start, $end];
                }
            }
        }
        return array_filter($result, static fn (array $items): bool => $items !== []);
    }

    private function validTime(string $value): bool
    {
        return preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/', $value) === 1;
    }

    private function defaultBusinessHoursJson(): string
    {
        return '{"days":["mon","tue","wed","thu","fri"],"start":"08:00","end":"18:00"}';
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
            );
            $statement->execute(['table_name' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
