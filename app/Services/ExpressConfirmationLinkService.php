<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

/** Nunca transforma texto da LLM em link; valida estado da agenda no backend. */
final class ExpressConfirmationLinkService
{
    /** @param array<string,mixed> $appointment
     * @return array{configured:bool,enabled:bool,url:?string}
     */
    public function forConfirmedAppointment(array $appointment, ?PDO $pdo = null): array
    {
        $none = ['configured'=>false, 'enabled'=>false, 'url'=>null];
        $tenantId = (int) ($appointment['tenant_id'] ?? 0);
        if ($tenantId < 1) return $none;
        $pdo ??= Database::connection();
        $stmt = $pdo->prepare('SELECT config_json FROM tenant_agent_profiles WHERE tenant_id=:tid LIMIT 1');
        $stmt->execute(['tid'=>$tenantId]);
        $raw = $stmt->fetchColumn();
        $config = is_string($raw) ? json_decode($raw, true) : null;
        $express = is_array($config['express'] ?? null) ? $config['express'] : null;
        if (!$express) return $none;
        $enabled = !empty($express['tools']['calendar.confirmed.meeting_link']['enabled']);
        $result = ['configured'=>true,'enabled'=>$enabled,'url'=>null];
        if (!$enabled || ($appointment['status'] ?? null) !== 'confirmed') return $result;
        $modality = strtolower(trim((string) (($appointment['appointment_modality'] ?? '') ?: ($appointment['location_type'] ?? ''))));
        if ($modality !== 'online') return $result;
        $saved = trim((string) ($appointment['meeting_url'] ?? ''));
        $fallback = trim((string) ($express['routing']['default_meeting_link'] ?? ''));
        foreach ([$saved, $fallback] as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_URL)
                && parse_url($candidate, PHP_URL_SCHEME) === 'https') {
                $result['url'] = $candidate;
                break;
            }
        }
        return $result;
    }
}
