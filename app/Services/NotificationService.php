<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class NotificationService
{
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

    public function markAllRead(int $tenantId): void
    {
        Database::connection()->prepare(
            'UPDATE client_notifications
             SET status = "read", read_at = COALESCE(read_at, NOW())
             WHERE tenant_id = :tenant_id AND status = "unread"'
        )->execute(['tenant_id' => $tenantId]);
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


    public function markConversationRead(int $tenantId, int $conversationId): void
    {
        if ($tenantId <= 0 || $conversationId <= 0) {
            return;
        }

        try {
            Database::connection()->prepare(
                'UPDATE client_notifications
                 SET status = "read", read_at = COALESCE(read_at, NOW())
                 WHERE tenant_id = :tenant_id
                   AND status = "unread"
                   AND reference_type = "conversation"
                   AND reference_id = :conversation_id'
            )->execute([
                'tenant_id' => $tenantId,
                'conversation_id' => $conversationId,
            ]);
        } catch (Throwable) {
            // Mantém a leitura de conversas funcionando mesmo se a tabela de notificações ainda não existir.
        }
    }

    public function createMessageNotification(
        int $tenantId,
        int $conversationId,
        string $contactName,
        string $phone,
        string $messagePreview
    ): void {
        if ($tenantId <= 0 || $conversationId <= 0) {
            return;
        }

        $label = trim($contactName) !== '' ? trim($contactName) : trim($phone);
        if ($label === '') {
            $label = 'Novo contato';
        }

        $preview = trim(preg_replace('/\s+/', ' ', $messagePreview) ?? '');
        if ($preview === '') {
            $preview = 'Nova mensagem recebida pelo WhatsApp.';
        }

        $this->create(
            $tenantId,
            'Nova mensagem recebida',
            $label . ': ' . mb_substr($preview, 0, 180),
            'info',
            '/conversations?conversation_id=' . $conversationId,
            'message',
            'message.received',
            'conversation',
            $conversationId,
            [
                'contact_name' => $label,
                'phone' => $phone,
                'preview' => mb_substr($preview, 0, 240),
            ]
        );
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
        $this->create(
            $tenantId,
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
}
