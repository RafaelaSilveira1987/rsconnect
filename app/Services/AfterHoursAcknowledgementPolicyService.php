<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Envia no máximo uma mensagem fixa de ausência por dia local do agente. */
final class AfterHoursAcknowledgementPolicyService
{
    public function shouldSend(?string $lastAcknowledgedAt, string $receivedAt, string $businessTimezone): bool
    {
        $lastAcknowledgedAt = trim((string) $lastAcknowledgedAt);
        if ($lastAcknowledgedAt === '') {
            return true;
        }

        return $this->localDate($lastAcknowledgedAt, $businessTimezone)
            !== $this->localDate($receivedAt, $businessTimezone);
    }

    private function localDate(string $timestamp, string $businessTimezone): string
    {
        $appTimezone = (string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo');
        try {
            $sourceTz = new DateTimeZone($appTimezone);
        } catch (Throwable) {
            $sourceTz = new DateTimeZone('UTC');
        }
        try {
            $targetTz = new DateTimeZone(trim($businessTimezone) !== '' ? $businessTimezone : $appTimezone);
        } catch (Throwable) {
            $targetTz = $sourceTz;
        }

        try {
            return (new DateTimeImmutable($timestamp, $sourceTz))->setTimezone($targetTz)->format('Y-m-d');
        } catch (Throwable) {
            return substr($timestamp, 0, 10);
        }
    }
}
