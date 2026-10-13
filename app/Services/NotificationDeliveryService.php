<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use PDO;
use RuntimeException;
use Throwable;

final class NotificationDeliveryService
{
    /** @return array<string,int> */
    public function process(int $limit = 50, ?int $tenantId = null): array
    {
        $summary = ['selected' => 0, 'sent' => 0, 'skipped' => 0, 'retry' => 0, 'failed' => 0];
        if (!$this->tableExists('notification_jobs')) {
            return $summary;
        }

        $pdo = Database::connection();
        $pdo->prepare(
            'UPDATE notification_jobs
             SET status = "retry", locked_at = NULL, next_attempt_at = UTC_TIMESTAMP()
             WHERE status = "processing" AND locked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)'
        )->execute();

        $sql = 'SELECT id FROM notification_jobs
                WHERE status IN ("pending", "retry")
                  AND scheduled_at <= UTC_TIMESTAMP()
                  AND next_attempt_at <= UTC_TIMESTAMP()';
        if ($tenantId !== null && $tenantId > 0) {
            $sql .= ' AND tenant_id = :tenant_id';
        }
        $sql .= ' ORDER BY next_attempt_at ASC, id ASC LIMIT :limit';
        $statement = $pdo->prepare($sql);
        if ($tenantId !== null && $tenantId > 0) {
            $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
        }
        $statement->bindValue('limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $statement->execute();
        $ids = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
        $summary['selected'] = count($ids);

        foreach ($ids as $id) {
            if (!$this->claim($id)) {
                continue;
            }
            $job = $this->job($id);
            if (!$job) {
                continue;
            }

            try {
                if ($this->shouldSkip($job)) {
                    $this->finish($id, 'skipped');
                    $this->syncJobSource($job);
                    $summary['skipped']++;
                    continue;
                }

                if ((string) $job['channel'] === 'in_app') {
                    if (!$this->sendInApp($job)) {
                        $this->finish($id, 'skipped');
                        $this->syncJobSource($job);
                        $summary['skipped']++;
                        continue;
                    }
                } elseif ((string) $job['channel'] === 'whatsapp') {
                    $this->sendWhatsApp($job);
                } else {
                    throw new RuntimeException('Canal de notificação não suportado.');
                }

                $this->finish($id, 'sent');
                $this->syncJobSource($job);
                $summary['sent']++;
            } catch (Throwable $exception) {
                $result = $this->fail($job, $exception->getMessage());
                $this->syncJobSource($job);
                $summary[$result]++;
            }
        }

        return $summary;
    }

    private function claim(int $id): bool
    {
        $statement = Database::connection()->prepare(
            'UPDATE notification_jobs
             SET status = "processing", locked_at = UTC_TIMESTAMP()
             WHERE id = :id AND status IN ("pending", "retry")'
        );
        $statement->execute(['id' => $id]);
        return $statement->rowCount() === 1;
    }

    /** @return array<string,mixed>|null */
    private function job(int $id): ?array
    {
        $statement = Database::connection()->prepare('SELECT * FROM notification_jobs WHERE id = :id LIMIT 1');
        $statement->execute(['id' => $id]);
        $job = $statement->fetch(PDO::FETCH_ASSOC);
        return $job ?: null;
    }

    /** @param array<string,mixed> $job */
    private function sendInApp(array $job): bool
    {
        $event = (string) ($job['event_key'] ?? 'system');
        $category = str_starts_with($event, 'calendar.')
            ? 'calendar'
            : ((str_starts_with($event, 'billing.') || str_starts_with($event, 'subscription.')) ? 'billing' : 'system');
        $type = str_starts_with($event, 'commercial.')
            ? 'commercial_request'
            : ((str_starts_with($event, 'billing.') || str_starts_with($event, 'subscription.')) ? 'billing' : 'calendar');
        $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
        return (new NotificationService())->createIfEnabled(
            (int) $job['tenant_id'],
            $category,
            (string) $job['title'],
            (string) $job['message'],
            (string) $job['severity'],
            (string) ($job['action_url'] ?? ''),
            $type,
            $event,
            (string) $job['entity_type'],
            (int) $job['entity_id'],
            is_array($payload) ? $payload : [],
            60
        );
    }

    /** @param array<string,mixed> $job */
    private function sendWhatsApp(array $job): void
    {
        $recipient = preg_replace('/\D+/', '', (string) ($job['recipient'] ?? '')) ?: '';
        if (strlen($recipient) < 10) {
            throw new RuntimeException('Destinatário de WhatsApp inválido.');
        }

        $sourceType = trim((string) ($job['source_type'] ?? ''));
        $platformFinancialJob = in_array($sourceType, ['billing_reminder_log', 'subscription_notification_log'], true);
        if ($platformFinancialJob) {
            $senderInstanceId = $this->platformSenderInstanceId();
            if ($senderInstanceId < 1) {
                throw new RuntimeException('Nenhuma conexão WhatsApp central da plataforma está configurada para os avisos financeiros.');
            }
            // Usa sempre a configuração central atual. Assim um job em retry se recupera
            // quando o administrador troca/reconecta o remetente, sem usar o número do tenant.
            Database::connection()->prepare(
                'UPDATE notification_jobs SET sender_instance_id = :sender_instance_id WHERE id = :id'
            )->execute(['sender_instance_id' => $senderInstanceId, 'id' => (int) ($job['id'] ?? 0)]);
            $instance = $this->instanceById($senderInstanceId);
        } else {
            $senderInstanceId = (int) ($job['sender_instance_id'] ?? 0);
            $instance = $senderInstanceId > 0
                ? $this->instanceById($senderInstanceId)
                : $this->instance((int) $job['tenant_id']);
        }
        if (!$instance) {
            throw new RuntimeException($platformFinancialJob || $senderInstanceId > 0
                ? 'A conexão WhatsApp da plataforma não está disponível ou conectada.'
                : 'Nenhuma conexão WhatsApp ativa está disponível para a empresa.');
        }
        $ownPhone = preg_replace('/\D+/', '', (string) ($instance['profile_phone'] ?? '')) ?: '';
        if ($ownPhone !== '' && $recipient === $ownPhone) {
            throw new RuntimeException('O destinatário da notificação não pode ser o próprio número conectado.');
        }

        $message = '*' . trim((string) $job['title']) . "*\n\n" . trim((string) $job['message']);
        $appUrl = rtrim((string) Env::get('APP_URL', ''), '/');
        $action = trim((string) ($job['action_url'] ?? ''));
        if ($appUrl !== '' && $action !== '') {
            $message .= "\n\nAbrir no RS Connect: " . $appUrl . '/' . ltrim($action, '/');
        }

        $service = new EvolutionService(
            (string) $instance['base_url'],
            Crypto::decrypt((string) $instance['api_key_encrypted']),
            (string) $instance['instance_name']
        );
        $service->sendText($recipient, $message);
    }

    /** @return array<string,mixed>|null */
    private function instance(int $tenantId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, base_url, api_key_encrypted, instance_name, status, connection_state, profile_phone
             FROM evolution_instances
             WHERE tenant_id = :tenant_id
               AND status = "connected"
               AND api_key_encrypted IS NOT NULL
               AND api_key_encrypted <> ""
             ORDER BY is_default DESC, id ASC
             LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId]);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);
        return $instance ?: null;
    }

    private function platformSenderInstanceId(): int
    {
        try {
            $statement = Database::connection()->query(
                'SELECT whatsapp_instance_id FROM platform_notification_settings WHERE id = 1 LIMIT 1'
            );
            return (int) ($statement->fetchColumn() ?: 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string,mixed>|null */
    private function instanceById(int $instanceId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT id, tenant_id, base_url, api_key_encrypted, instance_name, status, connection_state, profile_phone
             FROM evolution_instances
             WHERE id = :id
               AND status = "connected"
               AND api_key_encrypted IS NOT NULL
               AND api_key_encrypted <> ""
             LIMIT 1'
        );
        $statement->execute(['id' => $instanceId]);
        $instance = $statement->fetch(PDO::FETCH_ASSOC);
        return $instance ?: null;
    }

    /**
     * Sincroniza o histórico funcional que originou jobs nativos. Um aviso com
     * dois canais só é concluído quando todos os jobs deixam de estar pendentes.
     */
    public function syncSourceStatus(string $sourceType, int $sourceId): void
    {
        $sourceType = trim($sourceType);
        if ($sourceType === '' || $sourceId < 1 || !$this->columnExists('notification_jobs', 'source_type')) {
            return;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT status, COUNT(*) total
                 FROM notification_jobs
                 WHERE source_type = :source_type AND source_id = :source_id
                 GROUP BY status'
            );
            $statement->execute(['source_type' => $sourceType, 'source_id' => $sourceId]);
            $counts = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $counts[(string) $row['status']] = (int) $row['total'];
            }
            if ($counts === []) {
                return;
            }

            $waiting = (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0) + (int) ($counts['retry'] ?? 0);
            if ($waiting > 0) {
                $status = 'queued';
                $processedAt = null;
            } elseif ((int) ($counts['failed'] ?? 0) > 0) {
                $status = 'error';
                $processedAt = 'NOW()';
            } elseif ((int) ($counts['sent'] ?? 0) > 0) {
                $status = 'sent';
                $processedAt = 'NOW()';
            } else {
                $status = 'skipped';
                $processedAt = 'NOW()';
            }

            $table = match ($sourceType) {
                'billing_reminder_log' => 'billing_reminder_logs',
                'subscription_notification_log' => 'subscription_notification_logs',
                default => null,
            };
            if ($table === null || !$this->tableExists($table)) {
                return;
            }

            $result = json_encode(['notification_jobs' => $counts], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $sql = 'UPDATE ' . $table . ' SET status = :status, result_json = :result, processed_at = '
                . ($processedAt === null ? 'NULL' : $processedAt) . ' WHERE id = :id';
            Database::connection()->prepare($sql)->execute([
                'status' => $status,
                'result' => $result,
                'id' => $sourceId,
            ]);
        } catch (Throwable) {
            // A entrega não deve falhar apenas porque o histórico auxiliar não pôde ser sincronizado.
        }
    }

    /** @param array<string,mixed> $job */
    private function syncJobSource(array $job): void
    {
        $sourceType = trim((string) ($job['source_type'] ?? ''));
        $sourceId = (int) ($job['source_id'] ?? 0);
        if ($sourceType !== '' && $sourceId > 0) {
            $this->syncSourceStatus($sourceType, $sourceId);
        }
    }

    /** @param array<string,mixed> $job */
    private function shouldSkip(array $job): bool
    {
        $event = (string) ($job['event_key'] ?? '');
        $entityType = (string) ($job['entity_type'] ?? '');
        $entityId = (int) ($job['entity_id'] ?? 0);
        $tenantId = (int) ($job['tenant_id'] ?? 0);

        if ($event === 'commercial.quote.overdue' && $entityType === 'crm_commercial_request') {
            $statement = Database::connection()->prepare(
                'SELECT status FROM crm_commercial_requests WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
            );
            $statement->execute(['id' => $entityId, 'tenant_id' => $tenantId]);
            return (string) $statement->fetchColumn() !== 'pending';
        }

        if ($event === 'calendar.appointment.reminder' && $entityType === 'appointment') {
            $statement = Database::connection()->prepare(
                'SELECT status, starts_at FROM calendar_appointments WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
            );
            $statement->execute(['id' => $entityId, 'tenant_id' => $tenantId]);
            $appointment = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            $status = (string) ($appointment['status'] ?? '');
            if (!in_array($status, ['scheduled', 'confirmed'], true)) {
                return true;
            }
            $payload = json_decode((string) ($job['payload_json'] ?? ''), true);
            $expectedStart = trim((string) (is_array($payload) ? ($payload['starts_at'] ?? '') : ''));
            $currentStart = trim((string) ($appointment['starts_at'] ?? ''));
            return $expectedStart !== '' && $currentStart !== '' && $expectedStart !== $currentStart;
        }

        return false;
    }

    private function finish(int $id, string $status): void
    {
        $statement = Database::connection()->prepare(
            'UPDATE notification_jobs
             SET status = :status,
                 attempts = attempts + 1,
                 locked_at = NULL,
                 sent_at = CASE WHEN :sent_status = "sent" THEN UTC_TIMESTAMP() ELSE sent_at END,
                 last_error = NULL
             WHERE id = :id'
        );
        $statement->execute(['status' => $status, 'sent_status' => $status, 'id' => $id]);
    }

    /** @param array<string,mixed> $job */
    private function fail(array $job, string $error): string
    {
        $attempts = (int) ($job['attempts'] ?? 0) + 1;
        $maxAttempts = max(1, (int) ($job['max_attempts'] ?? 4));
        $id = (int) $job['id'];
        $error = mb_substr(trim($error), 0, 1500);

        if ($attempts >= $maxAttempts) {
            Database::connection()->prepare(
                'UPDATE notification_jobs
                 SET status = "failed", attempts = :attempts, failed_at = UTC_TIMESTAMP(),
                     locked_at = NULL, last_error = :error
                 WHERE id = :id'
            )->execute(['attempts' => $attempts, 'error' => $error, 'id' => $id]);
            (new NotificationService())->createIfEnabled(
                (int) ($job['tenant_id'] ?? 0),
                'automation_errors',
                'Falha ao enviar notificação',
                'O aviso “' . mb_substr((string) ($job['title'] ?? 'Notificação'), 0, 120) . '” não foi entregue após ' . $attempts . ' tentativas.',
                'warning',
                '/notifications#automatic-notifications',
                'automation',
                'notification.delivery_failed',
                'notification_job',
                $id,
                ['error' => $error, 'channel' => (string) ($job['channel'] ?? '')],
                300
            );
            return 'failed';
        }

        $delays = [1 => 1, 2 => 5, 3 => 15, 4 => 60];
        $delay = $delays[$attempts] ?? 60;
        Database::connection()->prepare(
            'UPDATE notification_jobs
             SET status = "retry", attempts = :attempts, locked_at = NULL,
                 next_attempt_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ' . $delay . ' MINUTE),
                 last_error = :error
             WHERE id = :id'
        )->execute(['attempts' => $attempts, 'error' => $error, 'id' => $id]);
        return 'retry';
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
