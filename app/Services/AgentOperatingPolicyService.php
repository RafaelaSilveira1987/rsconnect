<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Fonte única para a política operacional de horário dos agentes.
 * Quando business_hours_enabled=1, a restrição é técnica e prevalece
 * sobre prompt, agenda e integrações conversacionais.
 */
final class AgentOperatingPolicyService
{
    /**
     * @return array{enforced:bool,inside:bool,reason:string,timezone:string,day:string,current:string}
     */
    public function status(array $agent, ?DateTimeImmutable $now = null): array
    {
        $enforced = (int) ($agent['business_hours_enabled'] ?? 0) === 1;
        $timezone = trim((string) ($agent['business_timezone'] ?? Env::get('APP_TIMEZONE', 'America/Sao_Paulo')))
            ?: 'America/Sao_Paulo';

        if (!$enforced) {
            return [
                'enforced' => false,
                'inside' => true,
                'reason' => 'business_hours_disabled',
                'timezone' => $timezone,
                'day' => '',
                'current' => '',
            ];
        }

        try {
            $tz = new DateTimeZone($timezone);
        } catch (Throwable) {
            $timezone = (string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo');
            try {
                $tz = new DateTimeZone($timezone);
            } catch (Throwable) {
                $tz = new DateTimeZone('UTC');
                $timezone = 'UTC';
            }
        }

        if ($now === null) {
            $now = new DateTimeImmutable('now', $tz);
        } else {
            $now = $now->setTimezone($tz);
        }

        $days = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];
        $dayKey = $days[(int) $now->format('w')] ?? 'mon';
        $current = $now->format('H:i');
        $rules = json_decode((string) ($agent['business_hours_json'] ?? ''), true);

        if (!is_array($rules) || !isset($rules[$dayKey]) || !is_array($rules[$dayKey]) || $rules[$dayKey] === []) {
            return [
                'enforced' => true,
                'inside' => false,
                'reason' => 'day_closed',
                'timezone' => $timezone,
                'day' => $dayKey,
                'current' => $current,
            ];
        }

        foreach ($rules[$dayKey] as $range) {
            if (!is_array($range) || count($range) < 2) {
                continue;
            }
            $start = trim((string) ($range[0] ?? ''));
            $end = trim((string) ($range[1] ?? ''));
            if (!$this->validTime($start) || !$this->validTime($end)) {
                continue;
            }
            if ($start <= $current && $current <= $end) {
                return [
                    'enforced' => true,
                    'inside' => true,
                    'reason' => 'inside_business_hours',
                    'timezone' => $timezone,
                    'day' => $dayKey,
                    'current' => $current,
                ];
            }
        }

        return [
            'enforced' => true,
            'inside' => false,
            'reason' => 'outside_time_range',
            'timezone' => $timezone,
            'day' => $dayKey,
            'current' => $current,
        ];
    }

    public function allowsConversationalAutomation(array $agent, ?DateTimeImmutable $now = null): bool
    {
        $status = $this->status($agent, $now);
        return !$status['enforced'] || $status['inside'];
    }

    private function validTime(string $value): bool
    {
        return (bool) preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value);
    }
}
