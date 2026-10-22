<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Histórico de triagem próprio do compromisso (nunca reaproveita uma conversa posterior).
 * A cópia continua atualizável enquanto o pré-agendamento está pendente; é imutável
 * após locked_at. A camada de apresentação não deve criar históricos retroativos.
 */
final class AppointmentTriageSnapshotService
{
    public function capture(PDO $pdo, int $tenantId, int $appointmentId, int $conversationId, bool $freeze = false): bool
    {
        if ($tenantId < 1 || $appointmentId < 1 || $conversationId < 1) {
            return false;
        }

        // Revalida todos os identificadores dentro do tenant; nunca confia em um ID isolado.
        $appointment = $pdo->prepare(
            'SELECT id, conversation_id, status, is_pre_schedule FROM calendar_appointments
             WHERE tenant_id = :tenant_id AND id = :appointment_id LIMIT 1'
        );
        $appointment->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $row = $appointment->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) ($row['conversation_id'] ?? 0) !== $conversationId) {
            return false;
        }
        // Não construir contexto de compromissos antigos, já confirmados sem snapshot.
        if (!$freeze && (int) ($row['is_pre_schedule'] ?? 0) !== 1) {
            return false;
        }
        if ($freeze && (int) ($row['is_pre_schedule'] ?? 0) !== 1
            && !in_array((string) ($row['status'] ?? ''), ['awaiting_approval', 'pre_scheduled'], true)) {
            return false;
        }

        $triage = $pdo->prepare(
            'SELECT collected_json FROM conversation_triage_sessions
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id LIMIT 1'
        );
        $triage->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        $raw = $triage->fetchColumn();
        $collected = is_string($raw) ? json_decode($raw, true) : [];
        $collected = is_array($collected) ? $collected : [];

        $fields = $pdo->prepare(
            'SELECT field_key, label, field_type, prompt_text, active FROM tenant_triage_fields
             WHERE tenant_id = :tenant_id ORDER BY position, id'
        );
        $fields->execute(['tenant_id' => $tenantId]);
        $definitions = $fields->fetchAll(PDO::FETCH_ASSOC);
        $labels = [];
        foreach ($definitions as $definition) {
            $key = trim((string) ($definition['field_key'] ?? ''));
            if ($key !== '') {
                $labels[$key] = trim((string) ($definition['label'] ?? '')) ?: $key;
            }
        }
        $review = (new AgentTriageService())->reviewCollectedValues($definitions, $collected);

        $flow = $pdo->prepare(
            'SELECT demand_status, demand_summary FROM conversation_flow_states
             WHERE tenant_id = :tenant_id AND conversation_id = :conversation_id LIMIT 1'
        );
        $flow->execute(['tenant_id' => $tenantId, 'conversation_id' => $conversationId]);
        $flowRow = $flow->fetch(PDO::FETCH_ASSOC) ?: [];
        $groupStatement = $pdo->prepare(
            'SELECT ct.contact_group FROM calendar_appointments a
             LEFT JOIN contacts ct ON ct.id = a.contact_id AND ct.tenant_id = a.tenant_id
             WHERE a.tenant_id = :tenant_id AND a.id = :appointment_id LIMIT 1'
        );
        $groupStatement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $group = $groupStatement->fetchColumn();

        $json = static function (array $data): string {
            $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return is_string($encoded) ? $encoded : '{}';
        };
        // Chave única por tenant/agendamento. O ON DUPLICATE KEY UPDATE nunca altera
        // um snapshot congelado, mesmo que nova mensagem chegue na mesma conversa.
        $statement = $pdo->prepare(
            'INSERT INTO calendar_appointment_triage_snapshots
                (tenant_id, appointment_id, conversation_id, collected_json, field_labels_json,
                 invalid_keys_json, demand_status, demand_summary, contact_group, locked_at)
             VALUES (:tenant_id, :appointment_id, :conversation_id, :collected_json, :field_labels_json,
                     :invalid_keys_json, :demand_status, :demand_summary, :contact_group, :locked_at)
             ON DUPLICATE KEY UPDATE
                 conversation_id = IF(locked_at IS NULL, VALUES(conversation_id), conversation_id),
                 collected_json = IF(locked_at IS NULL, VALUES(collected_json), collected_json),
                 field_labels_json = IF(locked_at IS NULL, VALUES(field_labels_json), field_labels_json),
                 invalid_keys_json = IF(locked_at IS NULL, VALUES(invalid_keys_json), invalid_keys_json),
                 demand_status = IF(locked_at IS NULL, VALUES(demand_status), demand_status),
                 demand_summary = IF(locked_at IS NULL, VALUES(demand_summary), demand_summary),
                 contact_group = IF(locked_at IS NULL, VALUES(contact_group), contact_group),
                 captured_at = IF(locked_at IS NULL, CURRENT_TIMESTAMP, captured_at),
                 locked_at = IF(locked_at IS NULL, VALUES(locked_at), locked_at)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'appointment_id' => $appointmentId,
            'conversation_id' => $conversationId,
            'collected_json' => $json(is_array($review['collected'] ?? null) ? $review['collected'] : []),
            'field_labels_json' => $json($labels),
            'invalid_keys_json' => $json(is_array($review['invalid_keys'] ?? null) ? $review['invalid_keys'] : []),
            'demand_status' => (string) ($flowRow['demand_status'] ?? 'pending'),
            'demand_summary' => trim((string) ($flowRow['demand_summary'] ?? '')),
            'contact_group' => is_string($group) ? $group : null,
            'locked_at' => $freeze ? date('Y-m-d H:i:s') : null,
        ]);
        return true;
    }

    public function lockIfConfirmed(PDO $pdo, int $tenantId, int $appointmentId): bool
    {
        if ($tenantId < 1 || $appointmentId < 1) {
            return false;
        }
        $statement = $pdo->prepare(
            'UPDATE calendar_appointment_triage_snapshots s
             INNER JOIN calendar_appointments a
                 ON a.id = s.appointment_id AND a.tenant_id = s.tenant_id
             SET s.locked_at = CURRENT_TIMESTAMP
             WHERE s.tenant_id = :tenant_id AND s.appointment_id = :appointment_id
               AND a.status = "confirmed" AND s.locked_at IS NULL'
        );
        $statement->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        $check = $pdo->prepare(
            'SELECT 1 FROM calendar_appointment_triage_snapshots
             WHERE tenant_id = :tenant_id AND appointment_id = :appointment_id AND locked_at IS NOT NULL'
        );
        $check->execute(['tenant_id' => $tenantId, 'appointment_id' => $appointmentId]);
        return (bool) $check->fetchColumn();
    }

    /** @return array<int, array<string, mixed>> keyed by appointment id */
    public function forAppointments(PDO $pdo, int $tenantId, array $appointmentIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $appointmentIds), static fn (int $id): bool => $id > 0)));
        if ($tenantId < 1 || $ids === []) {
            return [];
        }
        $holders = implode(',', array_fill(0, count($ids), '?'));
        $statement = $pdo->prepare(
            'SELECT * FROM calendar_appointment_triage_snapshots
             WHERE tenant_id = ? AND appointment_id IN (' . $holders . ')'
        );
        $statement->execute(array_merge([$tenantId], $ids));
        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['appointment_id']] = $row;
        }
        return $result;
    }
}
