<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class BackupSchedulePolicyService
{
    /**
     * Avalia somente calendário/sucesso/retry. Jobs ativos devem ser bloqueados pelo chamador.
     *
     * @return array{due:bool,reason:string,message:string,next_retry_at:?string,stale:bool,schedule_due:bool}
     */
    public function evaluate(array $routine, ?DateTimeImmutable $now = null): array
    {
        $frequency = (string) ($routine['frequency'] ?? 'daily');
        if (in_array($frequency, ['manual', 'custom'], true)) {
            return $this->decision(false, 'manual_frequency', 'Rotina configurada sem disparo automático.');
        }

        try {
            $timezone = new DateTimeZone((string) ($routine['timezone'] ?? 'America/Sao_Paulo'));
            $sourceTimezone = new DateTimeZone((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));
            $now = $now ? $now->setTimezone($timezone) : new DateTimeImmutable('now', $timezone);

            $preferred = (string) ($routine['preferred_time'] ?? '03:00');
            [$hour, $minute] = array_map('intval', explode(':', preg_match('/^\d{2}:\d{2}$/', $preferred) ? $preferred : '03:00'));
            $todaySchedule = $now->setTime($hour, $minute, 0);

            $lastSuccess = $this->parseDate((string) ($routine['last_success_at'] ?? ''), $sourceTimezone, $timezone);
            $lastRequested = $this->parseDate((string) ($routine['last_requested_at'] ?? ''), $sourceTimezone, $timezone);
            $maxAgeHours = max(1, min(720, (int) ($routine['max_age_hours'] ?? Env::get('OPERATIONS_BACKUP_MAX_AGE_HOURS', 24))));
            $retryMinutes = max(5, min(240, (int) Env::get('OPERATIONS_BACKUP_RETRY_MINUTES', 30)));

            if ($lastSuccess === null && $now < $todaySchedule) {
                return $this->decision(false, 'before_schedule', 'Ainda não chegou o horário programado.');
            }

            $stale = $lastSuccess === null || $lastSuccess <= $now->modify('-' . $maxAgeHours . ' hours');
            $scheduleDue = $this->scheduleDue($frequency, $lastSuccess, $now, $todaySchedule);

            if (!$stale && !$scheduleDue) {
                return $this->decision(false, 'covered', 'Já existe backup válido cobrindo a janela atual.', null, false, false);
            }

            // last_requested_at nunca mais define o ciclo como concluído. Ele apenas evita rajada de novas tentativas.
            if ($lastRequested !== null && ($lastSuccess === null || $lastRequested > $lastSuccess)) {
                $retryAt = $lastRequested->modify('+' . $retryMinutes . ' minutes');
                if ($now < $retryAt) {
                    return $this->decision(
                        false,
                        'retry_cooldown',
                        'Última tentativa ainda está dentro da janela de nova tentativa.',
                        $retryAt->format(DATE_ATOM),
                        $stale,
                        $scheduleDue
                    );
                }
            }

            if ($stale) {
                return $this->decision(true, 'backup_overdue', 'Último backup válido excedeu a idade máxima permitida.', null, true, $scheduleDue);
            }

            return $this->decision(true, 'schedule_due', 'Horário/frequência da rotina está vencido sem sucesso confirmado.', null, false, true);
        } catch (Throwable) {
            return $this->decision(false, 'invalid_schedule', 'Não foi possível interpretar horário, frequência ou fuso da rotina.');
        }
    }

    private function scheduleDue(string $frequency, ?DateTimeImmutable $lastSuccess, DateTimeImmutable $now, DateTimeImmutable $todaySchedule): bool
    {
        if ($lastSuccess === null) {
            return $now >= $todaySchedule;
        }

        return match ($frequency) {
            'weekly' => $now >= $todaySchedule && $lastSuccess <= $now->modify('-7 days'),
            'monthly' => $now >= $todaySchedule && $lastSuccess <= $now->modify('-1 month'),
            default => $now >= $todaySchedule && $lastSuccess < $todaySchedule,
        };
    }

    private function parseDate(string $value, DateTimeZone $sourceTimezone, DateTimeZone $targetTimezone): ?DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value, $sourceTimezone))->setTimezone($targetTimezone);
        } catch (Throwable) {
            return null;
        }
    }

    private function decision(
        bool $due,
        string $reason,
        string $message,
        ?string $nextRetryAt = null,
        bool $stale = false,
        bool $scheduleDue = false
    ): array {
        return [
            'due' => $due,
            'reason' => $reason,
            'message' => $message,
            'next_retry_at' => $nextRetryAt,
            'stale' => $stale,
            'schedule_due' => $scheduleDue,
        ];
    }
}
