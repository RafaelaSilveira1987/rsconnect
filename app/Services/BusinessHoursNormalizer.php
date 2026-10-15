<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Normalização única para expediente de conversa (NÃO é disponibilidade da agenda).
 * Aceita os formatos legados do onboarding, o mapa do runtime e a lista da API.
 * Retorna null para dados inválidos e [] para agenda explicitamente vazia.
 * Não altera o banco, não infere horários e não modifica o fuso.
 */
final class BusinessHoursNormalizer
{
    private const DAYS = [
        'mon' => 'monday', 'tue' => 'tuesday', 'wed' => 'wednesday',
        'thu' => 'thursday', 'fri' => 'friday', 'sat' => 'saturday', 'sun' => 'sunday',
    ];

    /** @return array<string,list<array{0:string,1:string}>>|null */
    public static function normalize(mixed $raw): ?array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) return null;
        $output = array_fill_keys(array_keys(self::DAYS), []);
        $add = static function (string $day, mixed $start, mixed $end) use (&$output): bool {
            $day = array_search($day, self::DAYS, true) ?: $day;
            if (!array_key_exists($day, $output) || !is_string($start) || !is_string($end)) return false;
            if (!self::validTime($start) || !self::validTime($end) || $start >= $end) return false;
            $output[$day][] = [$start, $end];
            return true;
        };

        if (array_key_exists('days', $raw)) {
            if (!is_array($raw['days']) || !is_string($raw['start'] ?? null) || !is_string($raw['end'] ?? null)) return null;
            foreach ($raw['days'] as $day) {
                if (!is_string($day) || !$add($day, $raw['start'], $raw['end'])) return null;
            }
        } elseif (array_is_list($raw)) {
            foreach ($raw as $row) {
                if (!is_array($row) || !is_string($row['day'] ?? null)
                    || !$add($row['day'], $row['start'] ?? null, $row['end'] ?? null)) return null;
            }
        } else {
            foreach ($raw as $day => $ranges) {
                if (!is_string($day) || !is_array($ranges)) return null;
                foreach ($ranges as $range) {
                    if (!is_array($range)) return null;
                    if (!$add($day, $range['start'] ?? $range[0] ?? null, $range['end'] ?? $range[1] ?? null)) return null;
                }
                // Mesmo sem intervalos, dias desconhecidos são inválidos.
                if (!array_key_exists($day, $output) && !in_array($day, self::DAYS, true)) return null;
            }
        }
        foreach ($output as &$ranges) {
            usort($ranges, static fn (array $a, array $b): int => strcmp($a[0], $b[0]));
            $end = null;
            foreach ($ranges as $range) {
                if ($end !== null && $range[0] < $end) return null;
                $end = $range[1];
            }
        }
        unset($ranges);
        return array_filter($output, static fn (array $ranges): bool => $ranges !== []);
    }

    public static function hasValidIntervals(mixed $raw): bool
    {
        $ranges = self::normalize($raw);
        return $ranges !== null && $ranges !== [];
    }

    /** @return list<array{day:string,start:string,end:string}> */
    public static function asApiList(mixed $raw): array
    {
        $ranges = self::normalize($raw);
        if ($ranges === null) return [];
        $result = [];
        foreach (self::DAYS as $short => $long) {
            foreach ($ranges[$short] ?? [] as [$start, $end]) {
                $result[] = ['day' => $long, 'start' => $start, 'end' => $end];
            }
        }
        return $result;
    }

    private static function validTime(string $value): bool
    {
        return preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/D', $value) === 1;
    }
}
