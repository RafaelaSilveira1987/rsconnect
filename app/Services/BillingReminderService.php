<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class BillingReminderService
{
    public const EVENT_LABELS = [
        'billing.reminder.before_due' => 'Aviso antes do vencimento',
        'billing.reminder.due_today' => 'Aviso no dia do vencimento',
        'billing.reminder.overdue' => 'Aviso de atraso',
        'billing.subscription.suspended' => 'Suspensão automática',
    ];

    public const CHANNEL_LABELS = [
        'n8n' => 'n8n / externo',
        'whatsapp' => 'WhatsApp via n8n',
        'email' => 'E-mail via n8n',
        'manual' => 'Somente registrar',
    ];

    public function rules(): array
    {
        return Database::connection()
            ->query('SELECT * FROM billing_reminder_rules ORDER BY days_from_due ASC, id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function logs(int $limit = 100): array
    {
        $statement = Database::connection()->prepare(
            'SELECT l.*, r.label AS rule_label, t.name AS tenant_name, i.invoice_number, i.amount, i.due_date
             FROM billing_reminder_logs l
             LEFT JOIN billing_reminder_rules r ON r.id = l.rule_id
             LEFT JOIN tenants t ON t.id = l.tenant_id
             LEFT JOIN tenant_invoices i ON i.id = l.invoice_id
             ORDER BY l.id DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', max(10, min(300, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function preview(): array
    {
        $statement = Database::connection()->query(
            'SELECT i.*, t.name AS tenant_name, t.email AS tenant_email, t.phone AS tenant_phone,
                    DATEDIFF(CURDATE(), i.due_date) AS days_from_due
             FROM tenant_invoices i
             INNER JOIN tenants t ON t.id = i.tenant_id
             WHERE i.status IN ("open", "overdue")
               AND i.due_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND DATE_ADD(CURDATE(), INTERVAL 15 DAY)
             ORDER BY i.due_date ASC, i.id DESC
             LIMIT 80'
        );
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveRule(array $input): void
    {
        $id = (int) ($input['id'] ?? 0);
        $label = trim((string) ($input['label'] ?? ''));
        $daysFromDue = (int) ($input['days_from_due'] ?? 0);
        $event = (string) ($input['event_key'] ?? 'billing.reminder.due_today');
        $channel = (string) ($input['channel'] ?? 'n8n');
        $status = (string) ($input['status'] ?? 'active');
        $autoMarkOverdue = isset($input['auto_mark_overdue']) ? 1 : 0;
        $autoSuspend = isset($input['auto_suspend']) ? 1 : 0;
        $template = trim((string) ($input['message_template'] ?? ''));

        if ($label === '') {
            throw new \RuntimeException('Informe o nome da regra.');
        }
        if (!array_key_exists($event, self::EVENT_LABELS)) {
            $event = 'billing.reminder.due_today';
        }
        if (!array_key_exists($channel, self::CHANNEL_LABELS)) {
            $channel = 'n8n';
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }

        $pdo = Database::connection();
        if ($id > 0) {
            $statement = $pdo->prepare(
                'UPDATE billing_reminder_rules
                 SET label = :label, days_from_due = :days_from_due, event_key = :event_key, channel = :channel,
                     auto_mark_overdue = :auto_mark_overdue, auto_suspend = :auto_suspend,
                     message_template = :message_template, status = :status
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'label' => $label,
                'days_from_due' => $daysFromDue,
                'event_key' => $event,
                'channel' => $channel,
                'auto_mark_overdue' => $autoMarkOverdue,
                'auto_suspend' => $autoSuspend,
                'message_template' => $template !== '' ? $template : null,
                'status' => $status,
            ]);
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO billing_reminder_rules
                (label, days_from_due, event_key, channel, auto_mark_overdue, auto_suspend, message_template, status)
             VALUES
                (:label, :days_from_due, :event_key, :channel, :auto_mark_overdue, :auto_suspend, :message_template, :status)'
        );
        $statement->execute([
            'label' => $label,
            'days_from_due' => $daysFromDue,
            'event_key' => $event,
            'channel' => $channel,
            'auto_mark_overdue' => $autoMarkOverdue,
            'auto_suspend' => $autoSuspend,
            'message_template' => $template !== '' ? $template : null,
            'status' => $status,
        ]);
    }

    public function runDueReminders(): array
    {
        $rules = Database::connection()
            ->query('SELECT * FROM billing_reminder_rules WHERE status = "active" ORDER BY days_from_due ASC, id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);

        $created = 0;
        $dispatched = 0;
        $ignored = 0;
        $errors = [];

        foreach ($rules as $rule) {
            $invoices = $this->invoicesForRule($rule);
            foreach ($invoices as $invoice) {
                try {
                    if ($this->logExists((int) $rule['id'], (int) $invoice['id'])) {
                        $ignored++;
                        continue;
                    }

                    if ((int) ($rule['auto_mark_overdue'] ?? 0) === 1 && (int) $invoice['days_from_due'] > 0 && $invoice['status'] === 'open') {
                        $this->markInvoiceOverdue((int) $invoice['id']);
                        $invoice['status'] = 'overdue';
                    }

                    if ((int) ($rule['auto_suspend'] ?? 0) === 1 && (int) $invoice['days_from_due'] > 0) {
                        $this->suspendSubscription((int) $invoice['tenant_id']);
                    }

                    $payload = $this->payloadForInvoice($rule, $invoice);
                    $logId = $this->createLog((int) $rule['id'], (int) $invoice['tenant_id'], (int) $invoice['id'], 'pending', $payload);
                    (new NotificationService())->createBillingNotification($payload, (int) $invoice['id']);
                    $created++;

                    if (($rule['channel'] ?? 'n8n') !== 'manual') {
                        $result = (new AutomationWebhookService())->dispatch((string) $rule['event_key'], $payload, null, (int) $invoice['tenant_id']);
                        $success = false;
                        foreach ($result as $item) {
                            if (!empty($item['ok'])) {
                                $success = true;
                                break;
                            }
                        }
                        $this->finishLog($logId, $success ? 'sent' : 'error', $result);
                        $dispatched += $success ? 1 : 0;
                    } else {
                        $this->finishLog($logId, 'logged', ['manual' => true]);
                    }
                } catch (Throwable $exception) {
                    $errors[] = $exception->getMessage();
                }
            }
        }

        return [
            'created' => $created,
            'dispatched' => $dispatched,
            'ignored' => $ignored,
            'errors' => $errors,
        ];
    }

    private function invoicesForRule(array $rule): array
    {
        $statement = Database::connection()->prepare(
            'SELECT i.*, t.name AS tenant_name, t.legal_name AS tenant_legal_name, t.email AS tenant_email, t.phone AS tenant_phone,
                    t.document AS tenant_document, ts.id AS subscription_id, ts.billing_status,
                    sp.name AS plan_name, DATEDIFF(CURDATE(), i.due_date) AS days_from_due
             FROM tenant_invoices i
             INNER JOIN tenants t ON t.id = i.tenant_id
             LEFT JOIN tenant_subscriptions ts ON ts.id = i.subscription_id
             LEFT JOIN saas_plans sp ON sp.id = ts.plan_id
             WHERE i.status IN ("open", "overdue")
               AND DATEDIFF(CURDATE(), i.due_date) = :days_from_due
             ORDER BY i.id ASC'
        );
        $statement->execute(['days_from_due' => (int) $rule['days_from_due']]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function payloadForInvoice(array $rule, array $invoice): array
    {
        $message = (string) ($rule['message_template'] ?? '');
        if ($message === '') {
            $message = 'Olá, {{empresa}}. Identificamos a cobrança {{invoice_number}} no valor de {{valor}}, com vencimento em {{vencimento}}.';
        }

        $replacements = [
            '{{empresa}}' => (string) ($invoice['tenant_name'] ?? ''),
            '{{invoice_number}}' => (string) ($invoice['invoice_number'] ?? ''),
            '{{valor}}' => 'R$ ' . number_format((float) ($invoice['amount'] ?? 0), 2, ',', '.'),
            '{{vencimento}}' => date('d/m/Y', strtotime((string) ($invoice['due_date'] ?? 'now'))),
            '{{link_pagamento}}' => (string) (($invoice['external_checkout_url'] ?? '') ?: ($invoice['external_invoice_url'] ?? '')),
            '{{dias}}' => (string) abs((int) ($invoice['days_from_due'] ?? 0)),
        ];
        $message = strtr($message, $replacements);

        return [
            'tenant_id' => (int) $invoice['tenant_id'],
            'tenant' => [
                'id' => (int) $invoice['tenant_id'],
                'name' => (string) ($invoice['tenant_name'] ?? ''),
                'email' => (string) ($invoice['tenant_email'] ?? ''),
                'phone' => (string) ($invoice['tenant_phone'] ?? ''),
            ],
            'invoice' => [
                'id' => (int) $invoice['id'],
                'number' => (string) $invoice['invoice_number'],
                'amount' => (float) $invoice['amount'],
                'due_date' => (string) $invoice['due_date'],
                'status' => (string) $invoice['status'],
                'payment_url' => (string) (($invoice['external_checkout_url'] ?? '') ?: ($invoice['external_invoice_url'] ?? '')),
            ],
            'subscription' => [
                'id' => (int) ($invoice['subscription_id'] ?? 0),
                'plan_name' => (string) ($invoice['plan_name'] ?? ''),
                'billing_status' => (string) ($invoice['billing_status'] ?? ''),
            ],
            'rule' => [
                'id' => (int) $rule['id'],
                'label' => (string) $rule['label'],
                'days_from_due' => (int) $rule['days_from_due'],
                'event' => (string) $rule['event_key'],
                'channel' => (string) $rule['channel'],
            ],
            'message' => $message,
            'sent_at' => date('c'),
        ];
    }

    private function logExists(int $ruleId, int $invoiceId): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM billing_reminder_logs
             WHERE rule_id = :rule_id AND invoice_id = :invoice_id AND DATE(created_at) = CURDATE()'
        );
        $statement->execute(['rule_id' => $ruleId, 'invoice_id' => $invoiceId]);
        return (int) $statement->fetchColumn() > 0;
    }

    private function createLog(int $ruleId, int $tenantId, int $invoiceId, string $status, array $payload): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO billing_reminder_logs
                (rule_id, tenant_id, invoice_id, status, payload_json)
             VALUES
                (:rule_id, :tenant_id, :invoice_id, :status, :payload)'
        );
        $statement->execute([
            'rule_id' => $ruleId,
            'tenant_id' => $tenantId,
            'invoice_id' => $invoiceId,
            'status' => $status,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    private function finishLog(int $logId, string $status, array $result): void
    {
        Database::connection()->prepare(
            'UPDATE billing_reminder_logs
             SET status = :status, result_json = :result, processed_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $logId,
            'status' => $status,
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function markInvoiceOverdue(int $invoiceId): void
    {
        Database::connection()->prepare('UPDATE tenant_invoices SET status = "overdue" WHERE id = :id AND status = "open"')
            ->execute(['id' => $invoiceId]);
    }

    private function suspendSubscription(int $tenantId): void
    {
        Database::connection()->prepare(
            'UPDATE tenant_subscriptions
             SET billing_status = "suspended"
             WHERE tenant_id = :tenant_id
             ORDER BY id DESC
             LIMIT 1'
        )->execute(['tenant_id' => $tenantId]);
    }
}
