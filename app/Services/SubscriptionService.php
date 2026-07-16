<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class SubscriptionService
{
    public const LIMIT_LABELS = [
        'users' => 'Usuários ativos',
        'instances' => 'Conexões WhatsApp',
        'agents' => 'Agentes de IA',
        'n8n_flows' => 'Fluxos n8n',
        'contacts_month' => 'Novos contatos/mês',
        'conversations_month' => 'Novas conversas/mês',
        'messages_month' => 'Mensagens/mês',
        'ai_replies_month' => 'Respostas IA/mês',
        'appointments_month' => 'Agendamentos/mês',
        'crm_leads_month' => 'Oportunidades/mês',
    ];

    public function currentPlanForTenant(int $tenantId): array
    {
        $pdo = Database::connection();
        try {
            $statement = $pdo->prepare(
                'SELECT sp.*, ts.id AS subscription_id, ts.billing_status, ts.current_period_starts_at,
                        ts.current_period_ends_at, ts.next_billing_at, ts.amount AS subscription_amount,
                        ts.billing_cycle, ts.trial_ends_at
                 FROM tenant_subscriptions ts
                 INNER JOIN saas_plans sp ON sp.id = ts.plan_id
                 WHERE ts.tenant_id = :tenant_id
                   AND ts.billing_status IN ("trialing", "active", "overdue", "suspended")
                 ORDER BY ts.id DESC
                 LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $plan = $statement->fetch(PDO::FETCH_ASSOC);
            if ($plan) {
                return $this->normalizePlan($plan);
            }
        } catch (Throwable) {
            // Tables may not exist before the migration is executed. Fall through to permissive fallback.
        }

        try {
            $tenant = $pdo->prepare('SELECT plan FROM tenants WHERE id = :id LIMIT 1');
            $tenant->execute(['id' => $tenantId]);
            $planKey = (string) ($tenant->fetchColumn() ?: 'starter');
            $statement = $pdo->prepare('SELECT * FROM saas_plans WHERE plan_key = :plan_key LIMIT 1');
            $statement->execute(['plan_key' => $planKey]);
            $plan = $statement->fetch(PDO::FETCH_ASSOC);
            if ($plan) {
                $plan['billing_status'] = 'active';
                return $this->normalizePlan($plan);
            }
        } catch (Throwable) {
            // fallback below
        }

        return $this->normalizePlan([
            'id' => null,
            'plan_key' => 'custom',
            'name' => 'Sem plano definido',
            'monthly_price' => '0.00',
            'billing_status' => 'active',
            'limits_json' => json_encode(['users' => null, 'instances' => null, 'agents' => null, 'n8n_flows' => null], JSON_UNESCAPED_UNICODE),
            'features_json' => json_encode(['Acesso liberado até configurar planos'], JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function usageForTenant(int $tenantId): array
    {
        $period = [date('Y-m-01 00:00:00'), date('Y-m-t 23:59:59')];
        $queries = [
            'users' => ['SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND status = "active"', false],
            'instances' => ['SELECT COUNT(*) FROM evolution_instances WHERE tenant_id = :tenant_id', false],
            'agents' => ['SELECT COUNT(*) FROM ai_agents WHERE tenant_id = :tenant_id', false],
            'n8n_flows' => ['SELECT COUNT(*) FROM n8n_tenant_flows WHERE tenant_id = :tenant_id AND status = "active"', false],
            'contacts_month' => ['SELECT COUNT(*) FROM contacts WHERE tenant_id = :tenant_id AND created_at BETWEEN :start_at AND :end_at', true],
            'conversations_month' => ['SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND created_at BETWEEN :start_at AND :end_at', true],
            'messages_month' => ['SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND created_at BETWEEN :start_at AND :end_at', true],
            'ai_replies_month' => ['SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND sender_type = "ai" AND direction = "outgoing" AND created_at BETWEEN :start_at AND :end_at', true],
            'appointments_month' => ['SELECT COUNT(*) FROM calendar_appointments WHERE tenant_id = :tenant_id AND created_at BETWEEN :start_at AND :end_at', true],
            'crm_leads_month' => ['SELECT COUNT(*) FROM crm_leads WHERE tenant_id = :tenant_id AND created_at BETWEEN :start_at AND :end_at', true],
        ];

        $pdo = Database::connection();
        $usage = [];
        foreach ($queries as $key => [$sql, $usesPeriod]) {
            try {
                $statement = $pdo->prepare($sql);
                $params = ['tenant_id' => $tenantId];
                if ($usesPeriod) {
                    $params['start_at'] = $period[0];
                    $params['end_at'] = $period[1];
                }
                $statement->execute($params);
                $usage[$key] = (int) $statement->fetchColumn();
            } catch (Throwable) {
                $usage[$key] = 0;
            }
        }

        return $usage;
    }

    public function limitRows(int $tenantId): array
    {
        $plan = $this->currentPlanForTenant($tenantId);
        $usage = $this->usageForTenant($tenantId);
        $rows = [];
        foreach (self::LIMIT_LABELS as $key => $label) {
            $limit = $plan['limits'][$key] ?? null;
            $used = $usage[$key] ?? 0;
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'used' => $used,
                'limit' => $limit,
                'percent' => $limit ? min(100, (int) round(($used / max(1, (int) $limit)) * 100)) : 0,
                'blocked' => $limit !== null && $used >= (int) $limit,
            ];
        }
        return $rows;
    }

    public function ensureCanCreate(int $tenantId, string $limitKey): array
    {
        $plan = $this->currentPlanForTenant($tenantId);
        $usage = $this->usageForTenant($tenantId);
        $limit = $plan['limits'][$limitKey] ?? null;
        $used = $usage[$limitKey] ?? 0;
        if ($limit === null || $used < (int) $limit) {
            return ['ok' => true, 'message' => 'Liberado.'];
        }

        return [
            'ok' => false,
            'message' => 'Limite do plano atingido para ' . (self::LIMIT_LABELS[$limitKey] ?? $limitKey) . '. Atualize o plano da empresa ou aumente o limite no painel financeiro.',
        ];
    }

    private function normalizePlan(array $plan): array
    {
        $limits = json_decode((string) ($plan['limits_json'] ?? '{}'), true);
        $features = json_decode((string) ($plan['features_json'] ?? '[]'), true);
        $limits = is_array($limits) ? $limits : [];
        foreach ($limits as $key => $value) {
            $limits[$key] = $value === null || $value === '' ? null : (int) $value;
        }
        return [
            'id' => isset($plan['id']) ? (int) $plan['id'] : null,
            'subscription_id' => isset($plan['subscription_id']) ? (int) $plan['subscription_id'] : null,
            'key' => (string) ($plan['plan_key'] ?? 'custom'),
            'name' => (string) ($plan['name'] ?? 'Plano'),
            'monthly_price' => (float) ($plan['subscription_amount'] ?? $plan['monthly_price'] ?? 0),
            'billing_status' => (string) ($plan['billing_status'] ?? 'active'),
            'billing_cycle' => (string) ($plan['billing_cycle'] ?? 'monthly'),
            'current_period_starts_at' => $plan['current_period_starts_at'] ?? null,
            'current_period_ends_at' => $plan['current_period_ends_at'] ?? null,
            'next_billing_at' => $plan['next_billing_at'] ?? null,
            'trial_ends_at' => $plan['trial_ends_at'] ?? null,
            'limits' => $limits,
            'features' => is_array($features) ? $features : [],
        ];
    }
}
