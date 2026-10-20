<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Regra única e determinística para lembretes de agenda.
 * Pré-agendamento pendente nunca equivale a uma consulta confirmada.
 */
final class AppointmentReminderEligibilityService
{
    /** @param array<string,mixed> $appointment */
    public static function isConfirmed(array $appointment): bool
    {
        if ((string) ($appointment['status'] ?? '') !== 'confirmed') {
            return false;
        }

        // Se existe decisão de aprovação explícita, ela deve ser positiva.
        $approval = trim((string) ($appointment['approval_status'] ?? ''));
        if ($approval !== '' && $approval !== 'approved') {
            return false;
        }

        // Um pré-agendamento sem evidência de aprovação não está apto, mesmo
        // que dados antigos tenham deixado seu status como confirmed.
        if (!empty($appointment['is_pre_schedule']) && $approval !== 'approved') {
            return false;
        }

        return true;
    }
}
