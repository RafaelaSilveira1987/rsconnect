<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use PDO;
use Throwable;

final class ClientCommunicationService
{
    public function dashboard(): array
    {
        return [
            'tenants' => $this->tenants(),
            'history' => $this->history(),
            'replies' => $this->recentReplies(),
            'summary' => $this->summary(),
        ];
    }

    public function send(array $data): int
    {
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 180);
        $message = trim((string) ($data['message'] ?? ''));
        if ($title === '' || $message === '') {
            throw new \RuntimeException('Informe título e mensagem.');
        }

        $type = (string) ($data['communication_type'] ?? 'information');
        if (!in_array($type, ['information', 'maintenance', 'attention', 'incident', 'resolved'], true)) {
            $type = 'information';
        }
        $priority = (string) ($data['priority'] ?? 'normal');
        if (!in_array($priority, ['normal', 'important', 'critical'], true)) {
            $priority = 'normal';
        }
        $responseMode = (string) ($data['response_mode'] ?? 'none');
        if (!in_array($responseMode, ['none', 'acknowledge', 'reply'], true)) {
            $responseMode = 'none';
        }
        $audience = (string) ($data['audience_type'] ?? 'selected');
        if (!in_array($audience, ['selected', 'all', 'incident'], true)) {
            $audience = 'selected';
        }

        $incidentId = (int) ($data['incident_id'] ?? 0);
        $channels = [
            'in_app' => 1,
            'whatsapp' => !empty($data['channel_whatsapp']) ? 1 : 0,
            'email' => !empty($data['channel_email']) ? 1 : 0,
        ];
        $expiresAt = $this->parseExpiresAt((string) ($data['expires_at'] ?? ''));
        $tenantIds = $this->resolveAudience($audience, $incidentId, (array) ($data['tenant_ids'] ?? []));
        if ($tenantIds === []) {
            throw new \RuntimeException('Selecione pelo menos uma empresa.');
        }

        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO client_communications
                    (communication_type, priority, title, message, response_mode, audience_type, incident_id, channels_json, created_by, sent_at, expires_at)
                 VALUES
                    (:type, :priority, :title, :message, :response_mode, :audience, :incident, :channels, :user, NOW(), :expires_at)'
            );
            $statement->execute([
                'type' => $type,
                'priority' => $priority,
                'title' => $title,
                'message' => $message,
                'response_mode' => $responseMode,
                'audience' => $audience,
                'incident' => $incidentId > 0 ? $incidentId : null,
                'channels' => json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'user' => Auth::id(),
                'expires_at' => $expiresAt,
            ]);
            $communicationId = (int) $pdo->lastInsertId();

            foreach ($tenantIds as $tenantId) {
                $notificationId = $this->notification($pdo, $tenantId, $type, $priority, $title, $message, $communicationId, $responseMode);
                $whatsapp = $channels['whatsapp'] ? 'queued' : 'not_requested';
                $email = $channels['email'] ? 'queued' : 'not_requested';
                $pdo->prepare(
                    'INSERT INTO client_communication_recipients
                        (communication_id, tenant_id, notification_id, in_app_status, whatsapp_status, email_status)
                     VALUES (:communication, :tenant, :notification, "sent", :whatsapp, :email)'
                )->execute([
                    'communication' => $communicationId,
                    'tenant' => $tenantId,
                    'notification' => $notificationId ?: null,
                    'whatsapp' => $whatsapp,
                    'email' => $email,
                ]);
            }

            $pdo->commit();
            $this->deliverExternalChannels(
                $communicationId,
                $tenantIds,
                $channels,
                $title,
                $message,
                $incidentId
            );
            return $communicationId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed> */
    public function inbox(int $tenantId, int $limit = 30): array
    {
        if ($tenantId < 1) {
            return ['unread' => 0, 'items' => [], 'latest' => null];
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT c.id, c.communication_type, c.priority, c.title, c.message, c.response_mode,
                        c.incident_id, c.sent_at, c.expires_at,
                        r.read_at, r.tenant_last_seen_at, r.acknowledged_at, r.last_reply_at,
                        (SELECT COUNT(*) FROM client_communication_replies cr
                         WHERE cr.communication_id = c.id AND cr.tenant_id = r.tenant_id) AS reply_count,
                        CASE WHEN r.read_at IS NULL OR EXISTS (
                            SELECT 1 FROM client_communication_replies unread_reply
                            WHERE unread_reply.communication_id = c.id
                              AND unread_reply.tenant_id = r.tenant_id
                              AND unread_reply.direction = "rs_to_tenant"
                              AND unread_reply.created_at > COALESCE(r.tenant_last_seen_at, "1970-01-01 00:00:00")
                        ) THEN 1 ELSE 0 END AS is_unread
                 FROM client_communication_recipients r
                 INNER JOIN client_communications c ON c.id = r.communication_id
                 WHERE r.tenant_id = :tenant_id
                   AND r.in_app_status = "sent"
                   AND c.sent_at IS NOT NULL AND c.sent_at <= NOW()
                   AND (c.expires_at IS NULL OR c.expires_at >= NOW())
                 ORDER BY is_unread DESC, COALESCE(r.last_reply_at, c.sent_at) DESC, c.id DESC
                 LIMIT :limit'
            );
            $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
            $statement->bindValue('limit', max(5, min(60, $limit)), PDO::PARAM_INT);
            $statement->execute();
            $items = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $unread = count(array_filter($items, static fn (array $item): bool => (int) ($item['is_unread'] ?? 0) === 1));
            $latest = null;
            foreach ($items as $item) {
                if ((int) ($item['is_unread'] ?? 0) === 1) {
                    $latest = $item;
                    break;
                }
            }
            return ['unread' => $unread, 'items' => $items, 'latest' => $latest];
        } catch (Throwable) {
            return ['unread' => 0, 'items' => [], 'latest' => null];
        }
    }

    /** @return array<string,mixed>|null */
    public function thread(int $tenantId, int $communicationId): ?array
    {
        if ($tenantId < 1 || $communicationId < 1) {
            return null;
        }
        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare(
                'SELECT c.id, c.communication_type, c.priority, c.title, c.message, c.response_mode,
                        c.incident_id, c.sent_at, c.expires_at,
                        r.read_at, r.tenant_last_seen_at, r.acknowledged_at, r.last_reply_at
                 FROM client_communication_recipients r
                 INNER JOIN client_communications c ON c.id = r.communication_id
                 WHERE r.tenant_id = :tenant_id AND c.id = :communication_id AND r.in_app_status = "sent"
                 LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId, 'communication_id' => $communicationId]);
            $communication = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$communication) {
                return null;
            }
            $replyStatement = $pdo->prepare(
                'SELECT cr.id, cr.direction, cr.message, cr.created_at, u.name AS user_name
                 FROM client_communication_replies cr
                 LEFT JOIN users u ON u.id = cr.user_id
                 WHERE cr.communication_id = :communication_id AND cr.tenant_id = :tenant_id
                 ORDER BY cr.id ASC'
            );
            $replyStatement->execute(['communication_id' => $communicationId, 'tenant_id' => $tenantId]);
            $communication['replies'] = $replyStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return $communication;
        } catch (Throwable) {
            return null;
        }
    }

    public function markRead(int $tenantId, int $communicationId, ?int $userId): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'UPDATE client_communication_recipients
                 SET read_at = COALESCE(read_at, NOW()), tenant_last_seen_at = NOW(), read_by_user_id = COALESCE(read_by_user_id, :user_id)
                 WHERE tenant_id = :tenant_id AND communication_id = :communication_id'
            );
            $statement->execute([
                'user_id' => $userId && $userId > 0 ? $userId : null,
                'tenant_id' => $tenantId,
                'communication_id' => $communicationId,
            ]);
            $pdo->prepare(
                'UPDATE client_notifications n
                 INNER JOIN client_communication_recipients r ON r.notification_id = n.id
                 SET n.status = "read", n.read_at = COALESCE(n.read_at, NOW())
                 WHERE r.tenant_id = :tenant_id AND r.communication_id = :communication_id'
            )->execute(['tenant_id' => $tenantId, 'communication_id' => $communicationId]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function acknowledge(int $tenantId, int $communicationId, ?int $userId): void
    {
        $this->assertResponseMode($tenantId, $communicationId, ['acknowledge']);
        $this->markRead($tenantId, $communicationId, $userId);
        Database::connection()->prepare(
            'UPDATE client_communication_recipients
             SET acknowledged_at = COALESCE(acknowledged_at, NOW()),
                 acknowledged_by_user_id = COALESCE(acknowledged_by_user_id, :user_id)
             WHERE tenant_id = :tenant_id AND communication_id = :communication_id'
        )->execute([
            'user_id' => $userId && $userId > 0 ? $userId : null,
            'tenant_id' => $tenantId,
            'communication_id' => $communicationId,
        ]);
    }

    public function tenantReply(int $tenantId, int $communicationId, ?int $userId, string $message): int
    {
        $this->assertResponseMode($tenantId, $communicationId, ['reply']);
        return $this->insertReply($communicationId, $tenantId, $userId, 'tenant_to_rs', $message);
    }

    public function adminReply(int $communicationId, int $tenantId, ?int $userId, string $message): int
    {
        $this->assertResponseMode($tenantId, $communicationId, ['reply']);
        return $this->insertReply($communicationId, $tenantId, $userId, 'rs_to_tenant', $message);
    }

    private function insertReply(int $communicationId, int $tenantId, ?int $userId, string $direction, string $message): int
    {
        $message = trim($message);
        if ($message === '') {
            throw new \RuntimeException('Escreva uma mensagem antes de enviar.');
        }
        $message = mb_substr($message, 0, 3000);
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO client_communication_replies (communication_id, tenant_id, user_id, direction, message)
                 VALUES (:communication_id, :tenant_id, :user_id, :direction, :message)'
            );
            $statement->execute([
                'communication_id' => $communicationId,
                'tenant_id' => $tenantId,
                'user_id' => $userId && $userId > 0 ? $userId : null,
                'direction' => $direction,
                'message' => $message,
            ]);
            $replyId = (int) $pdo->lastInsertId();
            if ($direction === 'tenant_to_rs') {
                $pdo->prepare(
                    'UPDATE client_communication_recipients
                     SET last_reply_at = NOW(), read_at = COALESCE(read_at, NOW()), tenant_last_seen_at = NOW(),
                         read_by_user_id = COALESCE(read_by_user_id, :user_id)
                     WHERE communication_id = :communication_id AND tenant_id = :tenant_id'
                )->execute([
                    'user_id' => $userId && $userId > 0 ? $userId : null,
                    'communication_id' => $communicationId,
                    'tenant_id' => $tenantId,
                ]);
                $pdo->prepare(
                    'UPDATE client_notifications n
                     INNER JOIN client_communication_recipients r ON r.notification_id = n.id
                     SET n.status = "read", n.read_at = COALESCE(n.read_at, NOW())
                     WHERE r.communication_id = :communication_id AND r.tenant_id = :tenant_id'
                )->execute(['communication_id' => $communicationId, 'tenant_id' => $tenantId]);
            } else {
                $pdo->prepare(
                    'UPDATE client_communication_recipients
                     SET last_reply_at = NOW()
                     WHERE communication_id = :communication_id AND tenant_id = :tenant_id'
                )->execute(['communication_id' => $communicationId, 'tenant_id' => $tenantId]);
                $pdo->prepare(
                    'UPDATE client_notifications n
                     INNER JOIN client_communication_recipients r ON r.notification_id = n.id
                     SET n.status = "unread", n.read_at = NULL, n.updated_at = CURRENT_TIMESTAMP
                     WHERE r.communication_id = :communication_id AND r.tenant_id = :tenant_id'
                )->execute(['communication_id' => $communicationId, 'tenant_id' => $tenantId]);
            }
            $pdo->commit();
            if ($direction === 'tenant_to_rs') {
                $this->notifyAdminsOfReply($communicationId, $tenantId, $message);
            }
            return $replyId;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function notifyAdminsOfReply(int $communicationId, int $tenantId, string $message): void
    {
        try {
            $pdo = Database::connection();
            $context = $pdo->prepare(
                'SELECT c.title, t.name AS tenant_name
                 FROM client_communications c
                 INNER JOIN tenants t ON t.id = :tenant_id
                 WHERE c.id = :communication_id LIMIT 1'
            );
            $context->execute(['tenant_id' => $tenantId, 'communication_id' => $communicationId]);
            $row = $context->fetch(PDO::FETCH_ASSOC) ?: [];
            $title = 'Resposta em comunicado — ' . ((string) ($row['tenant_name'] ?? 'Empresa'));
            $body = mb_substr(trim($message), 0, 500);
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'")->fetchAll(PDO::FETCH_COLUMN) ?: [];
            $statement = $pdo->prepare(
                'INSERT INTO admin_operational_notifications
                    (user_id, incident_id, notification_kind, severity, title, message, action_url)
                 VALUES (:user_id, NULL, "manual", "info", :title, :message, "/comunicados")'
            );
            foreach ($admins as $adminId) {
                $statement->execute([
                    'user_id' => (int) $adminId,
                    'title' => mb_substr($title, 0, 180),
                    'message' => $body !== '' ? $body : 'A empresa respondeu a um comunicado.',
                ]);
            }
        } catch (Throwable) {
            // A resposta do cliente não pode falhar por causa de uma notificação administrativa secundária.
        }
    }

    private function assertResponseMode(int $tenantId, int $communicationId, array $allowed): void
    {
        $row = $this->recipientCommunication($tenantId, $communicationId);
        if (!$row) {
            throw new \RuntimeException('Comunicado não encontrado.');
        }
        if (!in_array((string) ($row['response_mode'] ?? 'none'), $allowed, true)) {
            throw new \RuntimeException('Este comunicado não aceita esta ação.');
        }
        if (!empty($row['expires_at']) && strtotime((string) $row['expires_at']) < time()) {
            throw new \RuntimeException('Este comunicado já expirou.');
        }
    }

    private function assertRecipient(int $tenantId, int $communicationId): void
    {
        if (!$this->recipientCommunication($tenantId, $communicationId)) {
            throw new \RuntimeException('Destinatário do comunicado não encontrado.');
        }
    }

    /** @return array<string,mixed>|null */
    private function recipientCommunication(int $tenantId, int $communicationId): ?array
    {
        $statement = Database::connection()->prepare(
            'SELECT c.response_mode, c.expires_at
             FROM client_communication_recipients r
             INNER JOIN client_communications c ON c.id = r.communication_id
             WHERE r.tenant_id = :tenant_id AND c.id = :communication_id
             LIMIT 1'
        );
        $statement->execute(['tenant_id' => $tenantId, 'communication_id' => $communicationId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function notification(PDO $pdo, int $tenantId, string $type, string $priority, string $title, string $message, int $communicationId, string $responseMode): int
    {
        $severity = ['information' => 'info', 'maintenance' => 'warning', 'attention' => 'warning', 'incident' => 'danger', 'resolved' => 'success'][$type] ?? 'info';
        if ($priority === 'critical') {
            $severity = 'danger';
        }
        $statement = $pdo->prepare(
            'INSERT INTO client_notifications
                (tenant_id,type,severity,title,message,action_url,source_event,reference_type,reference_id,metadata_json)
             VALUES
                (:tenant,"communication",:severity,:title,:message,:action_url,"rs.communication","communication",:id,:meta)'
        );
        $statement->execute([
            'tenant' => $tenantId,
            'severity' => $severity,
            'title' => mb_substr($title, 0, 160),
            'message' => $message,
            'action_url' => '/notifications?communication_id=' . $communicationId,
            'id' => $communicationId,
            'meta' => json_encode([
                'communication_type' => $type,
                'priority' => $priority,
                'response_mode' => $responseMode,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) $pdo->lastInsertId();
    }

    /** @return array<int,int> */
    private function resolveAudience(string $audience, int $incidentId, array $selected): array
    {
        if ($audience === 'all') {
            return array_map('intval', array_column($this->tenants(), 'id'));
        }
        if ($audience === 'incident' && $incidentId > 0) {
            try {
                $statement = Database::connection()->prepare('SELECT tenant_id FROM system_incidents WHERE id = :id AND tenant_id IS NOT NULL LIMIT 1');
                $statement->execute(['id' => $incidentId]);
                $tenantId = (int) $statement->fetchColumn();
                if ($tenantId > 0) {
                    return [$tenantId];
                }
            } catch (Throwable) {
                return [];
            }
        }
        return array_values(array_unique(array_filter(array_map('intval', $selected), static fn (int $id): bool => $id > 0)));
    }

    /** @param array<string,int> $channels @param array<int,int> $tenantIds */
    private function deliverExternalChannels(
        int $communicationId,
        array $tenantIds,
        array $channels,
        string $title,
        string $message,
        int $incidentId
    ): void {
        if (empty($channels['whatsapp']) && empty($channels['email'])) {
            return;
        }

        try {
            $placeholders = implode(',', array_fill(0, count($tenantIds), '?'));
            $statement = Database::connection()->prepare(
                "SELECT id, name, email, COALESCE(NULLIF(commercial_whatsapp,''),phone) AS admin_phone
                 FROM tenants WHERE id IN ($placeholders)"
            );
            foreach (array_values($tenantIds) as $index => $tenantId) {
                $statement->bindValue($index + 1, (int) $tenantId, PDO::PARAM_INT);
            }
            $statement->execute();
            $tenants = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            $tenants = [];
        }

        $byId = [];
        foreach ($tenants as $tenant) {
            $byId[(int) ($tenant['id'] ?? 0)] = $tenant;
        }

        $transport = new OperationalAlertService();
        $url = $this->communicationUrl($communicationId);
        foreach ($tenantIds as $tenantId) {
            $tenant = $byId[(int) $tenantId] ?? [];
            if (!empty($channels['whatsapp'])) {
                $destination = preg_replace('/\D+/', '', (string) ($tenant['admin_phone'] ?? '')) ?: '';
                $result = $transport->sendExternalWhatsapp($destination, $title, $message, $url);
                $this->updateExternalDelivery(
                    $communicationId,
                    (int) $tenantId,
                    'whatsapp',
                    $result
                );
            }
            if (!empty($channels['email'])) {
                $destination = trim((string) ($tenant['email'] ?? ''));
                $result = $transport->sendExternalEmail(
                    $destination,
                    $title,
                    $message,
                    $url,
                    $incidentId,
                    'client_communication'
                );
                $this->updateExternalDelivery(
                    $communicationId,
                    (int) $tenantId,
                    'email',
                    $result
                );
            }
        }
    }

    /** @param array<string,mixed> $result */
    private function updateExternalDelivery(int $communicationId, int $tenantId, string $channel, array $result): void
    {
        $status = !empty($result['ok'])
            ? 'sent'
            : (!empty($result['configured']) ? 'error' : 'pending_configuration');
        $providerId = trim((string) ($result['provider_message_id'] ?? ''));
        $error = !empty($result['ok']) ? null : mb_substr(trim((string) ($result['message'] ?? 'Falha não identificada.')), 0, 500);
        $sentAt = !empty($result['ok']) ? date('Y-m-d H:i:s') : null;

        $allowed = ['whatsapp', 'email'];
        if (!in_array($channel, $allowed, true)) {
            return;
        }

        try {
            $sql = "UPDATE client_communication_recipients
                    SET {$channel}_status = :status,
                        {$channel}_provider_message_id = :provider_id,
                        {$channel}_error = :error,
                        {$channel}_sent_at = :sent_at
                    WHERE communication_id = :communication_id AND tenant_id = :tenant_id";
            Database::connection()->prepare($sql)->execute([
                'status' => $status,
                'provider_id' => $providerId !== '' ? $providerId : null,
                'error' => $error,
                'sent_at' => $sentAt,
                'communication_id' => $communicationId,
                'tenant_id' => $tenantId,
            ]);
        } catch (Throwable) {
            // Compatibilidade temporária caso o código suba antes da migration 073.
            try {
                Database::connection()->prepare(
                    "UPDATE client_communication_recipients
                     SET {$channel}_status = :status
                     WHERE communication_id = :communication_id AND tenant_id = :tenant_id"
                )->execute([
                    'status' => $status,
                    'communication_id' => $communicationId,
                    'tenant_id' => $tenantId,
                ]);
            } catch (Throwable) {
            }
        }
    }

    private function communicationUrl(int $communicationId): string
    {
        $path = '/notifications?communication_id=' . $communicationId;
        $base = rtrim(trim((string) Env::get('APP_URL', '')), '/');
        return $base !== '' ? $base . $path : $path;
    }

    private function parseExpiresAt(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
            if ($date->getTimestamp() <= time()) {
                throw new \RuntimeException('A validade precisa ser futura.');
            }
            return $date->format('Y-m-d H:i:s');
        } catch (\RuntimeException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new \RuntimeException('Informe uma validade válida.');
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function tenants(): array
    {
        try {
            return Database::connection()->query(
                "SELECT id,name,email,COALESCE(NULLIF(commercial_whatsapp,''),phone) AS admin_phone,plan,status
                 FROM tenants WHERE status IN ('active','suspended') ORDER BY name"
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            try {
                return Database::connection()->query(
                    "SELECT id,name,email,phone AS admin_phone,plan,status
                     FROM tenants WHERE status IN ('active','suspended') ORDER BY name"
                )->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (Throwable) {
                return [];
            }
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function history(): array
    {
        try {
            return Database::connection()->query(
                'SELECT c.*,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id) AS recipients,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.read_at IS NOT NULL) AS read_count,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND (r.read_at IS NULL OR EXISTS (
                        SELECT 1 FROM client_communication_replies ur WHERE ur.communication_id = c.id AND ur.tenant_id = r.tenant_id
                        AND ur.direction = "rs_to_tenant" AND ur.created_at > COALESCE(r.tenant_last_seen_at, "1970-01-01 00:00:00")
                    ))) AS unread_count,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.acknowledged_at IS NOT NULL) AS acknowledged_count,
                    (SELECT COUNT(*) FROM client_communication_replies cr WHERE cr.communication_id = c.id AND cr.direction = "tenant_to_rs") AS reply_count,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.whatsapp_status = "pending_configuration") AS whatsapp_pending,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.whatsapp_status = "queued") AS whatsapp_queued,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.whatsapp_status = "sent") AS whatsapp_sent,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.whatsapp_status = "error") AS whatsapp_error,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.email_status = "pending_configuration") AS email_pending,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.email_status = "queued") AS email_queued,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.email_status = "sent") AS email_sent,
                    (SELECT COUNT(*) FROM client_communication_recipients r WHERE r.communication_id = c.id AND r.email_status = "error") AS email_error
                 FROM client_communications c
                 ORDER BY c.id DESC LIMIT 60'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function recentReplies(): array
    {
        try {
            return Database::connection()->query(
                'SELECT cr.*, c.title, c.response_mode, t.name AS tenant_name, u.name AS user_name
                 FROM client_communication_replies cr
                 INNER JOIN client_communications c ON c.id = cr.communication_id
                 INNER JOIN tenants t ON t.id = cr.tenant_id
                 LEFT JOIN users u ON u.id = cr.user_id
                 ORDER BY cr.id DESC LIMIT 80'
            )->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable) {
            return [];
        }
    }

    private function activeIncidentCount(): int
    {
        try {
            return (int) Database::connection()->query(
                "SELECT COUNT(*) FROM client_communications c
                 LEFT JOIN system_incidents i ON i.id = c.incident_id
                 WHERE c.communication_type = 'incident'
                   AND (c.expires_at IS NULL OR c.expires_at >= NOW())
                   AND (c.incident_id IS NULL OR i.resolved_at IS NULL)"
            )->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<string,int> */
    private function summary(): array
    {
        $history = $this->history();
        return [
            'sent' => count($history),
            'recipients' => array_sum(array_map(static fn (array $row): int => (int) ($row['recipients'] ?? 0), $history)),
            'read' => array_sum(array_map(static fn (array $row): int => (int) ($row['read_count'] ?? 0), $history)),
            'unread' => array_sum(array_map(static fn (array $row): int => (int) ($row['unread_count'] ?? 0), $history)),
            'replies' => array_sum(array_map(static fn (array $row): int => (int) ($row['reply_count'] ?? 0), $history)),
            'active_incidents' => $this->activeIncidentCount(),
        ];
    }
}
