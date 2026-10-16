<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Reconcilia data/dia e período/horário de turnos diferentes sem usar a LLM.
 * Apenas valores explícitos são mantidos; não cria disponibilidade nem confirma reserva.
 */
final class SchedulingPreferenceContextService
{
    /** @return array<string,mixed> */
    public function mergeIntent(string $previous, array $incoming): array
    {
        $resolver = new SchedulingPreferenceResolverService();
        $old = $resolver->resolve($previous, true);
        $day = trim((string) ($incoming['preferred_day'] ?? ''));
        $date = trim((string) ($incoming['preferred_date'] ?? ''));
        $time = trim((string) ($incoming['preferred_time'] ?? ''));
        if ($date === '' && $day === '') {
            $incoming['preferred_date'] = (string) ($old['preferred_date'] ?? '');
            $incoming['preferred_day'] = (string) ($old['preferred_day'] ?? '');
        }
        if ($time === '') {
            $oldTime = (string) ($old['preferred_time'] ?? '');
            $oldDay = (string) ($old['preferred_date'] ?: $old['preferred_day']);
            $newDay = $date !== '' ? $date : $day;
            // Alterar o dia sem repetir uma hora EXATA não autoriza reutilizar
            // aquela hora e pré-reservá-la automaticamente em outra data.
            $dateChanged = $newDay !== '' && $oldDay !== '' && $newDay !== $oldDay;
            $incoming['preferred_time'] = ($dateChanged && preg_match('/^\d{2}:\d{2}$/', $oldTime))
                ? '' : $oldTime;
        }
        return $incoming;
    }

    public function mergeText(string $previous, string $incoming): string
    {
        $resolver = new SchedulingPreferenceResolverService();
        $current = $resolver->resolve($incoming, true);
        $merged = $this->mergeIntent($previous, $current);
        $date = trim((string) ($merged['preferred_date'] ?? ''));
        $day = $date !== '' ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            ? substr($date, 8, 2) . '/' . substr($date, 5, 2) . '/' . substr($date, 0, 4)
            : $date) : trim((string) ($merged['preferred_day'] ?? ''));
        $time = trim((string) ($merged['preferred_time'] ?? ''));
        $mergedText = trim($day . ' ' . $time);
        return $mergedText !== '' ? $mergedText : trim($previous !== '' ? $previous : $incoming);
    }
}
