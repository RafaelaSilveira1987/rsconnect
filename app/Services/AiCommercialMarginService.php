<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Traduz custo técnico de IA em visão comercial por empresa.
 *
 * A margem exibida é uma margem de contribuição conhecida: receita de referência
 * menos custo projetado de IA RS e demais custos mensais informados. Ela não é
 * lucro líquido e não substitui a contabilidade da operação.
 */
final class AiCommercialMarginService
{
    public const REVENUE_SOURCES = ['subscription', 'manual'];

    /** @return array<string,mixed> */
    public function policy(int $tenantId): array
    {
        $defaults = [
            'tenant_id' => $tenantId,
            'enabled' => 1,
            'revenue_source' => 'subscription',
            'monthly_revenue_brl' => null,
            'other_monthly_cost_brl' => 0,
            'target_margin_percent' => 60,
            'warning_margin_percent' => 40,
            'usd_brl_rate' => null,
        ];
        if ($tenantId < 1) {
            return $defaults;
        }

        try {
            $statement = Database::connection()->prepare('SELECT * FROM tenant_ai_commercial_policies WHERE tenant_id = :tenant_id LIMIT 1');
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
            throw new RuntimeException('Empresa inválida para configurar a margem comercial de IA.');
        }

        $pdo = Database::connection();
        $check = $pdo->prepare('SELECT id FROM tenants WHERE id = :id LIMIT 1');
        $check->execute(['id' => $tenantId]);
        if (!$check->fetchColumn()) {
            throw new RuntimeException('Empresa não encontrada.');
        }

        $enabled = !empty($data['enabled']) ? 1 : 0;
        $source = strtolower(trim((string) ($data['revenue_source'] ?? 'subscription')));
        if (!in_array($source, self::REVENUE_SOURCES, true)) {
            $source = 'subscription';
        }

        $manualRevenue = $this->moneyOrNull($data['monthly_revenue_brl'] ?? null);
        if ($source === 'manual' && ($manualRevenue === null || $manualRevenue <= 0)) {
            throw new RuntimeException('Informe a receita mensal de referência quando a origem manual estiver selecionada.');
        }

        $otherCost = max(0.0, (float) ($this->moneyOrNull($data['other_monthly_cost_brl'] ?? null) ?? 0));
        $target = max(5.0, min(95.0, $this->percent($data['target_margin_percent'] ?? 60, 60.0)));
        $warning = max(-100.0, min($target - 1.0, $this->percent($data['warning_margin_percent'] ?? 40, 40.0)));
        $usdBrl = $this->moneyOrNull($data['usd_brl_rate'] ?? null);
        if ($usdBrl !== null && $usdBrl <= 0) {
            $usdBrl = null;
        }

        $pdo->prepare(
            'INSERT INTO tenant_ai_commercial_policies
                (tenant_id, enabled, revenue_source, monthly_revenue_brl, other_monthly_cost_brl,
                 target_margin_percent, warning_margin_percent, usd_brl_rate, updated_by_user_id)
             VALUES
                (:tenant_id, :enabled, :revenue_source, :monthly_revenue_brl, :other_monthly_cost_brl,
                 :target_margin_percent, :warning_margin_percent, :usd_brl_rate, :updated_by_user_id)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled), revenue_source = VALUES(revenue_source),
                monthly_revenue_brl = VALUES(monthly_revenue_brl), other_monthly_cost_brl = VALUES(other_monthly_cost_brl),
                target_margin_percent = VALUES(target_margin_percent), warning_margin_percent = VALUES(warning_margin_percent),
                usd_brl_rate = VALUES(usd_brl_rate), updated_by_user_id = VALUES(updated_by_user_id)'
        )->execute([
            'tenant_id' => $tenantId,
            'enabled' => $enabled,
            'revenue_source' => $source,
            'monthly_revenue_brl' => $manualRevenue,
            'other_monthly_cost_brl' => round($otherCost, 2),
            'target_margin_percent' => round($target, 2),
            'warning_margin_percent' => round($warning, 2),
            'usd_brl_rate' => $usdBrl !== null ? round($usdBrl, 6) : null,
            'updated_by_user_id' => $userId && $userId > 0 ? $userId : null,
        ]);

        $this->recordPolicyHistory($tenantId, [
            'enabled' => $enabled,
            'revenue_source' => $source,
            'monthly_revenue_brl' => $manualRevenue,
            'other_monthly_cost_brl' => round($otherCost, 2),
            'target_margin_percent' => round($target, 2),
            'warning_margin_percent' => round($warning, 2),
            'usd_brl_rate' => $usdBrl !== null ? round($usdBrl, 6) : null,
        ], $userId);
    }

    /** @return array<string,mixed> */
    public function analysis(int $tenantId): array
    {
        $policy = $this->policy($tenantId);
        $subscription = $this->subscription($tenantId);
        $period = (new SubscriptionService())->usagePeriodForTenant($tenantId);
        $usage = (new AiBudgetPolicyService())->costUsage($tenantId, $period);

        $globalFx = max(0.0, (float) Env::get('OPENAI_USAGE_USD_BRL', 0));
        $policyFx = $policy['usd_brl_rate'] !== null ? max(0.0, (float) $policy['usd_brl_rate']) : 0.0;
        $fx = $policyFx > 0 ? $policyFx : $globalFx;
        $revenueSource = (string) ($policy['revenue_source'] ?? 'subscription');
        $subscriptionRevenue = (float) ($subscription['monthly_equivalent_brl'] ?? 0);
        $manualRevenue = $policy['monthly_revenue_brl'] !== null ? max(0.0, (float) $policy['monthly_revenue_brl']) : 0.0;
        $revenue = $revenueSource === 'manual' ? $manualRevenue : $subscriptionRevenue;

        $currentCostUsd = max(0.0, (float) ($usage['cost_usd'] ?? 0));
        $currentCostBrl = $fx > 0 ? $currentCostUsd * $fx : 0.0;
        $today = new DateTimeImmutable('now');
        $daysInMonth = max(1, (int) $today->format('t'));
        $elapsedDays = max(1, (int) $today->format('j'));
        $projectedCostUsd = $currentCostUsd > 0 ? ($currentCostUsd / $elapsedDays) * $daysInMonth : 0.0;
        $projectedCostBrl = $fx > 0 ? $projectedCostUsd * $fx : 0.0;

        $otherCost = max(0.0, (float) ($policy['other_monthly_cost_brl'] ?? 0));
        $knownCosts = $projectedCostBrl + $otherCost;
        $contribution = $revenue - $knownCosts;
        $marginRate = $revenue > 0 ? $contribution / $revenue : null;
        $aiCostShare = $revenue > 0 ? $projectedCostBrl / $revenue : null;
        $targetMargin = max(0.05, min(0.95, ((float) ($policy['target_margin_percent'] ?? 60)) / 100));
        $warningMargin = ((float) ($policy['warning_margin_percent'] ?? 40)) / 100;
        $recommendedRevenue = $knownCosts > 0 && $targetMargin < 1 ? $knownCosts / (1 - $targetMargin) : 0.0;
        $priceGap = max(0.0, $recommendedRevenue - $revenue);

        $enabled = (int) ($policy['enabled'] ?? 1) === 1;
        $configured = $enabled && $revenue > 0 && $fx > 0;
        $status = 'unconfigured';
        if ($configured && $marginRate !== null) {
            $status = $marginRate < 0 ? 'loss' : ($marginRate < $warningMargin ? 'critical' : ($marginRate < $targetMargin ? 'attention' : 'healthy'));
        }

        return [
            'tenant_id' => $tenantId,
            'enabled' => $enabled,
            'configured' => $configured,
            'status' => $status,
            'revenue_source' => $revenueSource,
            'revenue_brl' => $revenue,
            'subscription_revenue_brl' => $subscriptionRevenue,
            'manual_revenue_brl' => $manualRevenue,
            'other_monthly_cost_brl' => $otherCost,
            'current_ai_cost_usd' => $currentCostUsd,
            'current_ai_cost_brl' => $currentCostBrl,
            'projected_ai_cost_usd' => $projectedCostUsd,
            'projected_ai_cost_brl' => $projectedCostBrl,
            'projected_known_cost_brl' => $knownCosts,
            'projected_contribution_brl' => $contribution,
            'projected_margin_rate' => $marginRate,
            'ai_cost_share_rate' => $aiCostShare,
            'target_margin_rate' => $targetMargin,
            'warning_margin_rate' => $warningMargin,
            'recommended_revenue_brl' => $recommendedRevenue,
            'price_gap_brl' => $priceGap,
            'usd_brl_rate' => $fx,
            'fx_source' => $policyFx > 0 ? 'tenant' : ($globalFx > 0 ? 'environment' : 'missing'),
            'provider_calls' => (int) ($usage['provider_calls'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'projection_days_elapsed' => $elapsedDays,
            'projection_days_in_month' => $daysInMonth,
            'subscription' => $subscription,
            'policy' => $policy,
            'note' => 'Margem de contribuição conhecida: receita de referência menos custo projetado de IA RS e demais custos mensais informados. Não representa lucro líquido.',
        ];
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
                $row = $this->analysis($tenantId);
                $row['tenant_name'] = (string) ($tenant['name'] ?? ('Empresa #' . $tenantId));
                $rows[] = $row;
            }

            $weight = ['loss' => 0, 'critical' => 1, 'attention' => 2, 'unconfigured' => 3, 'healthy' => 4];
            usort($rows, static function (array $a, array $b) use ($weight): int {
                $sa = $weight[(string) ($a['status'] ?? 'unconfigured')] ?? 3;
                $sb = $weight[(string) ($b['status'] ?? 'unconfigured')] ?? 3;
                if ($sa !== $sb) return $sa <=> $sb;
                $ma = $a['projected_margin_rate'] === null ? 9.0 : (float) $a['projected_margin_rate'];
                $mb = $b['projected_margin_rate'] === null ? 9.0 : (float) $b['projected_margin_rate'];
                return $ma <=> $mb;
            });
            return $rows;
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string,mixed> */
    private function subscription(int $tenantId): array
    {
        $defaults = [
            'subscription_id' => 0,
            'plan_name' => 'Sem plano',
            'plan_key' => '',
            'billing_cycle' => 'monthly',
            'billing_status' => '',
            'amount_brl' => 0.0,
            'monthly_equivalent_brl' => 0.0,
        ];
        if ($tenantId < 1) return $defaults;

        try {
            $statement = Database::connection()->prepare(
                'SELECT ts.id subscription_id, ts.amount, ts.billing_cycle, ts.billing_status,
                        sp.name plan_name, sp.plan_key
                 FROM tenant_subscriptions ts
                 INNER JOIN saas_plans sp ON sp.id = ts.plan_id
                 WHERE ts.tenant_id = :tenant_id
                   AND ts.billing_status IN ("trialing","active","overdue","suspended")
                 ORDER BY FIELD(ts.billing_status,"active","trialing","overdue","suspended"), ts.id DESC
                 LIMIT 1'
            );
            $statement->execute(['tenant_id' => $tenantId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$row) return $defaults;
            $amount = max(0.0, (float) ($row['amount'] ?? 0));
            $cycle = (string) ($row['billing_cycle'] ?? 'monthly');
            $months = match ($cycle) {
                'quarterly' => 3,
                'semiannual' => 6,
                'annual' => 12,
                default => 1,
            };
            return [
                'subscription_id' => (int) ($row['subscription_id'] ?? 0),
                'plan_name' => (string) ($row['plan_name'] ?? 'Sem plano'),
                'plan_key' => (string) ($row['plan_key'] ?? ''),
                'billing_cycle' => $cycle,
                'billing_status' => (string) ($row['billing_status'] ?? ''),
                'amount_brl' => $amount,
                'monthly_equivalent_brl' => $months > 0 ? $amount / $months : $amount,
            ];
        } catch (Throwable) {
            return $defaults;
        }
    }

    /** @param array<string,mixed> $policy */
    private function recordPolicyHistory(int $tenantId, array $policy, ?int $userId): void
    {
        try {
            Database::connection()->prepare(
                'INSERT INTO tenant_ai_commercial_policy_history
                    (tenant_id, effective_at, enabled, revenue_source, monthly_revenue_brl, other_monthly_cost_brl,
                     target_margin_percent, warning_margin_percent, usd_brl_rate, changed_by_user_id, source)
                 VALUES
                    (:tenant_id, NOW(), :enabled, :revenue_source, :monthly_revenue_brl, :other_monthly_cost_brl,
                     :target_margin_percent, :warning_margin_percent, :usd_brl_rate, :changed_by_user_id, "user")'
            )->execute([
                'tenant_id' => $tenantId,
                'enabled' => (int) ($policy['enabled'] ?? 1),
                'revenue_source' => (string) ($policy['revenue_source'] ?? 'subscription'),
                'monthly_revenue_brl' => $policy['monthly_revenue_brl'] ?? null,
                'other_monthly_cost_brl' => (float) ($policy['other_monthly_cost_brl'] ?? 0),
                'target_margin_percent' => (float) ($policy['target_margin_percent'] ?? 60),
                'warning_margin_percent' => (float) ($policy['warning_margin_percent'] ?? 40),
                'usd_brl_rate' => $policy['usd_brl_rate'] ?? null,
                'changed_by_user_id' => $userId && $userId > 0 ? $userId : null,
            ]);
        } catch (Throwable) {
            // Compatibilidade durante deploy: a política atual continua sendo salva antes da migration 084.
        }
    }

    private function moneyOrNull(mixed $value): ?float
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return null;
        $raw = str_replace(['R$', 'US$', ' '], '', $raw);
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }
        return is_numeric($raw) ? round((float) $raw, 6) : null;
    }

    private function percent(mixed $value, float $default): float
    {
        $raw = str_replace(',', '.', trim((string) ($value ?? '')));
        return is_numeric($raw) ? (float) $raw : $default;
    }
}
