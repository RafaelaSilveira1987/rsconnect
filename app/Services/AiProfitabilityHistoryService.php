<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Inteligência histórica de rentabilidade da IA.
 *
 * A leitura usa custos de IA custeados pela RS Connect, receita contratada/de referência
 * e outros custos informados. Os indicadores representam contribuição conhecida, não lucro líquido.
 */
final class AiProfitabilityHistoryService
{
    public const MAX_HISTORY_MONTHS = 24;

    /** @return array<int,array{id:int,name:string}> */
    public function tenantOptions(): array
    {
        try {
            $rows = Database::connection()->query('SELECT id, name FROM tenants WHERE status = "active" ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_map(static fn (array $row): array => [
                'id' => (int) ($row['id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
            ], $rows);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    public function portfolio(): array
    {
        $rows = (new AiCommercialMarginService())->overview();
        $mrr = 0.0;
        $aiProjected = 0.0;
        $knownCosts = 0.0;
        $contribution = 0.0;
        $mrrUnderTarget = 0.0;
        $needsReview = 0;
        $healthy = 0;
        $configured = 0;

        foreach ($rows as $row) {
            if (empty($row['configured'])) {
                continue;
            }
            $configured++;
            $revenue = max(0.0, (float) ($row['revenue_brl'] ?? 0));
            $mrr += $revenue;
            $aiProjected += max(0.0, (float) ($row['projected_ai_cost_brl'] ?? 0));
            $knownCosts += max(0.0, (float) ($row['projected_known_cost_brl'] ?? 0));
            $contribution += (float) ($row['projected_contribution_brl'] ?? 0);
            $status = (string) ($row['status'] ?? 'unconfigured');
            if ($status === 'healthy') {
                $healthy++;
            } else {
                $needsReview++;
                $mrrUnderTarget += $revenue;
            }
        }

        return [
            'mrr_brl' => round($mrr, 2),
            'projected_ai_cost_brl' => round($aiProjected, 2),
            'known_cost_brl' => round($knownCosts, 2),
            'contribution_brl' => round($contribution, 2),
            'margin_rate' => $mrr > 0 ? $contribution / $mrr : null,
            'mrr_under_target_brl' => round($mrrUnderTarget, 2),
            'mrr_under_target_rate' => $mrr > 0 ? $mrrUnderTarget / $mrr : 0.0,
            'configured_tenants' => $configured,
            'healthy_tenants' => $healthy,
            'review_tenants' => $needsReview,
            'rows' => $rows,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function history(int $tenantId, int $months = 6, bool $refresh = false): array
    {
        if ($tenantId < 1) return [];
        $months = max(3, min(self::MAX_HISTORY_MONTHS, $months));
        $currentMonth = new DateTimeImmutable('first day of this month 00:00:00');
        $rows = [];
        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $month = $offset > 0 ? $currentMonth->modify('-' . $offset . ' months') : $currentMonth;
            $rows[] = $this->monthMetrics($tenantId, $month, $refresh || $offset === 0);
        }
        return $rows;
    }

    /** @return array<int,array<string,mixed>> */
    public function portfolioHistory(int $months = 6): array
    {
        $months = max(3, min(12, $months));
        $tenants = $this->tenantOptions();
        if ($tenants === []) return [];

        $bucket = [];
        foreach ($tenants as $tenant) {
            foreach ($this->history((int) $tenant['id'], $months) as $row) {
                $key = (string) ($row['period_month'] ?? '');
                if ($key === '') continue;
                if (!isset($bucket[$key])) {
                    $bucket[$key] = [
                        'period_month' => $key,
                        'label' => (string) ($row['label'] ?? $key),
                        'revenue_brl' => 0.0,
                        'ai_cost_brl' => 0.0,
                        'known_cost_brl' => 0.0,
                        'contribution_brl' => 0.0,
                        'provider_calls' => 0,
                        'avoided_calls' => 0,
                        'total_tokens' => 0,
                        'configured_tenants' => 0,
                    ];
                }
                $bucket[$key]['revenue_brl'] += (float) ($row['revenue_brl'] ?? 0);
                $bucket[$key]['ai_cost_brl'] += (float) ($row['ai_cost_brl'] ?? 0);
                $bucket[$key]['known_cost_brl'] += (float) ($row['known_cost_brl'] ?? 0);
                $bucket[$key]['contribution_brl'] += (float) ($row['contribution_brl'] ?? 0);
                $bucket[$key]['provider_calls'] += (int) ($row['provider_calls'] ?? 0);
                $bucket[$key]['avoided_calls'] += (int) ($row['avoided_calls'] ?? 0);
                $bucket[$key]['total_tokens'] += (int) ($row['total_tokens'] ?? 0);
                if ((float) ($row['revenue_brl'] ?? 0) > 0) $bucket[$key]['configured_tenants']++;
            }
        }

        ksort($bucket);
        foreach ($bucket as &$row) {
            $revenue = (float) $row['revenue_brl'];
            $row['margin_rate'] = $revenue > 0 ? ((float) $row['contribution_brl']) / $revenue : null;
            $row['revenue_brl'] = round((float) $row['revenue_brl'], 2);
            $row['ai_cost_brl'] = round((float) $row['ai_cost_brl'], 2);
            $row['known_cost_brl'] = round((float) $row['known_cost_brl'], 2);
            $row['contribution_brl'] = round((float) $row['contribution_brl'], 2);
        }
        unset($row);
        return array_values($bucket);
    }

    /** @return array<string,mixed> */
    public function tenantDashboard(int $tenantId, int $months = 6, ?float $simulatedRevenue = null, bool $refresh = false): array
    {
        $history = $this->history($tenantId, $months, $refresh);
        $current = $history !== [] ? $history[count($history) - 1] : [];
        $previous = count($history) >= 2 ? $history[count($history) - 2] : [];
        $simulation = $this->planSimulation($tenantId, $simulatedRevenue);

        $marginDelta = null;
        if (($current['margin_rate'] ?? null) !== null && ($previous['margin_rate'] ?? null) !== null) {
            $marginDelta = (float) $current['margin_rate'] - (float) $previous['margin_rate'];
        }
        $aiCostDelta = $this->deltaRate((float) ($previous['ai_cost_brl'] ?? 0), (float) ($current['ai_cost_brl'] ?? 0));
        $revenueDelta = $this->deltaRate((float) ($previous['revenue_brl'] ?? 0), (float) ($current['revenue_brl'] ?? 0));

        return [
            'history' => $history,
            'current' => $current,
            'previous' => $previous,
            'trends' => [
                'margin_delta' => $marginDelta,
                'ai_cost_delta_rate' => $aiCostDelta,
                'revenue_delta_rate' => $revenueDelta,
            ],
            'simulation' => $simulation,
        ];
    }

    /** @return array<string,mixed> */
    public function monthMetrics(int $tenantId, DateTimeImmutable $month, bool $refresh = false): array
    {
        $month = $month->modify('first day of this month')->setTime(0, 0, 0);
        $monthKey = $month->format('Y-m-01');
        $isCurrent = $monthKey === (new DateTimeImmutable('first day of this month'))->format('Y-m-01');

        if (!$refresh && !$isCurrent) {
            $snapshot = $this->snapshot($tenantId, $monthKey);
            if ($snapshot !== null) return $this->normalizeSnapshot($snapshot);
        }

        $end = $month->modify('last day of this month')->setTime(23, 59, 59);
        $policy = $this->policyForPeriod($tenantId, $end);
        $subscription = $this->subscriptionForPeriod($tenantId, $month, $end);
        $revenueInfo = $this->revenueForPeriod($tenantId, $month, $end, $policy, $subscription);
        $ai = $this->aiUsageForPeriod($tenantId, $month, $end);

        $globalFx = max(0.0, (float) Env::get('OPENAI_USAGE_USD_BRL', 0));
        $policyFx = isset($policy['usd_brl_rate']) ? max(0.0, (float) $policy['usd_brl_rate']) : 0.0;
        $fx = $policyFx > 0 ? $policyFx : $globalFx;
        $aiCostUsd = max(0.0, (float) ($ai['cost_usd'] ?? 0));
        $aiCostBrl = $fx > 0 ? $aiCostUsd * $fx : 0.0;
        $otherCost = max(0.0, (float) ($policy['other_monthly_cost_brl'] ?? 0));
        $knownCost = $aiCostBrl + $otherCost;
        $revenue = max(0.0, (float) ($revenueInfo['revenue_brl'] ?? 0));
        $contribution = $revenue - $knownCost;
        $marginRate = $revenue > 0 ? $contribution / $revenue : null;
        $target = max(5.0, min(95.0, (float) ($policy['target_margin_percent'] ?? 60)));

        $row = [
            'tenant_id' => $tenantId,
            'period_month' => $monthKey,
            'label' => $this->monthLabel($month),
            'revenue_brl' => round($revenue, 2),
            'revenue_source' => (string) ($revenueInfo['source'] ?? 'unknown'),
            'revenue_quality' => (string) ($revenueInfo['quality'] ?? 'missing'),
            'ai_cost_usd' => round($aiCostUsd, 8),
            'usd_brl_rate' => round($fx, 6),
            'ai_cost_brl' => round($aiCostBrl, 4),
            'other_cost_brl' => round($otherCost, 2),
            'known_cost_brl' => round($knownCost, 4),
            'contribution_brl' => round($contribution, 4),
            'margin_rate' => $marginRate,
            'margin_percent' => $marginRate !== null ? round($marginRate * 100, 3) : null,
            'target_margin_percent' => $target,
            'provider_calls' => (int) ($ai['provider_calls'] ?? 0),
            'avoided_calls' => (int) ($ai['avoided_calls'] ?? 0),
            'total_tokens' => (int) ($ai['total_tokens'] ?? 0),
            'ai_conversations' => (int) ($ai['ai_conversations'] ?? 0),
            'plan_id' => (int) ($subscription['plan_id'] ?? 0) ?: null,
            'plan_key' => (string) ($subscription['plan_key'] ?? ''),
            'plan_name' => (string) ($subscription['plan_name'] ?? 'Sem plano'),
            'subscription_id' => (int) ($subscription['subscription_id'] ?? 0) ?: null,
            'source' => [
                'policy' => (string) ($policy['_source'] ?? 'current'),
                'fx' => $policyFx > 0 ? 'policy' : ($globalFx > 0 ? 'environment' : 'missing'),
                'revenue' => (string) ($revenueInfo['source'] ?? 'unknown'),
            ],
        ];

        $this->persistSnapshot($row);
        return $row;
    }

    /** @return array<string,mixed> */
    public function planSimulation(int $tenantId, ?float $simulatedRevenue = null): array
    {
        $commercial = (new AiCommercialMarginService())->analysis($tenantId);
        $subscriptionService = new SubscriptionService();
        $usage = $subscriptionService->usageForTenant($tenantId);
        $knownCost = max(0.0, (float) ($commercial['projected_known_cost_brl'] ?? 0));
        $target = max(0.05, min(0.95, (float) ($commercial['target_margin_rate'] ?? .60)));
        $currentRevenue = max(0.0, (float) ($commercial['revenue_brl'] ?? 0));
        $recommendedRevenue = max(0.0, (float) ($commercial['recommended_revenue_brl'] ?? 0));
        $aiShare = $currentRevenue > 0 ? max(0.0, (float) ($commercial['projected_ai_cost_brl'] ?? 0)) / $currentRevenue : 0.0;

        $plans = [];
        try {
            $rows = Database::connection()->query('SELECT id, plan_key, name, monthly_price, limits_json FROM saas_plans WHERE status = "active" ORDER BY monthly_price, sort_order, id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as $plan) {
                $price = max(0.0, (float) ($plan['monthly_price'] ?? 0));
                if ($price <= 0 && (string) ($plan['plan_key'] ?? '') !== 'custom') continue;
                $limits = json_decode((string) ($plan['limits_json'] ?? '{}'), true);
                if (!is_array($limits)) $limits = [];
                $capacity = $this->capacityCheck($limits, $usage);
                $margin = $price > 0 ? ($price - $knownCost) / $price : null;
                $plans[] = [
                    'id' => (int) ($plan['id'] ?? 0),
                    'plan_key' => (string) ($plan['plan_key'] ?? ''),
                    'name' => (string) ($plan['name'] ?? 'Plano'),
                    'monthly_price_brl' => $price,
                    'projected_margin_rate' => $margin,
                    'meets_margin' => $margin !== null && $margin >= $target,
                    'capacity_ok' => $capacity['ok'],
                    'capacity_issues' => $capacity['issues'],
                    'recommended' => false,
                ];
            }
        } catch (Throwable) {
            $plans = [];
        }

        $candidateIndex = null;
        foreach ($plans as $index => $plan) {
            if ($plan['capacity_ok'] && $plan['meets_margin'] && (float) $plan['monthly_price_brl'] >= $recommendedRevenue - 0.009) {
                $candidateIndex = $index;
                break;
            }
        }
        if ($candidateIndex !== null) $plans[$candidateIndex]['recommended'] = true;

        $provider = (int) ($commercial['provider_calls'] ?? 0);
        $currentMonth = $this->monthMetrics($tenantId, new DateTimeImmutable('first day of this month'));
        $avoided = (int) ($currentMonth['avoided_calls'] ?? 0);
        $avoidanceRate = ($provider + $avoided) > 0 ? $avoided / ($provider + $avoided) : 0.0;
        $currentMargin = $commercial['projected_margin_rate'] ?? null;
        $recommendation = 'configure';
        $message = 'Configure receita e cotação para receber uma recomendação comercial.';
        if (!empty($commercial['configured']) && $currentMargin !== null) {
            if ((float) $currentMargin >= $target) {
                $recommendation = 'keep';
                $message = 'A condição atual cobre a margem-alvo conhecida. Mantenha o plano e acompanhe a tendência.';
            } elseif ($aiShare >= .20 && $avoidanceRate < .15) {
                $recommendation = 'optimize_first';
                $message = 'A IA representa uma parcela relevante da receita e poucas chamadas estão sendo evitadas. Priorize otimização antes de reajustar.';
            } elseif ($candidateIndex !== null) {
                $recommendation = 'review_plan';
                $message = 'Existe plano padrão com capacidade e margem compatíveis. Use-o como referência para renegociação, sem alteração automática.';
            } else {
                $recommendation = 'custom_price';
                $message = 'Nenhum plano padrão cobre simultaneamente capacidade e margem. Use a receita mínima como referência de condição customizada.';
            }
        }

        $customRevenue = $simulatedRevenue !== null && $simulatedRevenue > 0 ? $simulatedRevenue : null;
        $customMargin = $customRevenue !== null ? ($customRevenue - $knownCost) / $customRevenue : null;

        return [
            'current_revenue_brl' => $currentRevenue,
            'known_cost_brl' => $knownCost,
            'target_margin_rate' => $target,
            'recommended_revenue_brl' => $recommendedRevenue,
            'ai_cost_share_rate' => $aiShare,
            'avoidance_rate' => $avoidanceRate,
            'recommendation' => $recommendation,
            'recommendation_message' => $message,
            'plans' => $plans,
            'usage' => $usage,
            'custom_revenue_brl' => $customRevenue,
            'custom_margin_rate' => $customMargin,
            'custom_meets_margin' => $customMargin !== null && $customMargin >= $target,
            'current_plan_key' => (string) ($commercial['subscription']['plan_key'] ?? ''),
            'current_plan_name' => (string) ($commercial['subscription']['plan_name'] ?? 'Sem plano'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function snapshot(int $tenantId, string $monthKey): ?array
    {
        try {
            $statement = Database::connection()->prepare('SELECT * FROM tenant_ai_profitability_snapshots WHERE tenant_id = :tenant_id AND period_month = :period_month LIMIT 1');
            $statement->execute(['tenant_id' => $tenantId, 'period_month' => $monthKey]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed> $row */
    private function persistSnapshot(array $row): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO tenant_ai_profitability_snapshots
                    (tenant_id, period_month, revenue_brl, revenue_source, revenue_quality, ai_cost_usd, usd_brl_rate,
                     ai_cost_brl, other_cost_brl, known_cost_brl, contribution_brl, margin_percent, target_margin_percent,
                     provider_calls, avoided_calls, total_tokens, ai_conversations, plan_id, plan_key, plan_name,
                     subscription_id, source_json, calculated_at)
                 VALUES
                    (:tenant_id, :period_month, :revenue_brl, :revenue_source, :revenue_quality, :ai_cost_usd, :usd_brl_rate,
                     :ai_cost_brl, :other_cost_brl, :known_cost_brl, :contribution_brl, :margin_percent, :target_margin_percent,
                     :provider_calls, :avoided_calls, :total_tokens, :ai_conversations, :plan_id, :plan_key, :plan_name,
                     :subscription_id, :source_json, NOW())
                 ON DUPLICATE KEY UPDATE
                    revenue_brl = VALUES(revenue_brl), revenue_source = VALUES(revenue_source), revenue_quality = VALUES(revenue_quality),
                    ai_cost_usd = VALUES(ai_cost_usd), usd_brl_rate = VALUES(usd_brl_rate), ai_cost_brl = VALUES(ai_cost_brl),
                    other_cost_brl = VALUES(other_cost_brl), known_cost_brl = VALUES(known_cost_brl), contribution_brl = VALUES(contribution_brl),
                    margin_percent = VALUES(margin_percent), target_margin_percent = VALUES(target_margin_percent), provider_calls = VALUES(provider_calls),
                    avoided_calls = VALUES(avoided_calls), total_tokens = VALUES(total_tokens), ai_conversations = VALUES(ai_conversations),
                    plan_id = VALUES(plan_id), plan_key = VALUES(plan_key), plan_name = VALUES(plan_name), subscription_id = VALUES(subscription_id),
                    source_json = VALUES(source_json), calculated_at = NOW()'
            )->execute([
                'tenant_id' => (int) ($row['tenant_id'] ?? 0),
                'period_month' => (string) ($row['period_month'] ?? ''),
                'revenue_brl' => (float) ($row['revenue_brl'] ?? 0),
                'revenue_source' => (string) ($row['revenue_source'] ?? 'unknown'),
                'revenue_quality' => (string) ($row['revenue_quality'] ?? 'missing'),
                'ai_cost_usd' => (float) ($row['ai_cost_usd'] ?? 0),
                'usd_brl_rate' => (float) ($row['usd_brl_rate'] ?? 0),
                'ai_cost_brl' => (float) ($row['ai_cost_brl'] ?? 0),
                'other_cost_brl' => (float) ($row['other_cost_brl'] ?? 0),
                'known_cost_brl' => (float) ($row['known_cost_brl'] ?? 0),
                'contribution_brl' => (float) ($row['contribution_brl'] ?? 0),
                'margin_percent' => $row['margin_percent'] ?? null,
                'target_margin_percent' => (float) ($row['target_margin_percent'] ?? 60),
                'provider_calls' => (int) ($row['provider_calls'] ?? 0),
                'avoided_calls' => (int) ($row['avoided_calls'] ?? 0),
                'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                'ai_conversations' => (int) ($row['ai_conversations'] ?? 0),
                'plan_id' => $row['plan_id'] ?? null,
                'plan_key' => (string) ($row['plan_key'] ?? ''),
                'plan_name' => (string) ($row['plan_name'] ?? ''),
                'subscription_id' => $row['subscription_id'] ?? null,
                'source_json' => json_encode($row['source'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        } catch (Throwable) {
            // Antes da migration 084, a análise atual continua funcionando sem persistir histórico.
        }
    }

    /** @return array<string,mixed> */
    private function normalizeSnapshot(array $row): array
    {
        $month = new DateTimeImmutable((string) ($row['period_month'] ?? 'first day of this month'));
        $marginPercent = $row['margin_percent'] !== null ? (float) $row['margin_percent'] : null;
        return [
            'tenant_id' => (int) ($row['tenant_id'] ?? 0),
            'period_month' => $month->format('Y-m-01'),
            'label' => $this->monthLabel($month),
            'revenue_brl' => (float) ($row['revenue_brl'] ?? 0),
            'revenue_source' => (string) ($row['revenue_source'] ?? 'unknown'),
            'revenue_quality' => (string) ($row['revenue_quality'] ?? 'missing'),
            'ai_cost_usd' => (float) ($row['ai_cost_usd'] ?? 0),
            'usd_brl_rate' => (float) ($row['usd_brl_rate'] ?? 0),
            'ai_cost_brl' => (float) ($row['ai_cost_brl'] ?? 0),
            'other_cost_brl' => (float) ($row['other_cost_brl'] ?? 0),
            'known_cost_brl' => (float) ($row['known_cost_brl'] ?? 0),
            'contribution_brl' => (float) ($row['contribution_brl'] ?? 0),
            'margin_rate' => $marginPercent !== null ? $marginPercent / 100 : null,
            'margin_percent' => $marginPercent,
            'target_margin_percent' => (float) ($row['target_margin_percent'] ?? 60),
            'provider_calls' => (int) ($row['provider_calls'] ?? 0),
            'avoided_calls' => (int) ($row['avoided_calls'] ?? 0),
            'total_tokens' => (int) ($row['total_tokens'] ?? 0),
            'ai_conversations' => (int) ($row['ai_conversations'] ?? 0),
            'plan_id' => $row['plan_id'] !== null ? (int) $row['plan_id'] : null,
            'plan_key' => (string) ($row['plan_key'] ?? ''),
            'plan_name' => (string) ($row['plan_name'] ?? 'Sem plano'),
            'subscription_id' => $row['subscription_id'] !== null ? (int) $row['subscription_id'] : null,
            'source' => json_decode((string) ($row['source_json'] ?? '{}'), true) ?: [],
            'calculated_at' => (string) ($row['calculated_at'] ?? ''),
        ];
    }

    /** @return array<string,mixed> */
    private function policyForPeriod(int $tenantId, DateTimeImmutable $end): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT * FROM tenant_ai_commercial_policy_history
                 WHERE tenant_id = :tenant_id AND effective_at <= :effective_at
                 ORDER BY effective_at DESC, id DESC LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId, 'effective_at' => $end->format('Y-m-d H:i:s')]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['_source'] = 'history';
                return $row;
            }
        } catch (Throwable) {
        }
        $row = (new AiCommercialMarginService())->policy($tenantId);
        $row['_source'] = 'current_policy_estimate';
        return $row;
    }

    /** @return array<string,mixed> */
    private function subscriptionForPeriod(int $tenantId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $defaults = [
            'subscription_id' => 0,
            'plan_id' => 0,
            'plan_key' => '',
            'plan_name' => 'Sem plano',
            'amount' => 0.0,
            'billing_cycle' => 'monthly',
            'monthly_equivalent_brl' => 0.0,
        ];
        try {
            $statement = Database::connection()->prepare(
                'SELECT ts.id subscription_id, ts.plan_id, ts.amount, ts.billing_cycle, ts.billing_status,
                        ts.starts_at, ts.cancel_at, sp.plan_key, sp.name plan_name
                 FROM tenant_subscriptions ts
                 INNER JOIN saas_plans sp ON sp.id = ts.plan_id
                 WHERE ts.tenant_id = :tenant_id
                   AND ts.starts_at <= :period_end
                   AND (ts.cancel_at IS NULL OR ts.cancel_at >= :period_start)
                 ORDER BY ts.starts_at DESC, ts.id DESC LIMIT 1'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'period_start' => $start->format('Y-m-d'),
                'period_end' => $end->format('Y-m-d'),
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$row) return $defaults;
            $amount = max(0.0, (float) ($row['amount'] ?? 0));
            $months = $this->billingCycleMonths((string) ($row['billing_cycle'] ?? 'monthly'));
            return array_merge($defaults, $row, ['monthly_equivalent_brl' => $months > 0 ? $amount / $months : $amount]);
        } catch (Throwable) {
            return $defaults;
        }
    }

    /** @return array{revenue_brl:float,source:string,quality:string} */
    private function revenueForPeriod(int $tenantId, DateTimeImmutable $start, DateTimeImmutable $end, array $policy, array $subscription): array
    {
        if ((string) ($policy['revenue_source'] ?? 'subscription') === 'manual' && (float) ($policy['monthly_revenue_brl'] ?? 0) > 0) {
            return [
                'revenue_brl' => max(0.0, (float) $policy['monthly_revenue_brl']),
                'source' => 'manual_policy',
                'quality' => (string) ($policy['_source'] ?? '') === 'history' ? 'contracted' : 'estimated',
            ];
        }

        $invoice = $this->invoiceRevenueForPeriod($tenantId, $start, $end);
        if ($invoice['revenue_brl'] > 0) return $invoice;

        $subscriptionRevenue = max(0.0, (float) ($subscription['monthly_equivalent_brl'] ?? 0));
        if ($subscriptionRevenue > 0) {
            return ['revenue_brl' => $subscriptionRevenue, 'source' => 'subscription', 'quality' => 'contracted'];
        }
        return ['revenue_brl' => 0.0, 'source' => 'unknown', 'quality' => 'missing'];
    }

    /** @return array{revenue_brl:float,source:string,quality:string} */
    private function invoiceRevenueForPeriod(int $tenantId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        try {
            $statement = Database::connection()->prepare(
                'SELECT amount, period_start, period_end, status
                 FROM tenant_invoices
                 WHERE tenant_id = :tenant_id
                   AND status <> "cancelled"
                   AND period_start <= :period_end
                   AND period_end >= :period_start'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'period_start' => $start->format('Y-m-d'),
                'period_end' => $end->format('Y-m-d'),
            ]);
            $revenue = 0.0;
            $allPaid = true;
            $found = false;
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $invoice) {
                $found = true;
                if ((string) ($invoice['status'] ?? '') !== 'paid') $allPaid = false;
                $invoiceStart = new DateTimeImmutable((string) $invoice['period_start']);
                $invoiceEnd = new DateTimeImmutable((string) $invoice['period_end']);
                $overlapStart = $invoiceStart > $start ? $invoiceStart : $start;
                $overlapEnd = $invoiceEnd < $end ? $invoiceEnd : $end;
                if ($overlapEnd < $overlapStart) continue;
                $invoiceDays = max(1, (int) $invoiceStart->diff($invoiceEnd)->format('%a') + 1);
                $overlapDays = max(0, (int) $overlapStart->diff($overlapEnd)->format('%a') + 1);
                $revenue += max(0.0, (float) ($invoice['amount'] ?? 0)) * ($overlapDays / $invoiceDays);
            }
            if ($found && $revenue > 0) {
                return ['revenue_brl' => round($revenue, 2), 'source' => 'invoice_allocated', 'quality' => $allPaid ? 'actual' : 'contracted'];
            }
        } catch (Throwable) {
        }
        return ['revenue_brl' => 0.0, 'source' => 'none', 'quality' => 'missing'];
    }

    /** @return array{cost_usd:float,provider_calls:int,avoided_calls:int,total_tokens:int,ai_conversations:int} */
    private function aiUsageForPeriod(int $tenantId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $defaults = ['cost_usd' => 0.0, 'provider_calls' => 0, 'avoided_calls' => 0, 'total_tokens' => 0, 'ai_conversations' => 0];
        try {
            $statement = Database::connection()->prepare(
                'SELECT
                    COALESCE(SUM(CASE WHEN credential_owner = "rs_connect" AND COALESCE(provider_calls,0) > 0 AND estimated_cost_currency = "USD" THEN estimated_cost ELSE 0 END),0) AS cost_usd,
                    COALESCE(SUM(CASE WHEN credential_owner = "rs_connect" THEN provider_calls ELSE 0 END),0) AS provider_calls,
                    COALESCE(SUM(provider_calls_avoided),0) AS avoided_calls,
                    COALESCE(SUM(CASE WHEN credential_owner = "rs_connect" THEN total_tokens ELSE 0 END),0) AS total_tokens,
                    COUNT(DISTINCT CASE WHEN credential_owner = "rs_connect" AND COALESCE(provider_calls,0) > 0 THEN conversation_id ELSE NULL END) AS ai_conversations
                 FROM ai_usage_events
                 WHERE tenant_id = :tenant_id
                   AND created_at BETWEEN :start_at AND :end_at'
            );
            $statement->execute([
                'tenant_id' => $tenantId,
                'start_at' => $start->format('Y-m-d H:i:s'),
                'end_at' => $end->format('Y-m-d H:i:s'),
            ]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            return [
                'cost_usd' => (float) ($row['cost_usd'] ?? 0),
                'provider_calls' => (int) ($row['provider_calls'] ?? 0),
                'avoided_calls' => (int) ($row['avoided_calls'] ?? 0),
                'total_tokens' => (int) ($row['total_tokens'] ?? 0),
                'ai_conversations' => (int) ($row['ai_conversations'] ?? 0),
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    /** @return array{ok:bool,issues:array<int,string>} */
    private function capacityCheck(array $limits, array $usage): array
    {
        $issues = [];
        $keys = ['users', 'instances', 'agents', 'n8n_flows', 'contacts_month', 'conversations_month', 'appointments_month', 'crm_leads_month'];
        foreach ($keys as $key) {
            $limit = $limits[$key] ?? null;
            if ($limit === null || $limit === '' || !is_numeric($limit)) continue;
            if ((int) ($usage[$key] ?? 0) > (int) $limit) {
                $issues[] = (SubscriptionService::LIMIT_LABELS[$key] ?? $key) . ': uso ' . (int) ($usage[$key] ?? 0) . ' > limite ' . (int) $limit;
            }
        }
        $aiLimit = $limits['ai_interactions_month'] ?? $limits['ai_replies_month'] ?? $limits['messages_month'] ?? null;
        if ($aiLimit !== null && $aiLimit !== '' && is_numeric($aiLimit) && (int) ($usage['ai_interactions_month'] ?? 0) > (int) $aiLimit) {
            $issues[] = 'Franquia de IA RS/mês: uso ' . (int) ($usage['ai_interactions_month'] ?? 0) . ' > limite ' . (int) $aiLimit;
        }
        return ['ok' => $issues === [], 'issues' => $issues];
    }

    private function billingCycleMonths(string $cycle): int
    {
        return match ($cycle) {
            'quarterly' => 3,
            'semiannual' => 6,
            'annual' => 12,
            default => 1,
        };
    }

    private function deltaRate(float $previous, float $current): ?float
    {
        if (abs($previous) < 0.000001) return abs($current) < 0.000001 ? 0.0 : null;
        return ($current - $previous) / abs($previous);
    }

    private function monthLabel(DateTimeImmutable $month): string
    {
        $names = [1 => 'Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];
        return ($names[(int) $month->format('n')] ?? $month->format('m')) . '/' . $month->format('y');
    }
}
