<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Governança financeira da IA custeada pela RS Connect.
 *
 * O orçamento é um segundo guardrail além da franquia comercial por interações.
 * Ele considera custo técnico estimado das chamadas realmente feitas com credencial RS.
 * Regras locais, cache, atendimento humano e credenciais próprias do cliente continuam livres.
 */
final class AiBudgetPolicyService
{
    public const WARNING_ACTIONS = ['none', 'economy'];
    public const HARD_ACTIONS = ['notify_only', 'economy', 'block_rs_ai'];

    /** @return array<string,mixed> */
    public function policy(int $tenantId): array
    {
        $defaults = [
            'tenant_id' => $tenantId,
            'enabled' => 0,
            'monthly_budget_usd' => null,
            'warning_percent' => 80,
            'critical_percent' => 95,
            'hard_limit_percent' => 100,
            'warning_action' => 'none',
            'hard_limit_action' => 'notify_only',
        ];
        if ($tenantId < 1) {
            return $defaults;
        }

        try {
            $statement = Database::connection()->prepare('SELECT * FROM tenant_ai_budget_policies WHERE tenant_id = :tenant_id LIMIT 1');
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return array_merge($defaults, $row);
        } catch (Throwable) {
            return $defaults;
        }
    }

    /** @param array<string,mixed> $data */
    public function save(int $tenantId, array $data, ?int $userId): void
    {
        if ($tenantId < 1) {
            throw new RuntimeException('Empresa inválida para configurar o orçamento de IA.');
        }
        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
        $check->execute(['id' => $tenantId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Empresa não encontrada.');
        }

        $enabled = !empty($data['enabled']) ? 1 : 0;
        $budgetRaw = str_replace(',', '.', trim((string) ($data['monthly_budget_usd'] ?? '')));
        $budget = $budgetRaw === '' ? null : round(max(0.0, (float) $budgetRaw), 4);
        if ($enabled && ($budget === null || $budget <= 0)) {
            throw new RuntimeException('Informe um orçamento em dólar maior que zero.');
        }

        $warning = max(10, min(99, (int) ($data['warning_percent'] ?? 80)));
        $critical = max($warning + 1, min(100, (int) ($data['critical_percent'] ?? 95)));
        $hard = max($critical, min(150, (int) ($data['hard_limit_percent'] ?? 100)));
        $warningAction = strtolower(trim((string) ($data['warning_action'] ?? 'none')));
        $hardAction = strtolower(trim((string) ($data['hard_limit_action'] ?? 'notify_only')));
        if (!in_array($warningAction, self::WARNING_ACTIONS, true)) {
            $warningAction = 'none';
        }
        if (!in_array($hardAction, self::HARD_ACTIONS, true)) {
            $hardAction = 'notify_only';
        }

        $pdo->prepare(
            'INSERT INTO tenant_ai_budget_policies
                (tenant_id, enabled, monthly_budget_usd, warning_percent, critical_percent, hard_limit_percent,
                 warning_action, hard_limit_action, updated_by_user_id)
             VALUES
                (:tenant_id, :enabled, :budget, :warning_percent, :critical_percent, :hard_limit_percent,
                 :warning_action, :hard_limit_action, :updated_by)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled), monthly_budget_usd = VALUES(monthly_budget_usd),
                warning_percent = VALUES(warning_percent), critical_percent = VALUES(critical_percent),
                hard_limit_percent = VALUES(hard_limit_percent), warning_action = VALUES(warning_action),
                hard_limit_action = VALUES(hard_limit_action), updated_by_user_id = VALUES(updated_by_user_id)'
        )->execute([
            'tenant_id' => $tenantId,
            'enabled' => $enabled,
            'budget' => $budget,
            'warning_percent' => $warning,
            'critical_percent' => $critical,
            'hard_limit_percent' => $hard,
            'warning_action' => $warningAction,
            'hard_limit_action' => $hardAction,
            'updated_by' => $userId && $userId > 0 ? $userId : null,
        ]);
    }

    /** @return array<string,mixed> */
    public function decision(int $tenantId): array
    {
        $policy = $this->policy($tenantId);
        $period = (new SubscriptionService())->usagePeriodForTenant($tenantId);
        $usage = $this->costUsage($tenantId, $period);
        $enabled = (int) ($policy['enabled'] ?? 0) === 1;
        $budget = $policy['monthly_budget_usd'] !== null ? max(0.0, (float) $policy['monthly_budget_usd']) : 0.0;
        $used = max(0.0, (float) ($usage['cost_usd'] ?? 0));
        $rate = $budget > 0 ? $used / $budget : 0.0;
        $percent = $rate * 100;
        $warning = (int) ($policy['warning_percent'] ?? 80);
        $critical = (int) ($policy['critical_percent'] ?? 95);
        $hard = (int) ($policy['hard_limit_percent'] ?? 100);

        $forceEconomy = false;
        $blocked = false;
        $action = 'none';
        if ($enabled && $budget > 0) {
            if ($percent >= $hard) {
                $hardAction = (string) ($policy['hard_limit_action'] ?? 'notify_only');
                if ($hardAction === 'block_rs_ai') {
                    $blocked = true;
                    $action = 'block_rs_ai';
                } elseif ($hardAction === 'economy') {
                    $forceEconomy = true;
                    $action = 'economy';
                } else {
                    $action = 'notify_only';
                }
            } elseif ($percent >= $warning && (string) ($policy['warning_action'] ?? 'none') === 'economy') {
                $forceEconomy = true;
                $action = 'economy';
            }
        }

        return [
            'enabled' => $enabled,
            'allowed' => !$blocked,
            'blocked' => $blocked,
            'force_economy' => $forceEconomy,
            'action' => $action,
            'used_usd' => $used,
            'budget_usd' => $budget,
            'remaining_usd' => $budget > 0 ? max(0.0, $budget - $used) : null,
            'used_rate' => $rate,
            'used_percent' => $percent,
            'warning_percent' => $warning,
            'critical_percent' => $critical,
            'hard_limit_percent' => $hard,
            'period' => $period,
            'provider_calls' => (int) ($usage['provider_calls'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'message' => $blocked
                ? 'O orçamento de IA custeada pela RS Connect atingiu o limite definido para esta empresa. Regras locais, cache, atendimento humano e credencial própria continuam disponíveis.'
                : ($forceEconomy ? 'Modo Econômico aplicado temporariamente pela política de orçamento da empresa.' : ''),
        ];
    }

    /** @param array<string,string> $period @return array{cost_usd:float,provider_calls:int,total_tokens:int} */
    public function costUsage(int $tenantId, array $period): array
    {
        if ($tenantId < 1) {
            return ['cost_usd' => 0.0, 'provider_calls' => 0, 'total_tokens' => 0];
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT COALESCE(SUM(CASE WHEN estimated_cost_currency = "USD" THEN estimated_cost ELSE 0 END),0) cost_usd,
                        COALESCE(SUM(provider_calls),0) provider_calls,
                        COALESCE(SUM(total_tokens),0) total_tokens
                 FROM ai_usage_events
                 WHERE tenant_id = :tenant_id
                   AND credential_owner = "rs_connect"
                   AND COALESCE(provider_calls,0) > 0
                   AND created_at BETWEEN :start_at AND :end_at'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'start_at' => $period['start_at'],
                'end_at' => $period['end_at'],
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'cost_usd' => (float) ($row['cost_usd'] ?? 0),
                'provider_calls' => (int) ($row['provider_calls'] ?? 0),
                'total_tokens' => (int) ($row['total_tokens'] ?? 0),
            ];
        } catch (Throwable) {
            return ['cost_usd' => 0.0, 'provider_calls' => 0, 'total_tokens' => 0];
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function overview(): array
    {
        try {
            $pdo = Database::connection();
            $tenants = $pdo->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $rows = [];
            foreach ($tenants as $tenant) {
                $tenantId = (int) ($tenant['id'] ?? 0);
                $decision = $this->decision($tenantId);
                $policy = $this->policy($tenantId);
                $rows[] = array_merge($decision, [
                    'tenant_id' => $tenantId,
                    'tenant_name' => (string) ($tenant['name'] ?? ('Empresa #' . $tenantId)),
                    'warning_action' => (string) ($policy['warning_action'] ?? 'none'),
                    'hard_limit_action' => (string) ($policy['hard_limit_action'] ?? 'notify_only'),
                ]);
            }
            usort($rows, static function (array $a, array $b): int {
                $rateCompare = (float) ($b['used_rate'] ?? 0) <=> (float) ($a['used_rate'] ?? 0);
                return $rateCompare !== 0 ? $rateCompare : ((float) ($b['used_usd'] ?? 0) <=> (float) ($a['used_usd'] ?? 0));
            });
            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    public function evaluateAndNotify(int $tenantId): void
    {
        if ($tenantId < 1) {
            return;
        }
        try {
            $decision = $this->decision($tenantId);
            if (empty($decision['enabled']) || (float) ($decision['budget_usd'] ?? 0) <= 0) {
                return;
            }
            $thresholds = [
                (int) ($decision['warning_percent'] ?? 80),
                (int) ($decision['critical_percent'] ?? 95),
                (int) ($decision['hard_limit_percent'] ?? 100),
            ];
            foreach (array_values(array_unique($thresholds)) as $threshold) {
                if ((float) ($decision['used_percent'] ?? 0) + 0.00001 < $threshold) {
                    continue;
                }
                $this->notifyThreshold($tenantId, $threshold, $decision);
            }
        } catch (Throwable) {
        }
    }

    /** @param array<string,mixed> $decision */
    private function notifyThreshold(int $tenantId, int $threshold, array $decision): void
    {
        $budget = (float) ($decision['budget_usd'] ?? 0);
        $used = (float) ($decision['used_usd'] ?? 0);
        $period = is_array($decision['period'] ?? null) ? $decision['period'] : [];
        if ($budget <= 0 || $threshold < 1) {
            return;
        }
        try {
            $claim = Database::connection()->prepare(
                'INSERT IGNORE INTO ai_budget_threshold_events
                    (tenant_id, period_start, period_end, threshold_percent, budget_usd, used_usd, action_taken)
                 VALUES (:tenant_id, :period_start, :period_end, :threshold, :budget, :used, :action)'
            );
            $claim->execute([
                'tenant_id' => $tenantId,
                'period_start' => (string) ($period['start_date'] ?? date('Y-m-01')),
                'period_end' => (string) ($period['end_date'] ?? date('Y-m-t')),
                'threshold' => $threshold,
                'budget' => $budget,
                'used' => $used,
                'action' => (string) ($decision['action'] ?? 'none'),
            ]);
            if ($claim->rowCount() < 1) {
                return;
            }
        } catch (Throwable) {
            return;
        }

        $hard = (int) ($decision['hard_limit_percent'] ?? 100);
        $critical = (int) ($decision['critical_percent'] ?? 95);
        $severity = $threshold >= $hard ? 'danger' : ($threshold >= $critical ? 'warning' : 'info');
        $title = $threshold >= $hard ? 'Orçamento de IA atingiu o limite' : ($threshold >= $critical ? 'Orçamento de IA em nível crítico' : 'Orçamento de IA em atenção');
        $message = 'A empresa utilizou US$ ' . number_format($used, 4, '.', '') . ' de US$ ' . number_format($budget, 4, '.', '') . ' do orçamento de IA custeada pela RS Connect (' . number_format((float) ($decision['used_percent'] ?? 0), 1, ',', '.') . '%).';
        if (($decision['action'] ?? '') === 'economy') {
            $message .= ' O modo Econômico foi aplicado automaticamente às novas chamadas com credencial RS.';
        } elseif (($decision['action'] ?? '') === 'block_rs_ai') {
            $message .= ' Novas chamadas com credencial RS estão bloqueadas; atendimento humano, cache, regras locais e credencial própria seguem disponíveis.';
        }

        try {
            (new NotificationService())->createIfEnabled(
                $tenantId, 'billing', $title, $message, $severity,
                '/subscription', 'billing', 'ai.budget.' . $threshold, 'tenant', $tenantId,
                ['threshold' => $threshold, 'budget_usd' => $budget, 'used_usd' => $used, 'action' => $decision['action'] ?? 'none'], 0
            );
        } catch (Throwable) {
        }

        $this->notifyAdmins($tenantId, $title, $message, $severity);
    }

    private function notifyAdmins(int $tenantId, string $title, string $message, string $severity): void
    {
        try {
            $pdo = Database::connection();
            $tenantStatement = $pdo->prepare('SELECT name FROM tenants WHERE id = :id LIMIT 1');
            $tenantStatement->execute(['id' => $tenantId]);
            $tenantName = trim((string) $tenantStatement->fetchColumn()) ?: ('Empresa #' . $tenantId);
            $admins = $pdo->query("SELECT id FROM users WHERE role = 'super_admin' AND status = 'active'")->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($admins as $admin) {
                $userId = (int) ($admin['id'] ?? 0);
                if ($userId < 1) continue;
                $preferences = (new OperationalAlertService())->preferences($userId);
                if (empty($preferences['platform_enabled']) || empty($preferences['ai_enabled'])) continue;
                $pdo->prepare(
                    'INSERT INTO admin_operational_notifications
                        (user_id, incident_id, notification_kind, severity, title, message, action_url)
                     VALUES (:user_id, NULL, "manual", :severity, :title, :message, :action_url)'
                )->execute([
                    'user_id' => $userId,
                    'severity' => in_array($severity, ['info','warning','danger'], true) ? $severity : 'warning',
                    'title' => mb_substr($title . ' — ' . $tenantName, 0, 180),
                    'message' => $message,
                    'action_url' => '/openai-usage?usage_period=month&tenant_id=' . $tenantId,
                ]);
            }
        } catch (Throwable) {
        }
    }
}
