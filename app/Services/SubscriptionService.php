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
        'instances' => 'Canais WhatsApp',
        'agents' => 'Agentes especializados de IA',
        'n8n_flows' => 'Automações integradas',
        'contacts_month' => 'Novos contatos/mês',
        'conversations_month' => 'Novas conversas/mês',
        'ai_interactions_month' => 'Franquia de IA RS/mês',
        'appointments_month' => 'Agendamentos/mês',
        'crm_leads_month' => 'Oportunidades/mês',
    ];

    public const LIMIT_DESCRIPTIONS = [
        'users' => 'Pessoas da equipe com acesso ativo ao RS Connect.',
        'instances' => 'Números WhatsApp conectados. Cada número conta como um canal de atendimento da empresa.',
        'agents' => 'Funções especializadas de IA. Um agente pode atuar em vários canais e vários agentes podem compartilhar o mesmo WhatsApp.',
        'n8n_flows' => 'Integrações e rotinas automatizadas executadas pela camada n8n da operação.',
        'contacts_month' => 'Contatos criados durante o mês atual.',
        'conversations_month' => 'Novas conversas abertas durante o mês atual.',
        'ai_interactions_month' => 'Respostas automáticas enviadas usando IA custeada pela RS Connect. Uso com credencial própria é contado separadamente e não reduz esta franquia.',
        'appointments_month' => 'Agendamentos criados durante o mês atual.',
        'crm_leads_month' => 'Oportunidades comerciais criadas durante o mês atual.',
    ];

    public function currentPlanForTenant(int $tenantId): array
    {
        $pdo = Database::connection();
        try {
            $statement = $pdo->prepare(
                'SELECT sp.*, ts.id AS subscription_id, ts.billing_status, ts.current_period_starts_at,
                        ts.current_period_ends_at, ts.next_billing_at, ts.amount AS subscription_amount,
                        ts.billing_cycle, ts.ai_billing_mode, ts.commitment_months, ts.commitment_ends_at,
                        ts.trial_ends_at, ts.trial_days, ts.trial_end_behavior, ts.trial_grace_days
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
        $period = $this->usagePeriodForTenant($tenantId);
        $queries = [
            'users' => ['SELECT COUNT(*) FROM users WHERE tenant_id = :tenant_id AND status = "active"', false],
            'instances' => ['SELECT COUNT(*) FROM evolution_instances WHERE tenant_id = :tenant_id', false],
            'agents' => ['SELECT COUNT(*) FROM ai_agents WHERE tenant_id = :tenant_id', false],
            'n8n_flows' => ['SELECT COUNT(*) FROM n8n_tenant_flows WHERE tenant_id = :tenant_id AND status = "active"', false],
            'contacts_month' => ['SELECT COUNT(*) FROM contacts WHERE tenant_id = :tenant_id AND created_at BETWEEN :start_at AND :end_at', true],
            'conversations_month' => ['SELECT COUNT(*) FROM conversations WHERE tenant_id = :tenant_id AND created_at BETWEEN :start_at AND :end_at', true],
            'ai_interactions_month' => ['SELECT COUNT(*) FROM ai_usage_events WHERE tenant_id = :tenant_id AND usage_type = "auto_reply" AND plan_billable = 1 AND status = "success" AND delivery_status = "delivered" AND created_at BETWEEN :start_at AND :end_at', true],
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
                    $params['start_at'] = $period['start_at'];
                    $params['end_at'] = $period['end_at'];
                }
                $statement->execute($params);
                $usage[$key] = (int) $statement->fetchColumn();
            } catch (Throwable) {
                // Compatibilidade temporária antes da migration 052: conta apenas saídas da IA.
                if ($key === 'ai_interactions_month') {
                    try {
                        $fallback = $pdo->prepare('SELECT COUNT(*) FROM conversation_messages WHERE tenant_id = :tenant_id AND sender_type = "ai" AND direction = "outgoing" AND created_at BETWEEN :start_at AND :end_at');
                        $fallback->execute(['tenant_id' => $tenantId, 'start_at' => $period['start_at'], 'end_at' => $period['end_at']]);
                        $usage[$key] = (int) $fallback->fetchColumn();
                        continue;
                    } catch (Throwable) {
                    }
                }
                $usage[$key] = 0;
            }
        }

        return $usage;
    }

    /** @return array{start_at:string,end_at:string,start_date:string,end_date:string} */
    public function usagePeriodForTenant(int $tenantId): array
    {
        // Os limites comerciais terminados em _month continuam mensais, independentemente
        // de a assinatura ser cobrada mensal, trimestral ou anualmente. Preserva a regra
        // já existente no RS Connect e evita transformar uma cobrança anual em franquia anual.
        $startDate = date('Y-m-01');
        $endDate = date('Y-m-t');

        return [
            'start_at' => $startDate . ' 00:00:00',
            'end_at' => $endDate . ' 23:59:59',
            'start_date' => $startDate,
            'end_date' => $endDate,
        ];
    }

    /**
     * Visão consolidada de uso da IA e mensagens no mês corrente.
     *
     * Interação comercial = auto_reply entregue com sucesso.
     * Chamadas/tokens = telemetria técnica de qualquer uso da IA, inclusive falhas e sugestões.
     *
     * @return array<string,mixed>
     */
    public function aiUsageBreakdownForTenant(int $tenantId): array
    {
        $period = $this->usagePeriodForTenant($tenantId);
        $plan = $this->currentPlanForTenant($tenantId);
        $result = ['rs_connect' => 0, 'tenant' => 0];
        $pdo = Database::connection();

        try {
            $statement = $pdo->prepare(
                'SELECT credential_owner, COUNT(*) AS total
                 FROM ai_usage_events
                 WHERE tenant_id = :tenant_id
                   AND usage_type = "auto_reply"
                   AND status = "success"
                   AND delivery_status = "delivered"
                   AND created_at BETWEEN :start_at AND :end_at
                 GROUP BY credential_owner'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'start_at' => $period['start_at'],
                'end_at' => $period['end_at'],
            ]);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $owner = (string) ($row['credential_owner'] ?? '');
                if (array_key_exists($owner, $result)) {
                    $result[$owner] = (int) ($row['total'] ?? 0);
                }
            }
        } catch (Throwable) {
            // Compatibilidade antes da migration 054: status success era a confirmação de entrega.
            try {
                $statement = $pdo->prepare(
                    'SELECT credential_owner, COUNT(*) AS total
                     FROM ai_usage_events
                     WHERE tenant_id = :tenant_id
                       AND usage_type = "auto_reply"
                       AND status = "success"
                       AND created_at BETWEEN :start_at AND :end_at
                     GROUP BY credential_owner'
                );
                $statement->execute([
                    'tenant_id' => $tenantId,
                    'start_at' => $period['start_at'],
                    'end_at' => $period['end_at'],
                ]);
                foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                    $owner = (string) ($row['credential_owner'] ?? '');
                    if (array_key_exists($owner, $result)) {
                        $result[$owner] = (int) ($row['total'] ?? 0);
                    }
                }
            } catch (Throwable) {
                $result['rs_connect'] = $this->usageForTenant($tenantId)['ai_interactions_month'] ?? 0;
            }
        }

        $messages = [
            'total' => 0,
            'incoming' => 0,
            'human_outgoing' => 0,
            'automatic_outgoing' => 0,
        ];
        try {
            $statement = $pdo->prepare(
                'SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN direction = "incoming" THEN 1 ELSE 0 END) AS incoming,
                    SUM(CASE WHEN direction = "outgoing" AND sender_type = "user" THEN 1 ELSE 0 END) AS human_outgoing,
                    SUM(CASE WHEN direction = "outgoing" AND sender_type IN ("ai","system") THEN 1 ELSE 0 END) AS automatic_outgoing
                 FROM conversation_messages
                 WHERE tenant_id = :tenant_id
                   AND sent_at BETWEEN :start_at AND :end_at'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'start_at' => $period['start_at'],
                'end_at' => $period['end_at'],
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (array_keys($messages) as $key) {
                $messages[$key] = (int) ($row[$key] ?? 0);
            }
        } catch (Throwable) {
        }

        $technical = [
            'provider_calls' => 0,
            'provider_calls_avoided' => 0,
            'input_tokens' => 0,
            'output_tokens' => 0,
            'total_tokens' => 0,
            'cached_tokens' => 0,
            'estimated_input_tokens_avoided' => 0,
            'failed_events' => 0,
            'cancelled_events' => 0,
            'successful_events' => 0,
        ];
        $costs = ['rs_connect' => [], 'tenant' => []];
        try {
            $statement = $pdo->prepare(
                'SELECT
                    COALESCE(SUM(provider_calls), 0) AS provider_calls,
                    COALESCE(SUM(provider_calls_avoided), 0) AS provider_calls_avoided,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(total_tokens), 0) AS total_tokens,
                    COALESCE(SUM(cached_tokens), 0) AS cached_tokens,
                    COALESCE(SUM(estimated_input_tokens_avoided), 0) AS estimated_input_tokens_avoided,
                    SUM(CASE WHEN status = "failed" THEN 1 ELSE 0 END) AS failed_events,
                    SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) AS cancelled_events,
                    SUM(CASE WHEN status = "success" THEN 1 ELSE 0 END) AS successful_events
                 FROM ai_usage_events
                 WHERE tenant_id = :tenant_id
                   AND created_at BETWEEN :start_at AND :end_at'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'start_at' => $period['start_at'],
                'end_at' => $period['end_at'],
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            foreach (array_keys($technical) as $key) {
                $technical[$key] = (int) ($row[$key] ?? 0);
            }

            $costStatement = $pdo->prepare(
                'SELECT credential_owner, estimated_cost_currency AS currency, COALESCE(SUM(estimated_cost), 0) AS total
                 FROM ai_usage_events
                 WHERE tenant_id = :tenant_id
                   AND created_at BETWEEN :start_at AND :end_at
                   AND estimated_cost IS NOT NULL
                   AND estimated_cost_currency IS NOT NULL
                 GROUP BY credential_owner, estimated_cost_currency
                 ORDER BY credential_owner, estimated_cost_currency'
            );
            $costStatement->execute([
                'tenant_id' => $tenantId,
                'start_at' => $period['start_at'],
                'end_at' => $period['end_at'],
            ]);
            foreach ($costStatement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $owner = (string) ($row['credential_owner'] ?? '');
                $currency = strtoupper(trim((string) ($row['currency'] ?? '')));
                if (isset($costs[$owner]) && $currency !== '') {
                    $costs[$owner][$currency] = (float) ($row['total'] ?? 0);
                }
            }
        } catch (Throwable) {
            // A telemetria detalhada exige a migration 054; mantém os contadores comerciais funcionais.
        }

        return [
            'rs_connect' => $result['rs_connect'],
            'tenant' => $result['tenant'],
            'total' => $result['rs_connect'] + $result['tenant'],
            'billable_limit' => $plan['limits']['ai_interactions_month'] ?? $plan['limits']['messages_month'] ?? $plan['limits']['ai_replies_month'] ?? null,
            'period' => $period,
            'messages' => $messages,
            'technical' => $technical,
            'costs' => $costs,
            'agents' => $this->aiUsageByAgentForTenant($tenantId, $period),
        ];
    }

    /**
     * @param array{start_at:string,end_at:string,start_date:string,end_date:string}|null $period
     * @return list<array<string,mixed>>
     */
    public function aiUsageByAgentForTenant(int $tenantId, ?array $period = null): array
    {
        $period ??= $this->usagePeriodForTenant($tenantId);
        try {
            $statement = Database::connection()->prepare(
                'SELECT
                    COALESCE(a.name, CONCAT("Assistente #", COALESCE(e.agent_id, 0))) AS agent_name,
                    e.agent_id,
                    e.credential_owner,
                    e.provider,
                    COALESCE(NULLIF(e.model, ""), "—") AS model,
                    SUM(CASE WHEN e.usage_type = "auto_reply" AND e.status = "success" AND e.delivery_status = "delivered" THEN 1 ELSE 0 END) AS interactions,
                    COALESCE(SUM(e.provider_calls), 0) AS provider_calls,
                    COALESCE(SUM(e.provider_calls_avoided), 0) AS provider_calls_avoided,
                    COALESCE(SUM(e.input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(e.output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(e.total_tokens), 0) AS total_tokens,
                    COALESCE(SUM(e.cached_tokens), 0) AS cached_tokens,
                    COALESCE(SUM(e.estimated_input_tokens_avoided), 0) AS estimated_input_tokens_avoided,
                    SUM(CASE WHEN e.status = "failed" THEN 1 ELSE 0 END) AS failed_events,
                    e.estimated_cost_currency AS cost_currency,
                    COALESCE(SUM(e.estimated_cost), 0) AS estimated_cost
                 FROM ai_usage_events e
                 LEFT JOIN ai_agents a ON a.id = e.agent_id
                 WHERE e.tenant_id = :tenant_id
                   AND e.created_at BETWEEN :start_at AND :end_at
                 GROUP BY e.agent_id, a.name, e.credential_owner, e.provider, e.model, e.estimated_cost_currency
                 ORDER BY interactions DESC, provider_calls DESC, agent_name'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'start_at' => $period['start_at'],
                'end_at' => $period['end_at'],
            ]);
            $merged = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $key = implode('|', [
                    (string) ($row['agent_id'] ?? '0'),
                    (string) ($row['credential_owner'] ?? ''),
                    (string) ($row['provider'] ?? ''),
                    (string) ($row['model'] ?? ''),
                ]);
                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'agent_id' => isset($row['agent_id']) ? (int) $row['agent_id'] : null,
                        'agent_name' => (string) ($row['agent_name'] ?? 'Assistente'),
                        'credential_owner' => (string) ($row['credential_owner'] ?? ''),
                        'provider' => (string) ($row['provider'] ?? ''),
                        'model' => (string) ($row['model'] ?? '—'),
                        'interactions' => 0,
                        'provider_calls' => 0,
                        'provider_calls_avoided' => 0,
                        'input_tokens' => 0,
                        'output_tokens' => 0,
                        'total_tokens' => 0,
                        'cached_tokens' => 0,
                        'estimated_input_tokens_avoided' => 0,
                        'failed_events' => 0,
                        'costs' => [],
                    ];
                }
                foreach (['interactions','provider_calls','provider_calls_avoided','input_tokens','output_tokens','total_tokens','cached_tokens','estimated_input_tokens_avoided','failed_events'] as $metric) {
                    $merged[$key][$metric] += (int) ($row[$metric] ?? 0);
                }
                $currency = strtoupper(trim((string) ($row['cost_currency'] ?? '')));
                if ($currency !== '') {
                    $merged[$key]['costs'][$currency] = ($merged[$key]['costs'][$currency] ?? 0.0) + (float) ($row['estimated_cost'] ?? 0);
                }
            }
            return array_values($merged);
        } catch (Throwable) {
            return [];
        }
    }

    public function limitRows(int $tenantId): array
    {
        $plan = $this->currentPlanForTenant($tenantId);
        $usage = $this->usageForTenant($tenantId);
        $rows = [];
        foreach (self::LIMIT_LABELS as $key => $label) {
            $limit = $plan['limits'][$key] ?? null;
            if ($key === 'ai_interactions_month' && $limit === null) {
                // Compatibilidade/recuperação: instalações que ainda não aplicaram a 052,
                // ou cuja conversão do JSON legado falhou, continuam respeitando o antigo
                // volume comercial de mensagens como franquia de interações de IA.
                $limit = $plan['limits']['messages_month'] ?? $plan['limits']['ai_replies_month'] ?? null;
            }
            $used = $usage[$key] ?? 0;
            $rows[] = [
                'key' => $key,
                'label' => $label,
                'description' => self::LIMIT_DESCRIPTIONS[$key] ?? '',
                'used' => $used,
                'limit' => $limit,
                'percent' => $limit !== null ? ((int) $limit > 0 ? min(100, (int) round(($used / (int) $limit) * 100)) : 100) : 0,
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
        if ($limitKey === 'ai_interactions_month' && $limit === null) {
            $limit = $plan['limits']['messages_month'] ?? $plan['limits']['ai_replies_month'] ?? null;
        }
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
            'ai_billing_mode' => (string) ($plan['ai_billing_mode'] ?? 'rs_connect'),
            'commitment_months' => isset($plan['commitment_months']) ? (int) $plan['commitment_months'] : 3,
            'commitment_ends_at' => $plan['commitment_ends_at'] ?? null,
            'current_period_starts_at' => $plan['current_period_starts_at'] ?? null,
            'current_period_ends_at' => $plan['current_period_ends_at'] ?? null,
            'next_billing_at' => $plan['next_billing_at'] ?? null,
            'trial_ends_at' => $plan['trial_ends_at'] ?? null,
            'trial_days' => isset($plan['trial_days']) ? (int) $plan['trial_days'] : null,
            'trial_end_behavior' => $plan['trial_end_behavior'] ?? 'await_payment',
            'trial_grace_days' => isset($plan['trial_grace_days']) ? (int) $plan['trial_grace_days'] : 3,
            'limits' => $limits,
            'features' => is_array($features) ? $features : [],
        ];
    }
}
