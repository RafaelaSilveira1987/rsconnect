<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class MessageGovernanceService
{
    /** @return array<string,mixed> */
    public function settingsForTenant(int $tenantId): array
    {
        $defaults = [
            'whatsapp_human_signature_enabled' => 0,
            'whatsapp_human_signature_format' => 'name_role',
            'message_retention_mode' => 'reduced',
            'message_retention_days' => 90,
            'message_raw_payload_days' => 30,
            'message_ephemeral_hours' => 24,
            'message_retention_last_run_at' => null,
        ];

        try {
            $statement = Database::connection()->prepare(
                'SELECT whatsapp_human_signature_enabled, whatsapp_human_signature_format,
                        message_retention_mode, message_retention_days, message_raw_payload_days,
                        message_ephemeral_hours, message_retention_last_run_at
                 FROM tenants WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ? array_merge($defaults, $row) : $defaults;
        } catch (Throwable) {
            return $defaults;
        }
    }

    /** @return array{original:string,delivered:string,display_name:?string,role_label:?string,signed:bool} */
    public function prepareHumanMessage(PDO $pdo, int $tenantId, int $userId, string $message): array
    {
        $original = trim($message);
        $result = [
            'original' => $original,
            'delivered' => $original,
            'display_name' => null,
            'role_label' => null,
            'signed' => false,
        ];

        try {
            // A empresa define se mensagens humanas devem ser assinadas. O usuário pode
            // pertencer à própria empresa ou ser um Super Admin global atendendo em nome dela.
            // A consulta anterior fazia INNER JOIN por tenant_id e, por isso, descartava todo
            // usuário global antes mesmo de montar o texto entregue à Evolution.
            $statement = $pdo->prepare(
                'SELECT t.name AS tenant_name, t.whatsapp_human_signature_enabled,
                        t.whatsapp_human_signature_format,
                        u.tenant_id AS user_tenant_id, u.role, u.status,
                        u.name, u.whatsapp_display_name, u.whatsapp_role_label,
                        u.whatsapp_signature_enabled
                 FROM tenants t
                 INNER JOIN users u ON u.id = :user_id
                 WHERE t.id = :tenant_id
                   AND u.status = "active"
                   AND (
                        u.tenant_id = t.id
                        OR (u.tenant_id IS NULL AND u.role = "super_admin")
                   )
                 LIMIT 1'
            );
            $statement->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row || empty($row['whatsapp_human_signature_enabled']) || empty($row['whatsapp_signature_enabled'])) {
                return $result;
            }

            $displayName = trim((string) ($row['whatsapp_display_name'] ?? ''));
            if ($displayName === '') {
                $displayName = trim((string) ($row['name'] ?? ''));
            }
            if ($displayName === '') {
                return $result;
            }

            $roleLabel = trim((string) ($row['whatsapp_role_label'] ?? ''));
            $tenantName = trim((string) ($row['tenant_name'] ?? ''));
            $format = (string) ($row['whatsapp_human_signature_format'] ?? 'name_role');
            $signature = match ($format) {
                'name' => $displayName,
                'name_company' => $tenantName !== '' ? $displayName . ' — ' . $tenantName : $displayName,
                default => $roleLabel !== '' ? $displayName . ' — ' . $roleLabel : $displayName,
            };

            // A assinatura integra o texto enviado para a Evolution. O WhatsApp não
            // possui um campo separado para o nome do atendente em conversas individuais.
            $result['delivered'] = '*' . $signature . "*\n" . $original;
            $result['display_name'] = $displayName;
            $result['role_label'] = $roleLabel !== '' ? $roleLabel : null;
            $result['signed'] = true;
            return $result;
        } catch (Throwable $exception) {
            error_log(sprintf(
                '[RS Connect][human-signature] tenant=%d user=%d error=%s',
                $tenantId,
                $userId,
                $exception->getMessage()
            ));
            return $result;
        }
    }

    /** @return array<string,mixed> */
    public function run(?int $tenantId = null, string $source = 'system'): array
    {
        $pdo = Database::connection();
        $source = in_array($source, ['manual', 'cron', 'n8n', 'system'], true) ? $source : 'system';
        $startedAt = \App\Core\Clock::nowUtc();
        $summary = [
            'ok' => true,
            'source' => $source,
            'tenants_checked' => 0,
            'messages_purged' => 0,
            'payloads_purged' => 0,
            'previews_purged' => 0,
            'results' => [],
            'started_at' => $startedAt,
        ];

        $sql = 'SELECT id, name, message_retention_mode, message_retention_days,
                       message_raw_payload_days, message_ephemeral_hours
                FROM tenants';
        $params = [];
        if ($tenantId !== null && $tenantId > 0) {
            $sql .= ' WHERE id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $sql .= ' ORDER BY id';
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        $tenants = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($tenants as $tenant) {
            $id = (int) $tenant['id'];
            $mode = (string) ($tenant['message_retention_mode'] ?? 'reduced');
            $rawDays = max(1, min(3650, (int) ($tenant['message_raw_payload_days'] ?? 30)));
            $retentionDays = max(1, min(3650, (int) ($tenant['message_retention_days'] ?? 90)));
            $ephemeralHours = max(1, min(720, (int) ($tenant['message_ephemeral_hours'] ?? 24)));
            $runId = $this->openRun($pdo, $id, $source, $startedAt);
            $result = ['tenant_id' => $id, 'tenant_name' => (string) $tenant['name'], 'mode' => $mode, 'messages_purged' => 0, 'payloads_purged' => 0, 'previews_purged' => 0];

            try {
                $payloadCutoff = date('Y-m-d H:i:s', strtotime('-' . $rawDays . ' days'));
                $payloadStatement = $pdo->prepare(
                    'UPDATE conversation_messages
                     SET raw_payload_json = NULL, raw_payload_purged_at = NOW()
                     WHERE tenant_id = :tenant_id
                       AND raw_payload_json IS NOT NULL
                       AND sent_at < :cutoff'
                );
                $payloadStatement->execute(['tenant_id' => $id, 'cutoff' => $payloadCutoff]);
                $result['payloads_purged'] = $payloadStatement->rowCount();

                $contentCutoff = null;
                if ($mode === 'reduced') {
                    $contentCutoff = date('Y-m-d H:i:s', strtotime('-' . $retentionDays . ' days'));
                } elseif ($mode === 'ephemeral') {
                    $contentCutoff = date('Y-m-d H:i:s', strtotime('-' . $ephemeralHours . ' hours'));
                }

                if ($contentCutoff !== null) {
                    $contentStatement = $pdo->prepare(
                        'UPDATE conversation_messages m
                         INNER JOIN conversations c ON c.id = m.conversation_id AND c.tenant_id = m.tenant_id
                         SET m.content = NULL,
                             m.delivered_content = NULL,
                             m.raw_payload_json = NULL,
                             m.content_purged_at = NOW(),
                             m.raw_payload_purged_at = COALESCE(m.raw_payload_purged_at, NOW())
                         WHERE m.tenant_id = :tenant_id
                           AND m.content_purged_at IS NULL
                           AND m.sent_at < :cutoff
                           AND (:mode <> "ephemeral" OR c.last_message_at < :cutoff_active)'
                    );
                    $contentStatement->execute([
                        'tenant_id' => $id,
                        'cutoff' => $contentCutoff,
                        'mode' => $mode,
                        'cutoff_active' => $contentCutoff,
                    ]);
                    $result['messages_purged'] = $contentStatement->rowCount();

                    $previewStatement = $pdo->prepare(
                        'UPDATE conversations
                         SET last_message_preview = "Conteúdo removido pela política de retenção"
                         WHERE tenant_id = :tenant_id
                           AND last_message_at < :cutoff
                           AND last_message_preview IS NOT NULL
                           AND last_message_preview <> "Conteúdo removido pela política de retenção"'
                    );
                    $previewStatement->execute(['tenant_id' => $id, 'cutoff' => $contentCutoff]);
                    $result['previews_purged'] = $previewStatement->rowCount();
                }

                $pdo->prepare('UPDATE tenants SET message_retention_last_run_at = NOW() WHERE id = :id')->execute(['id' => $id]);
                $this->finishRun($pdo, $runId, 'success', $result, null);
            } catch (Throwable $exception) {
                $summary['ok'] = false;
                $result['error'] = $exception->getMessage();
                $this->finishRun($pdo, $runId, 'error', $result, $exception->getMessage());
            }

            $summary['tenants_checked']++;
            $summary['messages_purged'] += (int) $result['messages_purged'];
            $summary['payloads_purged'] += (int) $result['payloads_purged'];
            $summary['previews_purged'] += (int) $result['previews_purged'];
            $summary['results'][] = $result;
        }

        $summary['finished_at'] = \App\Core\Clock::nowUtc();
        return $summary;
    }

    private function openRun(PDO $pdo, int $tenantId, string $source, string $startedAt): int
    {
        $statement = $pdo->prepare(
            'INSERT INTO message_retention_runs (tenant_id, source, status, started_at)
             VALUES (:tenant_id, :source, "success", :started_at)'
        );
        $statement->execute(['tenant_id' => $tenantId, 'source' => $source, 'started_at' => $startedAt]);
        return (int) $pdo->lastInsertId();
    }

    /** @param array<string,mixed> $details */
    private function finishRun(PDO $pdo, int $runId, string $status, array $details, ?string $error): void
    {
        $pdo->prepare(
            'UPDATE message_retention_runs
             SET status = :status,
                 messages_purged = :messages_purged,
                 payloads_purged = :payloads_purged,
                 previews_purged = :previews_purged,
                 details_json = :details_json,
                 error_message = :error_message,
                 finished_at = NOW()
             WHERE id = :id'
        )->execute([
            'status' => $status,
            'messages_purged' => (int) ($details['messages_purged'] ?? 0),
            'payloads_purged' => (int) ($details['payloads_purged'] ?? 0),
            'previews_purged' => (int) ($details['previews_purged'] ?? 0),
            'details_json' => json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'error_message' => $error !== null ? mb_substr($error, 0, 500) : null,
            'id' => $runId,
        ]);
    }
}
