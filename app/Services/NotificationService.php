<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class NotificationService
{
    private const DEFAULT_PREFERENCES = [
        'messages_enabled' => 1,
        'ai_errors_enabled' => 1,
        'automation_errors_enabled' => 1,
        'calendar_enabled' => 1,
        'billing_enabled' => 1,
        'system_enabled' => 1,
    ];

    private const CATEGORY_COLUMNS = [
        'messages' => 'messages_enabled',
        'ai_errors' => 'ai_errors_enabled',
        'automation_errors' => 'automation_errors_enabled',
        'calendar' => 'calendar_enabled',
        'billing' => 'billing_enabled',
        'system' => 'system_enabled',
    ];

    private static ?bool $preferencesTableExists = null;

    public function unreadCount(?int $tenantId): int
    {
        if (!$tenantId) {
            return 0;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM client_notifications WHERE tenant_id = :tenant_id AND status = "unread"'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            return (int) $statement->fetchColumn();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function latestForTenant(int $tenantId, int $limit = 20): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT * FROM client_notifications
                 WHERE tenant_id = :tenant_id
                 ORDER BY status = "unread" DESC, created_at DESC, id DESC
                 LIMIT :limit'
            );
            $statement->bindValue('tenant_id', $tenantId, PDO::PARAM_INT);
            $statement->bindValue('limit', max(5, min(100, $limit)), PDO::PARAM_INT);
            $statement->execute();
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed>|null */
    public function latestUnreadForTenant(int $tenantId): ?array
    {
        if ($tenantId < 1) {
            return null;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT id, type, severity, title, message, action_url, source_event, created_at
                 FROM client_notifications
                 WHERE tenant_id = :tenant_id AND status = "unread"
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    public function markAllRead(int $tenantId): void
    {
        Database::connection()->prepare(
            'UPDATE client_notifications
             SET status = "read", read_at = COALESCE(read_at, NOW())
             WHERE tenant_id = :tenant_id AND status = "unread"'
        )->execute(['tenant_id' => $tenantId]);
    }

    /** @return array<string,int> */
    public function preferences(int $tenantId): array
    {
        if ($tenantId < 1 || !$this->preferencesTableExists()) {
            return self::DEFAULT_PREFERENCES;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT messages_enabled, ai_errors_enabled, automation_errors_enabled,
                        calendar_enabled, billing_enabled, system_enabled
                 FROM tenant_notification_preferences
                 WHERE tenant_id = :tenant_id
                 LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            $preferences = self::DEFAULT_PREFERENCES;
            foreach ($preferences as $key => $default) {
                if (array_key_exists($key, $row)) {
                    $preferences[$key] = (int) $row[$key] === 1 ? 1 : 0;
                }
            }
            return $preferences;
        } catch (Throwable) {
            return self::DEFAULT_PREFERENCES;
        }
    }

    /** @param array<string,mixed> $data */
    public function savePreferences(int $tenantId, array $data, ?int $updatedByUserId = null): void
    {
        if ($tenantId < 1) {
            throw new \RuntimeException('Empresa inválida.');
        }
        if (!$this->preferencesTableExists()) {
            throw new \RuntimeException('Execute a migration 034 para ativar as preferências de notificação.');
        }

        $values = [];
        foreach (self::DEFAULT_PREFERENCES as $key => $default) {
            $values[$key] = !empty($data[$key]) ? 1 : 0;
        }

        Database::connection()->prepare(
            'INSERT INTO tenant_notification_preferences
                (tenant_id, messages_enabled, ai_errors_enabled, automation_errors_enabled,
                 calendar_enabled, billing_enabled, system_enabled, updated_by_user_id)
             VALUES
                (:tenant_id, :messages_enabled, :ai_errors_enabled, :automation_errors_enabled,
                 :calendar_enabled, :billing_enabled, :system_enabled, :updated_by_user_id)
             ON DUPLICATE KEY UPDATE
                messages_enabled = VALUES(messages_enabled),
                ai_errors_enabled = VALUES(ai_errors_enabled),
                automation_errors_enabled = VALUES(automation_errors_enabled),
                calendar_enabled = VALUES(calendar_enabled),
                billing_enabled = VALUES(billing_enabled),
                system_enabled = VALUES(system_enabled),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = CURRENT_TIMESTAMP'
        )->execute([
            'tenant_id' => $tenantId,
            'messages_enabled' => $values['messages_enabled'],
            'ai_errors_enabled' => $values['ai_errors_enabled'],
            'automation_errors_enabled' => $values['automation_errors_enabled'],
            'calendar_enabled' => $values['calendar_enabled'],
            'billing_enabled' => $values['billing_enabled'],
            'system_enabled' => $values['system_enabled'],
            'updated_by_user_id' => $updatedByUserId && $updatedByUserId > 0 ? $updatedByUserId : null,
        ]);
    }

    public function isCategoryEnabled(int $tenantId, string $category): bool
    {
        $column = self::CATEGORY_COLUMNS[$category] ?? null;
        if ($column === null) {
            return true;
        }
        $preferences = $this->preferences($tenantId);
        return (int) ($preferences[$column] ?? 1) === 1;
    }

    /**
     * Cria uma notificação respeitando a preferência da empresa e sem interromper o fluxo principal
     * caso a tabela ainda não tenha sido migrada ou ocorra uma falha secundária.
     *
     * @param array<string,mixed> $metadata
     */
    public function createIfEnabled(
        int $tenantId,
        string $category,
        string $title,
        string $message,
        string $severity = 'info',
        ?string $actionUrl = null,
        string $type = 'system',
        ?string $sourceEvent = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = [],
        int $dedupeSeconds = 0
    ): bool {
        try {
            if (!$this->isCategoryEnabled($tenantId, $category)) {
                return false;
            }

            if ($dedupeSeconds > 0 && $this->hasRecentUnreadDuplicate(
                $tenantId,
                $sourceEvent,
                $referenceType,
                $referenceId,
                $dedupeSeconds
            )) {
                return false;
            }

            $this->create(
                $tenantId,
                $title,
                $message,
                $severity,
                $actionUrl,
                $type,
                $sourceEvent,
                $referenceType,
                $referenceId,
                $metadata
            );
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string,mixed> $metadata */
    public function create(
        int $tenantId,
        string $title,
        string $message,
        string $severity = 'info',
        ?string $actionUrl = null,
        string $type = 'system',
        ?string $sourceEvent = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        array $metadata = []
    ): void {
        if ($tenantId <= 0) {
            return;
        }

        if (!in_array($severity, ['info', 'success', 'warning', 'danger'], true)) {
            $severity = 'info';
        }

        Database::connection()->prepare(
            'INSERT INTO client_notifications
                (tenant_id, type, severity, title, message, action_url, source_event, reference_type, reference_id, metadata_json)
             VALUES
                (:tenant_id, :type, :severity, :title, :message, :action_url, :source_event, :reference_type, :reference_id, :metadata_json)'
        )->execute([
            'tenant_id' => $tenantId,
            'type' => mb_substr($type, 0, 80),
            'severity' => $severity,
            'title' => mb_substr($title, 0, 160),
            'message' => mb_substr($message, 0, 1200),
            'action_url' => $actionUrl ? mb_substr($actionUrl, 0, 500) : null,
            'source_event' => $sourceEvent ? mb_substr($sourceEvent, 0, 120) : null,
            'reference_type' => $referenceType ? mb_substr($referenceType, 0, 80) : null,
            'reference_id' => $referenceId && $referenceId > 0 ? $referenceId : null,
            'metadata_json' => $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ]);
    }

    /** @param array<string,mixed> $payload */
    public function createBillingNotification(array $payload, ?int $invoiceId = null): void
    {
        $tenantId = (int) ($payload['tenant_id'] ?? ($payload['tenant']['id'] ?? 0));
        $rule = is_array($payload['rule'] ?? null) ? $payload['rule'] : [];
        $invoice = is_array($payload['invoice'] ?? null) ? $payload['invoice'] : [];
        $days = (int) ($rule['days_from_due'] ?? 0);
        $event = (string) ($rule['event'] ?? 'billing.reminder');
        $invoiceNumber = (string) ($invoice['number'] ?? 'cobrança');

        $severity = 'info';
        if ($days > 0) {
            $severity = 'warning';
        }
        if ($event === 'billing.subscription.suspended') {
            $severity = 'danger';
        }

        $title = match ($event) {
            'billing.reminder.before_due' => 'Cobrança próxima do vencimento',
            'billing.reminder.due_today' => 'Cobrança vence hoje',
            'billing.reminder.overdue' => 'Cobrança em atraso',
            'billing.subscription.suspended' => 'Assinatura sinalizada para suspensão',
            default => 'Atualização financeira',
        };

        $message = (string) ($payload['message'] ?? 'Existe uma atualização financeira na sua assinatura.');
        $this->createIfEnabled(
            $tenantId,
            'billing',
            $title,
            $message,
            $severity,
            '/subscription',
            'billing',
            $event,
            'invoice',
            $invoiceId ?: (isset($invoice['id']) ? (int) $invoice['id'] : null),
            ['invoice_number' => $invoiceNumber, 'payload' => $payload]
        );
    }

    private function hasRecentUnreadDuplicate(
        int $tenantId,
        ?string $sourceEvent,
        ?string $referenceType,
        ?int $referenceId,
        int $seconds
    ): bool {
        if ($sourceEvent === null || trim($sourceEvent) === '') {
            return false;
        }

        $seconds = max(30, min(86400, $seconds));
        $sql = 'SELECT id FROM client_notifications
                WHERE tenant_id = :tenant_id
                  AND status = "unread"
                  AND source_event = :source_event
                  AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $seconds . ' SECOND)';
        $params = [
            'tenant_id' => $tenantId,
            'source_event' => $sourceEvent,
        ];

        if ($referenceType !== null && $referenceType !== '') {
            $sql .= ' AND reference_type = :reference_type';
            $params['reference_type'] = $referenceType;
        }
        if ($referenceId !== null && $referenceId > 0) {
            $sql .= ' AND reference_id = :reference_id';
            $params['reference_id'] = $referenceId;
        }

        $sql .= ' LIMIT 1';
        $statement = Database::connection()->prepare($sql);
        $statement->execute($params);
        return (bool) $statement->fetchColumn();
    }

    private function preferencesTableExists(): bool
    {
        if (self::$preferencesTableExists !== null) {
            return self::$preferencesTableExists;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE() AND table_name = "tenant_notification_preferences"'
            );
            $statement->execute();
            self::$preferencesTableExists = (int) $statement->fetchColumn() > 0;
        } catch (Throwable) {
            self::$preferencesTableExists = false;
        }

        return self::$preferencesTableExists;
    }
}
