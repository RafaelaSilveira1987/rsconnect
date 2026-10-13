<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class SubscriptionLifecycleReminderService
{
    public const TARGET_LABELS = [
        'current_period_end' => 'Fim da vigência atual',
        'trial_end' => 'Fim do período de teste',
        'trial_grace_end' => 'Fim do prazo de tolerância do teste',
    ];

    public const CHANNEL_LABELS = [
        'native_in_app' => 'RS Connect (interno)',
        'native_whatsapp' => 'WhatsApp nativo da plataforma',
        'native_both' => 'RS Connect + WhatsApp nativo',
        'n8n' => 'Integração externa (n8n)',
        'manual' => 'Somente registrar',
    ];

    /** @return array<int,array<string,mixed>> */
    public function rules(): array
    {
        if (!$this->tableExists('subscription_notification_rules')) {
            return [];
        }
        return Database::connection()
            ->query('SELECT * FROM subscription_notification_rules ORDER BY target_date, days_from_target ASC, id ASC')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int,array<string,mixed>> */
    public function logs(int $limit = 80): array
    {
        if (!$this->tableExists('subscription_notification_logs')) {
            return [];
        }
        $statement = Database::connection()->prepare(
            'SELECT l.*, r.label AS rule_label, r.target_date, t.name AS tenant_name,
                    s.billing_status, s.current_period_ends_at, s.trial_ends_at, sp.name AS plan_name
             FROM subscription_notification_logs l
             LEFT JOIN subscription_notification_rules r ON r.id = l.rule_id
             LEFT JOIN tenants t ON t.id = l.tenant_id
             LEFT JOIN tenant_subscriptions s ON s.id = l.subscription_id
             LEFT JOIN saas_plans sp ON sp.id = s.plan_id
             ORDER BY l.id DESC
             LIMIT :limit'
        );
        $statement->bindValue('limit', max(10, min(300, $limit)), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function saveRule(array $input): void
    {
        if (!$this->tableExists('subscription_notification_rules')) {
            throw new \RuntimeException('Execute a migration 125 para ativar os avisos de vigência e teste.');
        }

        $id = (int) ($input['id'] ?? 0);
        $label = trim((string) ($input['label'] ?? ''));
        $targetDate = (string) ($input['target_date'] ?? 'current_period_end');
        $days = (int) ($input['days_from_target'] ?? -7);
        $channel = (string) ($input['channel'] ?? 'native_in_app');
        $status = (string) ($input['status'] ?? 'active');
        $template = trim((string) ($input['message_template'] ?? ''));

        if ($label === '') {
            throw new \RuntimeException('Informe o nome da regra.');
        }
        if (!array_key_exists($targetDate, self::TARGET_LABELS)) {
            throw new \RuntimeException('Selecione uma data de referência válida.');
        }
        if (!array_key_exists($channel, self::CHANNEL_LABELS)) {
            throw new \RuntimeException('Selecione um canal válido.');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $status = 'active';
        }
        if ($days < -365 || $days > 365) {
            throw new \RuntimeException('O deslocamento deve ficar entre 365 dias antes e 365 dias depois.');
        }

        $pdo = Database::connection();
        if ($id > 0) {
            $statement = $pdo->prepare(
                'UPDATE subscription_notification_rules
                 SET label = :label, target_date = :target_date, days_from_target = :days,
                     channel = :channel, message_template = :message, status = :status
                 WHERE id = :id'
            );
            $statement->execute([
                'id' => $id,
                'label' => $label,
                'target_date' => $targetDate,
                'days' => $days,
                'channel' => $channel,
                'message' => $template !== '' ? $template : null,
                'status' => $status,
            ]);
            return;
        }

        $statement = $pdo->prepare(
            'INSERT INTO subscription_notification_rules
                (label, target_date, days_from_target, channel, message_template, status)
             VALUES (:label, :target_date, :days, :channel, :message, :status)'
        );
        $statement->execute([
            'label' => $label,
            'target_date' => $targetDate,
            'days' => $days,
            'channel' => $channel,
            'message' => $template !== '' ? $template : null,
            'status' => $status,
        ]);
    }

    /** @return array<string,mixed> */
    public function runDueReminders(string $origin = 'manual'): array
    {
        if (!$this->tableExists('subscription_notification_rules')) {
            return ['created' => 0, 'dispatched' => 0, 'ignored' => 0, 'errors' => []];
        }

        $rules = Database::connection()->query(
            'SELECT * FROM subscription_notification_rules WHERE status = "active" ORDER BY target_date, days_from_target, id'
        )->fetchAll(PDO::FETCH_ASSOC);

        $created = 0;
        $dispatched = 0;
        $ignored = 0;
        $errors = [];

        foreach ($rules as $rule) {
            foreach ($this->subscriptionsForRule($rule) as $subscription) {
                try {
                    if ($this->logExists((int) $rule['id'], (int) $subscription['id'], $origin === 'manual')) {
                        $ignored++;
                        continue;
                    }

                    $payload = $this->payload($rule, $subscription);
                    $logId = $this->createLog((int) $rule['id'], (int) $subscription['tenant_id'], (int) $subscription['id'], $payload);
                    $created++;
                    $channel = (string) ($rule['channel'] ?? 'native_in_app');

                    if (in_array($channel, PlatformNotificationService::NATIVE_CHANNELS, true)) {
                        $severity = $this->severity($rule);
                        $title = $this->title($rule);
                        $recipient = (string) (($subscription['tenant_commercial_whatsapp'] ?? '') ?: ($subscription['tenant_phone'] ?? ''));
                        $result = (new PlatformNotificationService())->dispatch(
                            (int) $subscription['tenant_id'],
                            $this->eventKey($rule),
                            'subscription',
                            (int) $subscription['id'],
                            $channel,
                            $recipient,
                            $title,
                            (string) $payload['message'],
                            '/subscription',
                            $severity,
                            $payload,
                            'subscription_notification_log',
                            $logId
                        );
                        if (!empty($result['errors'])) {
                            $this->finishLog($logId, 'error', $result);
                            foreach ((array) $result['errors'] as $error) {
                                $errors[] = (string) $error;
                            }
                        } elseif ((int) ($result['queued'] ?? 0) > 0) {
                            (new NotificationDeliveryService())->syncSourceStatus('subscription_notification_log', $logId);
                            $dispatched++;
                        } else {
                            $this->finishLog($logId, 'skipped', $result);
                        }
                    } elseif ($channel === 'n8n') {
                        $result = (new AutomationWebhookService())->dispatch(
                            $this->eventKey($rule),
                            $payload,
                            null,
                            (int) $subscription['tenant_id']
                        );
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

        $result = compact('created', 'dispatched', 'ignored', 'errors');
        $this->recordHeartbeat($result, $origin);
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    private function subscriptionsForRule(array $rule): array
    {
        $target = (string) $rule['target_date'];
        $days = (int) $rule['days_from_target'];
        $baseSelect =
            'SELECT s.*, t.name AS tenant_name, t.phone AS tenant_phone,
                    t.commercial_whatsapp AS tenant_commercial_whatsapp, t.email AS tenant_email,
                    sp.name AS plan_name,
                    DATE_ADD(s.trial_ends_at, INTERVAL COALESCE(s.trial_grace_days, 3) DAY) AS trial_grace_ends_at ' .
            'FROM tenant_subscriptions s ' .
            'INNER JOIN tenants t ON t.id = s.tenant_id ' .
            'LEFT JOIN saas_plans sp ON sp.id = s.plan_id ' .
            'WHERE s.billing_status IN ("trialing","active","overdue","suspended") ' .
            'AND NOT EXISTS (SELECT 1 FROM tenant_subscriptions newer WHERE newer.tenant_id = s.tenant_id '
                . 'AND newer.id > s.id AND newer.billing_status IN ("trialing","active","overdue","suspended")) ';

        if ($target === 'current_period_end') {
            $sql = $baseSelect . 'AND s.current_period_ends_at IS NOT NULL '
                . 'AND DATEDIFF(CURDATE(), s.current_period_ends_at) = :days ORDER BY s.id';
        } elseif ($target === 'trial_end') {
            $sql = $baseSelect . 'AND s.billing_status = "trialing" AND s.trial_ends_at IS NOT NULL '
                . 'AND DATEDIFF(CURDATE(), s.trial_ends_at) = :days ORDER BY s.id';
        } else {
            $sql = $baseSelect . 'AND s.billing_status = "trialing" AND s.trial_ends_at IS NOT NULL '
                . 'AND COALESCE(s.trial_end_behavior, "await_payment") = "await_payment" '
                . 'AND DATEDIFF(CURDATE(), DATE_ADD(s.trial_ends_at, INTERVAL COALESCE(s.trial_grace_days, 3) DAY)) = :days ORDER BY s.id';
        }

        $statement = Database::connection()->prepare($sql);
        $statement->execute(['days' => $days]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function payload(array $rule, array $subscription): array
    {
        $target = (string) $rule['target_date'];
        $message = trim((string) ($rule['message_template'] ?? ''));
        if ($message === '') {
            $message = match ($target) {
                'trial_end' => 'Olá, {{empresa}}. Seu período de teste termina em {{fim_teste}}.',
                'trial_grace_end' => 'Olá, {{empresa}}. Seu prazo de tolerância termina em {{fim_tolerancia}}.',
                default => 'Olá, {{empresa}}. A vigência atual do plano {{plano}} termina em {{fim_vigencia}}.',
            };
        }

        $graceDate = (string) ($subscription['trial_grace_ends_at'] ?? '');
        $replacements = [
            '{{empresa}}' => (string) ($subscription['tenant_name'] ?? ''),
            '{{plano}}' => (string) ($subscription['plan_name'] ?? ''),
            '{{fim_vigencia}}' => $this->formatDate((string) ($subscription['current_period_ends_at'] ?? '')),
            '{{fim_teste}}' => $this->formatDate((string) ($subscription['trial_ends_at'] ?? '')),
            '{{fim_tolerancia}}' => $this->formatDate($graceDate),
            '{{dias}}' => (string) abs((int) ($rule['days_from_target'] ?? 0)),
            '{{status}}' => (string) ($subscription['billing_status'] ?? ''),
            '{{valor}}' => 'R$ ' . number_format((float) ($subscription['amount'] ?? 0), 2, ',', '.'),
        ];
        $message = strtr($message, $replacements);

        return [
            'tenant_id' => (int) $subscription['tenant_id'],
            'tenant' => [
                'id' => (int) $subscription['tenant_id'],
                'name' => (string) ($subscription['tenant_name'] ?? ''),
                'phone' => (string) (($subscription['tenant_commercial_whatsapp'] ?? '') ?: ($subscription['tenant_phone'] ?? '')),
                'email' => (string) ($subscription['tenant_email'] ?? ''),
            ],
            'subscription' => [
                'id' => (int) $subscription['id'],
                'plan_name' => (string) ($subscription['plan_name'] ?? ''),
                'billing_status' => (string) ($subscription['billing_status'] ?? ''),
                'current_period_ends_at' => (string) ($subscription['current_period_ends_at'] ?? ''),
                'trial_ends_at' => (string) ($subscription['trial_ends_at'] ?? ''),
                'trial_grace_ends_at' => $graceDate,
            ],
            'rule' => [
                'id' => (int) $rule['id'],
                'label' => (string) $rule['label'],
                'target_date' => $target,
                'days_from_target' => (int) $rule['days_from_target'],
                'channel' => (string) $rule['channel'],
            ],
            'message' => $message,
            'sent_at' => date('c'),
        ];
    }

    private function eventKey(array $rule): string
    {
        return match ((string) ($rule['target_date'] ?? 'current_period_end')) {
            'trial_end' => 'subscription.trial.notice',
            'trial_grace_end' => 'subscription.trial_grace.notice',
            default => 'subscription.period.notice',
        };
    }

    private function title(array $rule): string
    {
        $days = (int) ($rule['days_from_target'] ?? 0);
        return match ((string) ($rule['target_date'] ?? 'current_period_end')) {
            'trial_end' => $days < 0 ? 'Período de teste próximo do fim' : ($days === 0 ? 'Período de teste termina hoje' : 'Período de teste encerrado'),
            'trial_grace_end' => $days < 0 ? 'Prazo de tolerância próximo do fim' : ($days === 0 ? 'Prazo de tolerância termina hoje' : 'Prazo de tolerância encerrado'),
            default => $days < 0 ? 'Vigência próxima do fim' : ($days === 0 ? 'Vigência termina hoje' : 'Vigência encerrada'),
        };
    }

    private function severity(array $rule): string
    {
        $days = (int) ($rule['days_from_target'] ?? 0);
        return $days > 0 ? 'warning' : 'info';
    }

    private function formatDate(string $value): string
    {
        $timestamp = strtotime($value);
        return $timestamp ? date('d/m/Y', $timestamp) : 'não informada';
    }

    private function logExists(int $ruleId, int $subscriptionId, bool $allowRetryErrors = false): bool
    {
        $statement = Database::connection()->prepare(
            'SELECT COUNT(*) FROM subscription_notification_logs
             WHERE rule_id = :rule_id AND subscription_id = :subscription_id AND DATE(created_at) = CURDATE()'
             . ($allowRetryErrors ? ' AND status NOT IN ("error","skipped")' : '')
        );
        $statement->execute(['rule_id' => $ruleId, 'subscription_id' => $subscriptionId]);
        return (int) $statement->fetchColumn() > 0;
    }

    /** @param array<string,mixed> $payload */
    private function createLog(int $ruleId, int $tenantId, int $subscriptionId, array $payload): int
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO subscription_notification_logs
                (rule_id, tenant_id, subscription_id, status, payload_json)
             VALUES (:rule_id, :tenant_id, :subscription_id, "pending", :payload)'
        );
        $statement->execute([
            'rule_id' => $ruleId,
            'tenant_id' => $tenantId,
            'subscription_id' => $subscriptionId,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int) Database::connection()->lastInsertId();
    }

    /** @param array<string,mixed> $result */
    private function finishLog(int $logId, string $status, array $result): void
    {
        Database::connection()->prepare(
            'UPDATE subscription_notification_logs
             SET status = :status, result_json = :result, processed_at = NOW()
             WHERE id = :id'
        )->execute([
            'id' => $logId,
            'status' => $status,
            'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /** @param array<string,mixed> $result */
    private function recordHeartbeat(array $result, string $origin): void
    {
        try {
            $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
            Database::connection()->prepare(
                'INSERT INTO system_health_checks (check_key, label, status, message, latency_ms, checked_at)
                 VALUES ("subscription_lifecycle_heartbeat", "Avisos de vigência e teste", :status, :message, NULL, NOW())'
            )->execute([
                'status' => $errors === [] ? 'ok' : 'warning',
                'message' => sprintf(
                    'Ciclo (%s) processado: %d aviso(s) criado(s), %d encaminhado(s), %d ignorado(s)%s',
                    $origin === 'cron' ? 'cron' : 'manual',
                    (int) ($result['created'] ?? 0),
                    (int) ($result['dispatched'] ?? 0),
                    (int) ($result['ignored'] ?? 0),
                    $errors !== [] ? '; ' . count($errors) . ' erro(s).' : '.'
                ),
            ]);
        } catch (Throwable) {
        }
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
}
