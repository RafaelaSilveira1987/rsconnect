<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class NotificationOrchestratorService
{
    private const DEFINITIONS = [
        'calendar.appointment.created' => [
            'input_key' => 'calendar_appointment_created',
            'group' => 'agenda',
            'label' => 'Novo agendamento',
            'description' => 'Avisa quando um compromisso ou pré-agendamento é criado.',
        ],
        'calendar.appointment.confirmed' => [
            'input_key' => 'calendar_appointment_confirmed',
            'group' => 'agenda',
            'label' => 'Agendamento confirmado',
            'description' => 'Avisa quando a equipe confirma um compromisso.',
        ],
        'calendar.appointment.cancelled' => [
            'input_key' => 'calendar_appointment_cancelled',
            'group' => 'agenda',
            'label' => 'Agendamento cancelado',
            'description' => 'Avisa quando um compromisso é cancelado ou recusado.',
        ],
        'calendar.appointment.rescheduled' => [
            'input_key' => 'calendar_appointment_rescheduled',
            'group' => 'agenda',
            'label' => 'Pedido de remarcação',
            'description' => 'Avisa quando o cliente ou a equipe solicita uma nova data.',
        ],
        'calendar.appointment.reminder' => [
            'input_key' => 'calendar_appointment_reminder',
            'group' => 'agenda',
            'label' => 'Lembrete de agendamento',
            'description' => 'Avisa a equipe antes do início do compromisso.',
            'supports_reminder' => true,
        ],
        'commercial.quote.requested' => [
            'input_key' => 'commercial_quote_requested',
            'group' => 'comercial',
            'label' => 'Novo pedido de orçamento',
            'description' => 'Avisa quando a conversa confirma interesse em orçamento ou proposta.',
        ],
        'commercial.quote.overdue' => [
            'input_key' => 'commercial_quote_overdue',
            'group' => 'comercial',
            'label' => 'Orçamento atrasado',
            'description' => 'Escala o aviso quando a solicitação continua pendente após o prazo.',
            'supports_escalation' => true,
        ],
    ];

    /** @return array<string,array<string,mixed>> */
    public function definitions(): array
    {
        return self::DEFINITIONS;
    }

    /** @return array<string,array<string,mixed>> */
    public function rules(int $tenantId): array
    {
        $rules = [];
        foreach (self::DEFINITIONS as $eventKey => $definition) {
            $rules[$eventKey] = array_merge($definition, [
                'event_key' => $eventKey,
                'ready' => false,
                'enabled' => 1,
                'in_app_enabled' => 1,
                'whatsapp_enabled' => 0,
                'recipient_phone' => '',
                'reminder_minutes' => $eventKey === 'calendar.appointment.reminder' ? 120 : null,
                'escalation_minutes' => $eventKey === 'commercial.quote.overdue' ? 30 : null,
            ]);
        }

        if ($tenantId < 1 || !$this->hasTables()) {
            return $rules;
        }
        foreach ($rules as $eventKey => $rule) {
            $rules[$eventKey]['ready'] = true;
        }

        try {
            $statement = Database::connection()->prepare(
                'SELECT * FROM tenant_notification_rules WHERE tenant_id = :tenant_id ORDER BY id'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $eventKey = (string) ($row['event_key'] ?? '');
                if (!isset($rules[$eventKey])) {
                    continue;
                }
                $rules[$eventKey] = array_merge($rules[$eventKey], $row, ['ready' => true]);
            }
        } catch (Throwable) {
            return $rules;
        }

        return $rules;
    }

    /** @param array<string,mixed> $data */
    public function saveRules(int $tenantId, array $data, ?int $userId): void
    {
        if ($tenantId < 1) {
            throw new RuntimeException('Empresa inválida.');
        }
        if (!$this->hasTables()) {
            throw new RuntimeException('Execute a migration 092 para ativar as notificações automáticas.');
        }

        $posted = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare(
                'INSERT INTO tenant_notification_rules
                    (tenant_id, event_key, enabled, in_app_enabled, whatsapp_enabled, recipient_phone,
                     reminder_minutes, escalation_minutes, updated_by_user_id)
                 VALUES
                    (:tenant_id, :event_key, :enabled, :in_app_enabled, :whatsapp_enabled, :recipient_phone,
                     :reminder_minutes, :escalation_minutes, :updated_by_user_id)
                 ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    in_app_enabled = VALUES(in_app_enabled),
                    whatsapp_enabled = VALUES(whatsapp_enabled),
                    recipient_phone = VALUES(recipient_phone),
                    reminder_minutes = VALUES(reminder_minutes),
                    escalation_minutes = VALUES(escalation_minutes),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    updated_at = CURRENT_TIMESTAMP'
            );

            foreach (self::DEFINITIONS as $eventKey => $definition) {
                $inputKey = (string) $definition['input_key'];
                $row = is_array($posted[$inputKey] ?? null) ? $posted[$inputKey] : [];
                $phone = preg_replace('/\D+/', '', (string) ($row['recipient_phone'] ?? '')) ?: '';
                if ($phone !== '' && strlen($phone) < 10) {
                    throw new RuntimeException('Informe um WhatsApp válido para ' . $definition['label'] . '.');
                }

                $reminder = !empty($definition['supports_reminder'])
                    ? max(5, min(10080, (int) ($row['reminder_minutes'] ?? 120)))
                    : null;
                $escalation = !empty($definition['supports_escalation'])
                    ? max(5, min(10080, (int) ($row['escalation_minutes'] ?? 30)))
                    : null;

                $statement->execute([
                    'tenant_id' => $tenantId,
                    'event_key' => $eventKey,
                    'enabled' => !empty($row['enabled']) ? 1 : 0,
                    'in_app_enabled' => !empty($row['in_app_enabled']) ? 1 : 0,
                    'whatsapp_enabled' => !empty($row['whatsapp_enabled']) ? 1 : 0,
                    'recipient_phone' => $phone !== '' ? $phone : null,
                    'reminder_minutes' => $reminder,
                    'escalation_minutes' => $escalation,
                    'updated_by_user_id' => $userId && $userId > 0 ? $userId : null,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /** @param array<string,mixed> $context */
    public function dispatch(
        int $tenantId,
        string $eventKey,
        string $entityType,
        int $entityId,
        array $context = [],
        ?string $scheduledAt = null,
        string $dedupeSuffix = ''
    ): array {
        if ($tenantId < 1 || $entityId < 1 || !isset(self::DEFINITIONS[$eventKey])) {
            return ['queued' => 0, 'reason' => 'invalid_context'];
        }

        try {
            $rule = $this->rule($tenantId, $eventKey);
            if (empty($rule['enabled'])) {
                return ['queued' => 0, 'reason' => 'disabled'];
            }

            $rendered = $this->render($eventKey, $context);
            $scheduledAt = $this->normalizeDate($scheduledAt ?: Clock::nowUtc());
            $queued = 0;

            if (!empty($rule['in_app_enabled'])) {
            $queued += $this->enqueue(
                $tenantId,
                $eventKey,
                $entityType,
                $entityId,
                'in_app',
                null,
                $rendered,
                $context,
                $scheduledAt,
                $dedupeSuffix
            );
        }

            if (!empty($rule['whatsapp_enabled'])) {
                $recipient = $this->resolveRecipient($tenantId, (string) ($rule['recipient_phone'] ?? ''));
                if ($recipient !== '') {
                    $queued += $this->enqueue(
                        $tenantId,
                        $eventKey,
                        $entityType,
                        $entityId,
                        'whatsapp',
                        $recipient,
                        $rendered,
                        $context,
                        $scheduledAt,
                        $dedupeSuffix
                    );
                }
            }

            return ['queued' => $queued, 'reason' => $queued > 0 ? 'queued' : 'no_channel'];
        } catch (Throwable $exception) {
            return ['queued' => 0, 'reason' => 'error', 'error' => mb_substr($exception->getMessage(), 0, 500)];
        }
    }

    /** @param array<string,mixed> $context */
    public function scheduleAppointmentReminder(int $tenantId, int $appointmentId, string $startsAt, array $context): array
    {
        try {
            $rule = $this->rule($tenantId, 'calendar.appointment.reminder');
            if (empty($rule['enabled'])) {
                return ['queued' => 0, 'reason' => 'disabled'];
            }
            $minutes = max(5, (int) ($rule['reminder_minutes'] ?? 120));
            $start = new DateTimeImmutable($startsAt, new DateTimeZone('UTC'));
            $scheduled = $start->sub(new DateInterval('PT' . $minutes . 'M'));
            if ($scheduled <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
                return ['queued' => 0, 'reason' => 'past_due'];
            }
            return $this->dispatch(
                $tenantId,
                'calendar.appointment.reminder',
                'appointment',
                $appointmentId,
                $context,
                $scheduled->format('Y-m-d H:i:s'),
                'reminder-' . $minutes . '-' . $start->format('YmdHis')
            );
        } catch (Throwable) {
            return ['queued' => 0, 'reason' => 'invalid_date'];
        }
    }

    /** @param array<string,mixed> $context */
    public function scheduleQuoteOverdue(int $tenantId, int $requestId, string $dueAt, array $context): array
    {
        try {
            $rule = $this->rule($tenantId, 'commercial.quote.overdue');
            if (empty($rule['enabled'])) {
                return ['queued' => 0, 'reason' => 'disabled'];
            }
            $minutes = max(5, (int) ($rule['escalation_minutes'] ?? 30));
            $due = new DateTimeImmutable($dueAt, new DateTimeZone('UTC'));
            $scheduled = $due->add(new DateInterval('PT' . $minutes . 'M'));
            return $this->dispatch(
                $tenantId,
                'commercial.quote.overdue',
                'crm_commercial_request',
                $requestId,
                $context,
                $scheduled->format('Y-m-d H:i:s'),
                'overdue-' . $minutes
            );
        } catch (Throwable) {
            return ['queued' => 0, 'reason' => 'invalid_date'];
        }
    }

    /** @return array<string,int> */
    public function deliveryStats(int $tenantId): array
    {
        $defaults = ['pending' => 0, 'retry' => 0, 'sent' => 0, 'failed' => 0];
        if ($tenantId < 1 || !$this->hasTables()) {
            return $defaults;
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT status, COUNT(*) total FROM notification_jobs
                 WHERE tenant_id = :tenant_id AND created_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
                 GROUP BY status'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $status = (string) ($row['status'] ?? '');
                if (array_key_exists($status, $defaults)) {
                    $defaults[$status] = (int) ($row['total'] ?? 0);
                }
            }
        } catch (Throwable) {
            return $defaults;
        }
        return $defaults;
    }

    /** @return array<string,mixed> */
    private function rule(int $tenantId, string $eventKey): array
    {
        $rules = $this->rules($tenantId);
        return $rules[$eventKey] ?? [];
    }

    /** @param array<string,mixed> $rendered @param array<string,mixed> $context */
    private function enqueue(
        int $tenantId,
        string $eventKey,
        string $entityType,
        int $entityId,
        string $channel,
        ?string $recipient,
        array $rendered,
        array $context,
        string $scheduledAt,
        string $dedupeSuffix
    ): int {
        if (!$this->hasTables()) {
            if ($channel === 'in_app' && $scheduledAt <= Clock::nowUtc()) {
                (new NotificationService())->createIfEnabled(
                    $tenantId,
                    str_starts_with($eventKey, 'calendar.') ? 'calendar' : 'system',
                    (string) $rendered['title'],
                    (string) $rendered['message'],
                    (string) $rendered['severity'],
                    (string) $rendered['action_url'],
                    (string) $rendered['type'],
                    $eventKey,
                    $entityType,
                    $entityId,
                    $context,
                    60
                );
                return 1;
            }
            return 0;
        }

        $dedupe = hash('sha256', implode('|', [
            $tenantId,
            $eventKey,
            $entityType,
            $entityId,
            $channel,
            (string) $recipient,
            $dedupeSuffix,
        ]));

        if ($channel === 'in_app' && $scheduledAt <= Clock::nowUtc()) {
            $insert = Database::connection()->prepare(
                'INSERT IGNORE INTO notification_jobs
                    (tenant_id, event_key, entity_type, entity_id, channel, recipient, title, message,
                     action_url, severity, payload_json, status, attempts, max_attempts,
                     scheduled_at, next_attempt_at, locked_at, deduplication_key)
                 VALUES
                    (:tenant_id, :event_key, :entity_type, :entity_id, "in_app", NULL, :title, :message,
                     :action_url, :severity, :payload_json, "processing", 0, 4,
                     :scheduled_at, :next_attempt_at, UTC_TIMESTAMP(), :deduplication_key)'
            );
            $insert->execute([
                'tenant_id' => $tenantId,
                'event_key' => $eventKey,
                'entity_type' => mb_substr($entityType, 0, 80),
                'entity_id' => $entityId,
                'title' => mb_substr((string) $rendered['title'], 0, 180),
                'message' => mb_substr((string) $rendered['message'], 0, 3000),
                'action_url' => mb_substr((string) $rendered['action_url'], 0, 500),
                'severity' => (string) $rendered['severity'],
                'payload_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'scheduled_at' => $scheduledAt,
                'next_attempt_at' => $scheduledAt,
                'deduplication_key' => $dedupe,
            ]);
            if ($insert->rowCount() < 1) {
                return 0;
            }
            $created = (new NotificationService())->createIfEnabled(
                $tenantId,
                str_starts_with($eventKey, 'calendar.') ? 'calendar' : 'system',
                (string) $rendered['title'],
                (string) $rendered['message'],
                (string) $rendered['severity'],
                (string) $rendered['action_url'],
                (string) $rendered['type'],
                $eventKey,
                $entityType,
                $entityId,
                $context,
                60
            );
            Database::connection()->prepare(
                'UPDATE notification_jobs
                 SET status = :status, attempts = 1, locked_at = NULL,
                     sent_at = CASE WHEN :sent_flag = 1 THEN UTC_TIMESTAMP() ELSE NULL END
                 WHERE deduplication_key = :deduplication_key'
            )->execute([
                'status' => $created ? 'sent' : 'skipped',
                'sent_flag' => $created ? 1 : 0,
                'deduplication_key' => $dedupe,
            ]);
            return 1;
        }

        $statement = Database::connection()->prepare(
            'INSERT IGNORE INTO notification_jobs
                (tenant_id, event_key, entity_type, entity_id, channel, recipient, title, message,
                 action_url, severity, payload_json, status, attempts, max_attempts,
                 scheduled_at, next_attempt_at, deduplication_key)
             VALUES
                (:tenant_id, :event_key, :entity_type, :entity_id, :channel, :recipient, :title, :message,
                 :action_url, :severity, :payload_json, "pending", 0, 4,
                 :scheduled_at, :next_attempt_at, :deduplication_key)'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'event_key' => $eventKey,
            'entity_type' => mb_substr($entityType, 0, 80),
            'entity_id' => $entityId,
            'channel' => $channel,
            'recipient' => $recipient !== null ? mb_substr($recipient, 0, 190) : null,
            'title' => mb_substr((string) $rendered['title'], 0, 180),
            'message' => mb_substr((string) $rendered['message'], 0, 3000),
            'action_url' => mb_substr((string) $rendered['action_url'], 0, 500),
            'severity' => (string) $rendered['severity'],
            'payload_json' => json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scheduled_at' => $scheduledAt,
            'next_attempt_at' => $scheduledAt,
            'deduplication_key' => $dedupe,
        ]);
        return $statement->rowCount() > 0 ? 1 : 0;
    }

    /** @param array<string,mixed> $context @return array<string,string> */
    private function render(string $eventKey, array $context): array
    {
        $customer = trim((string) ($context['customer_name'] ?? $context['contact_name'] ?? 'Cliente')) ?: 'Cliente';
        $appointment = trim((string) ($context['appointment_title'] ?? $context['title'] ?? 'Agendamento')) ?: 'Agendamento';
        $startsAt = trim((string) ($context['starts_at'] ?? ''));
        $when = $startsAt !== '' ? Clock::formatUtc($startsAt, 'd/m/Y H:i') : 'data a confirmar';
        $dueAt = trim((string) ($context['due_at'] ?? ''));
        $due = $dueAt !== '' ? Clock::formatUtc($dueAt, 'd/m/Y H:i') : 'sem prazo definido';
        $conversationId = (int) ($context['conversation_id'] ?? 0);
        $action = $conversationId > 0 ? '/conversations?conversation_id=' . $conversationId : '/calendar';

        return match ($eventKey) {
            'calendar.appointment.created' => [
                'title' => 'Novo agendamento',
                'message' => $customer . ' — ' . $appointment . ' em ' . $when . '.',
                'severity' => 'info', 'action_url' => '/calendar', 'type' => 'calendar',
            ],
            'calendar.appointment.confirmed' => [
                'title' => 'Agendamento confirmado',
                'message' => $customer . ' está confirmado para ' . $when . '.',
                'severity' => 'success', 'action_url' => '/calendar', 'type' => 'calendar',
            ],
            'calendar.appointment.cancelled' => [
                'title' => 'Agendamento cancelado',
                'message' => $appointment . ' de ' . $customer . ' foi cancelado ou recusado.',
                'severity' => 'warning', 'action_url' => '/calendar', 'type' => 'calendar',
            ],
            'calendar.appointment.rescheduled' => [
                'title' => 'Remarcação pendente',
                'message' => $customer . ' precisa de uma nova data para ' . $appointment . '.',
                'severity' => 'warning', 'action_url' => '/calendar', 'type' => 'calendar',
            ],
            'calendar.appointment.reminder' => [
                'title' => 'Lembrete de agendamento',
                'message' => $appointment . ' com ' . $customer . ' começa em ' . $when . '.',
                'severity' => 'info', 'action_url' => '/calendar', 'type' => 'calendar',
            ],
            'commercial.quote.requested' => [
                'title' => 'Novo pedido de orçamento',
                'message' => $customer . ' solicitou orçamento. Prazo de retorno: ' . $due . '.',
                'severity' => 'warning', 'action_url' => $action, 'type' => 'commercial_request',
            ],
            'commercial.quote.overdue' => [
                'title' => 'Orçamento atrasado',
                'message' => 'A solicitação de ' . $customer . ' continua pendente desde ' . $due . '.',
                'severity' => 'danger', 'action_url' => $action, 'type' => 'commercial_request',
            ],
            default => [
                'title' => 'Atualização importante', 'message' => 'Existe uma atualização que precisa de atenção.',
                'severity' => 'info', 'action_url' => '/', 'type' => 'system',
            ],
        };
    }

    private function resolveRecipient(int $tenantId, string $configured): string
    {
        $configured = preg_replace('/\D+/', '', $configured) ?: '';
        if ($configured !== '') {
            return $configured;
        }
        try {
            $pdo = Database::connection();
            $statement = $pdo->prepare(
                'SELECT commercial_whatsapp, phone FROM tenants WHERE id = :id LIMIT 1'
            );
            $statement->execute(['id' => $tenantId]);
            $tenant = $statement->fetch(PDO::FETCH_ASSOC) ?: [];

            $instanceStatement = $pdo->prepare(
                'SELECT profile_phone FROM evolution_instances
                 WHERE tenant_id = :tenant_id AND status = "connected"'
            );
            $instanceStatement->execute(['tenant_id' => $tenantId]);
            $ownNumbers = [];
            foreach ($instanceStatement->fetchAll(PDO::FETCH_COLUMN) as $phone) {
                $normalized = preg_replace('/\D+/', '', (string) $phone) ?: '';
                if ($normalized !== '') {
                    $ownNumbers[$normalized] = true;
                }
            }

            foreach ([(string) ($tenant['commercial_whatsapp'] ?? ''), (string) ($tenant['phone'] ?? '')] as $candidate) {
                $normalized = preg_replace('/\D+/', '', $candidate) ?: '';
                if ($normalized !== '' && !isset($ownNumbers[$normalized])) {
                    return $normalized;
                }
            }
            return '';
        } catch (Throwable) {
            return '';
        }
    }

    private function normalizeDate(string $value): string
    {
        try {
            return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return Clock::nowUtc();
        }
    }

    private function hasTables(): bool
    {
        try {
            $statement = Database::connection()->query(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name IN ("tenant_notification_rules", "notification_jobs")'
            );
            return (int) $statement->fetchColumn() === 2;
        } catch (Throwable) {
            return false;
        }
    }
}
