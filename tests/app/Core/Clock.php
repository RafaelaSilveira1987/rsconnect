<?php

declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;

/**
 * Centraliza o contrato de datas do RS Connect.
 *
 * Persistência técnica: UTC.
 * Apresentação e filtros: fuso da empresa ou APP_TIMEZONE.
 */
final class Clock
{
    public const STORAGE_TIMEZONE = 'UTC';

    public static function nowUtc(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::STORAGE_TIMEZONE)))
            ->format('Y-m-d H:i:s');
    }

    public static function nowUtcIso(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone(self::STORAGE_TIMEZONE)))
            ->format(DateTimeInterface::ATOM);
    }

    public static function fromUnixUtc(int $timestamp): string
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone(self::STORAGE_TIMEZONE))
            ->format('Y-m-d H:i:s');
    }

    public static function appTimezone(): string
    {
        return self::safeTimezone((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));
    }

    public static function safeTimezone(?string $timezone): string
    {
        $candidate = trim((string) $timezone);
        if ($candidate === '') {
            return 'America/Sao_Paulo';
        }

        try {
            new DateTimeZone($candidate);
            return $candidate;
        } catch (Throwable) {
            return 'America/Sao_Paulo';
        }
    }

    /**
     * Converte uma data local para UTC no formato do banco.
     */
    public static function localToUtc(string $value, ?string $timezone = null): string
    {
        $timezone = self::safeTimezone($timezone ?: self::appTimezone());
        try {
            return (new DateTimeImmutable($value, new DateTimeZone($timezone)))
                ->setTimezone(new DateTimeZone(self::STORAGE_TIMEZONE))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return $value;
        }
    }

    /**
     * Converte uma data UTC do banco para o fuso de exibição.
     */
    public static function utcToLocal(string $value, ?string $timezone = null, string $format = 'Y-m-d H:i:s'): string
    {
        if (trim($value) === '') {
            return '';
        }

        $timezone = self::safeTimezone($timezone ?: self::appTimezone());
        try {
            return (new DateTimeImmutable($value, new DateTimeZone(self::STORAGE_TIMEZONE)))
                ->setTimezone(new DateTimeZone($timezone))
                ->format($format);
        } catch (Throwable) {
            return $value;
        }
    }

    public static function localRangeToUtc(string $startDate, string $endDate, ?string $timezone = null): array
    {
        $timezone = self::safeTimezone($timezone ?: self::appTimezone());
        $start = self::localToUtc($startDate . ' 00:00:00', $timezone);
        $end = self::localToUtc($endDate . ' 23:59:59', $timezone);

        return ['start' => $start, 'end' => $end, 'timezone' => $timezone];
    }

    public static function localDateKey(string $utcValue, ?string $timezone = null): string
    {
        return self::utcToLocal($utcValue, $timezone, 'Y-m-d');
    }

    public static function formatUtc(?string $value, string $format = 'd/m/Y H:i', ?string $timezone = null): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        return self::utcToLocal($value, $timezone, $format);
    }
}
