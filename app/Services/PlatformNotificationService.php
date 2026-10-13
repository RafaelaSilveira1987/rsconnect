<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Fila nativa usada pela própria plataforma para avisar empresas clientes.
 *
 * Diferente das notificações operacionais de cada tenant, o WhatsApp pode sair
 * de uma instância central escolhida pelo super administrador. Assim a cobrança
 * do RS Connect nunca usa silenciosamente o número da própria empresa cobrada.
 */
final class PlatformNotificationService
{
    public const NATIVE_CHANNELS = ['native_in_app', 'native_whatsapp', 'native_both'];

    /** @return array<string,mixed> */
    public function settings(): array
    {
        if (!$this->tableExists('platform_notification_settings')) {
            return ['whatsapp_instance_id' => null, 'ready' => false];
        }

        try {
            $row = Database::connection()->query(
                'SELECT pns.*, ei.name AS instance_name, ei.instance_name AS remote_instance_name,
                        ei.status AS instance_status, ei.profile_phone AS instance_phone,
                        t.name AS instance_tenant_name
                 FROM platform_notification_settings pns
                 LEFT JOIN evolution_instances ei ON ei.id = pns.whatsapp_instance_id
                 LEFT JOIN tenants t ON t.id = ei.tenant_id
                 WHERE pns.id = 1 LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC) ?: [];
            $row['ready'] = true;
            return $row;
        } catch (Throwable) {
            return ['whatsapp_instance_id' => null, 'ready' => false];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function whatsappInstances(): array
    {
        try {
            return Database::connection()->query(
                'SELECT ei.id, ei.tenant_id, ei.name, ei.instance_name, ei.status, ei.connection_state,
                        ei.profile_phone, t.name AS tenant_name
                 FROM evolution_instances ei
                 INNER JOIN tenants t ON t.id = ei.tenant_id
                 WHERE ei.api_key_encrypted IS NOT NULL AND ei.api_key_encrypted <> ""
                 ORDER BY CASE WHEN ei.status = "connected" THEN 0 ELSE 1 END, t.name, ei.is_default DESC, ei.id'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    public function saveSettings(?int $instanceId, ?int $userId): void
    {
        if (!$this->tableExists('platform_notification_settings')) {
            throw new RuntimeException('Execute a migration 125 para configurar o WhatsApp nativo da plataforma.');
        }

        $instanceId = $instanceId && $instanceId > 0 ? $instanceId : null;
        if ($instanceId !== null) {
            $statement = Database::connection()->prepare('SELECT COUNT(*) FROM evolution_instances WHERE id = :id');
            $statement->execute(['id' => $instanceId]);
            if ((int) $statement->fetchColumn() < 1) {
                throw new RuntimeException('A conexão WhatsApp selecionada não existe.');
            }
        }

        Database::connection()->prepare(
            'INSERT INTO platform_notification_settings (id, whatsapp_instance_id, updated_by_user_id)
             VALUES (1, :instance_id, :user_id)
             ON DUPLICATE KEY UPDATE
                whatsapp_instance_id = VALUES(whatsapp_instance_id),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'instance_id' => $instanceId,
            'user_id' => $userId && $userId > 0 ? $userId : null,
        ]);
    }

    public function configuredSenderInstanceId(): ?int
    {
        $settings = $this->settings();
        $id = (int) ($settings['whatsapp_instance_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{queued:int,job_ids:array<int,int>,errors:array<int,string>,delivery:array<string,int>|null}
     */
    public function dispatch(
        int $tenantId,
        string $eventKey,
        string $entityType,
        int $entityId,
        string $channel,
        ?string $recipient,
        string $title,
        string $message,
        string $actionUrl,
        string $severity,
        array $payload,
        string $sourceType,
        int $sourceId
    ): array {
        if ($tenantId < 1 || $entityId < 1 || $sourceId < 1) {
            throw new RuntimeException('Contexto inválido para a notificação nativa.');
        }
        if (!in_array($channel, self::NATIVE_CHANNELS, true)) {
            throw new RuntimeException('Canal nativo inválido.');
        }
        if (!$this->columnExists('notification_jobs', 'sender_instance_id')
            || !$this->columnExists('notification_jobs', 'source_type')) {
            throw new RuntimeException('Execute a migration 125 para ativar as notificações financeiras nativas.');
        }

        $channels = match ($channel) {
            'native_in_app' => ['in_app'],
            'native_whatsapp' => ['whatsapp'],
            default => ['in_app', 'whatsapp'],
        };

        $recipient = preg_replace('/\D+/', '', (string) $recipient) ?: '';
        $senderInstanceId = $this->configuredSenderInstanceId();
        $queued = 0;
        $jobIds = [];
        $errors = [];

        foreach ($channels as $deliveryChannel) {
            if ($deliveryChannel === 'whatsapp' && ($recipient === '' || strlen($recipient) < 10)) {
                $errors[] = 'A empresa não possui um WhatsApp comercial/telefone válido para receber o aviso.';
                continue;
            }

            $jobId = $this->enqueue(
                $tenantId,
                $eventKey,
                $entityType,
                $entityId,
                $deliveryChannel,
                $deliveryChannel === 'whatsapp' ? $recipient : null,
                $deliveryChannel === 'whatsapp' ? $senderInstanceId : null,
                $title,
                $message,
                $actionUrl,
                $severity,
                $payload,
                $sourceType,
                $sourceId
            );
            if ($jobId > 0) {
                $queued++;
                $jobIds[] = $jobId;
            }
        }

        $delivery = null;
        if ($queued > 0) {
            $deliveryService = new NotificationDeliveryService();
            $delivery = $deliveryService->process(50, $tenantId);
            $deliveryService->syncSourceStatus($sourceType, $sourceId);
        }

        return [
            'queued' => $queued,
            'job_ids' => $jobIds,
            'errors' => $errors,
            'delivery' => $delivery,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function enqueue(
        int $tenantId,
        string $eventKey,
        string $entityType,
        int $entityId,
        string $channel,
        ?string $recipient,
        ?int $senderInstanceId,
        string $title,
        string $message,
        string $actionUrl,
        string $severity,
        array $payload,
        string $sourceType,
        int $sourceId
    ): int {
        $scheduledAt = Clock::nowUtc();
        $dedupe = hash('sha256', implode('|', [
            'platform', $tenantId, $eventKey, $entityType, $entityId, $channel,
            (string) $recipient, $sourceType, $sourceId,
        ]));

        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO notification_jobs
                (tenant_id, event_key, entity_type, entity_id, channel, recipient, sender_instance_id,
                 title, message, action_url, severity, payload_json, status, attempts, max_attempts,
                 scheduled_at, next_attempt_at, deduplication_key, source_type, source_id)
             VALUES
                (:tenant_id, :event_key, :entity_type, :entity_id, :channel, :recipient, :sender_instance_id,
                 :title, :message, :action_url, :severity, :payload_json, "pending", 0, :max_attempts,
                 :scheduled_at, :next_attempt_at, :deduplication_key, :source_type, :source_id)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'event_key' => mb_substr($eventKey, 0, 120),
            'entity_type' => mb_substr($entityType, 0, 80),
            'entity_id' => $entityId,
            'channel' => $channel,
            'recipient' => $recipient !== null ? mb_substr($recipient, 0, 190) : null,
            'sender_instance_id' => $senderInstanceId,
            'title' => mb_substr(trim($title), 0, 180),
            'message' => mb_substr(trim($message), 0, 3000),
            'action_url' => $actionUrl !== '' ? mb_substr($actionUrl, 0, 500) : null,
            'severity' => in_array($severity, ['info', 'success', 'warning', 'danger'], true) ? $severity : 'info',
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'max_attempts' => $channel === 'whatsapp' ? 24 : 4,
            'scheduled_at' => $scheduledAt,
            'next_attempt_at' => $scheduledAt,
            'deduplication_key' => $dedupe,
            'source_type' => mb_substr($sourceType, 0, 80),
            'source_id' => $sourceId,
        ]);

        if ($statement->rowCount() < 1) {
            $existing = Database::connection()->prepare(
                'SELECT id FROM notification_jobs WHERE deduplication_key = :dedupe LIMIT 1'
            );
            $existing->execute(['dedupe' => $dedupe]);
            return (int) ($existing->fetchColumn() ?: 0);
        }
        return (int) Database::connection()->lastInsertId();
    }

    private function senderIsConnected(int $instanceId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM evolution_instances
             WHERE id = :id AND status = "connected"
               AND api_key_encrypted IS NOT NULL AND api_key_encrypted <> ""'
        );
        $statement->execute(['id' => $instanceId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function tableExists(string $table): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = :table_name'
            );
            $statement->execute(['table_name' => $table]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE() AND table_name = :table_name AND column_name = :column_name'
            );
            $statement->execute(['table_name' => $table, 'column_name' => $column]);
            return (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }
}
