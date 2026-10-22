<?php

declare(strict_types=1);

namespace App\Services;

/** Regra única de exibição: nenhum compromisso antigo herda a triagem mais recente. */
final class AppointmentTriagePresentationService
{
    /** @param array<string, mixed> $appointment @param array<string, string> $currentLabels */
    public function resolve(array $appointment, array $currentLabels): array
    {
        $decode = static function (mixed $value): array {
            if (is_array($value)) {
                return $value;
            }
            $data = is_string($value) ? json_decode($value, true) : null;
            return is_array($data) ? $data : [];
        };
        $snapshot = !empty($appointment['context_snapshot_present']);
        $pending = !empty($appointment['is_pre_schedule']) && empty($appointment['context_snapshot_locked_at']);
        $snapshotLabels = $snapshot ? $decode($appointment['context_snapshot_labels_json'] ?? null) : [];
        $labels = $pending
            ? array_merge($snapshotLabels, $currentLabels)
            : array_merge($currentLabels, $snapshotLabels);
        // Pré-agendamentos ainda abertos mostram o estágio vivo da coleta, quando
        // disponível. Compromissos finalizados sempre usam apenas o snapshot.
        $pendingCollected = $pending
            ? $decode($appointment['triage_review_collected_json'] ?? $appointment['current_triage_collected_json'] ?? null)
            : [];
        $collected = $pending && $pendingCollected !== []
            ? $pendingCollected
            : ($snapshot ? $decode($appointment['context_snapshot_collected_json'] ?? null) : []);
        $invalid = $pending && $pendingCollected !== []
            ? (array) ($appointment['triage_review_invalid_keys'] ?? [])
            : ($snapshot ? $decode($appointment['context_snapshot_invalid_keys_json'] ?? null) : []);
        return [
            'collected' => $collected,
            'labels' => $labels,
            'invalid' => $invalid,
            'group' => $pending
                ? trim((string) ($appointment['current_contact_group'] ?? $appointment['context_snapshot_contact_group'] ?? ''))
                : ($snapshot ? trim((string) ($appointment['context_snapshot_contact_group'] ?? '')) : ''),
            'demand_summary' => $pending
                ? trim((string) ($appointment['current_demand_summary'] ?? $appointment['context_snapshot_demand_summary'] ?? ''))
                : ($snapshot ? trim((string) ($appointment['context_snapshot_demand_summary'] ?? '')) : ''),
            'demand_status' => $pending
                ? trim((string) ($appointment['current_demand_status'] ?? $appointment['context_snapshot_demand_status'] ?? ''))
                : ($snapshot ? trim((string) ($appointment['context_snapshot_demand_status'] ?? '')) : ''),
            'has_snapshot' => $snapshot,
            'historical_missing' => !$snapshot && !$pending,
        ];
    }
}
