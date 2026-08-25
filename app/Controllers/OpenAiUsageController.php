<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Env;
use App\Core\View;
use App\Services\AiEfficiencyDashboardService;
use App\Services\OpenAiOrganizationUsageService;
use DateTimeImmutable;

final class OpenAiUsageController
{
    public function index(): void
    {
        $period = (string) ($_GET['usage_period'] ?? 'month');
        $forceRefresh = isset($_GET['refresh_usage']) && (string) $_GET['refresh_usage'] === '1';
        $tenantId = max(0, (int) ($_GET['tenant_id'] ?? 0));
        $agentId = max(0, (int) ($_GET['agent_id'] ?? 0));

        $usage = (new OpenAiOrganizationUsageService())->dashboard($period, $forceRefresh);
        $efficiency = (new AiEfficiencyDashboardService())->dashboard($period, $tenantId, $agentId);
        $usage['insights'] = $this->insights($usage, $efficiency);

        View::render('openai_usage.index', [
            'title' => 'Consumo OpenAI',
            'openAiUsage' => $usage,
            'aiEfficiency' => $efficiency,
        ]);
    }

    /** @return array<string,mixed> */
    private function insights(array $usage, array $efficiency): array
    {
        $officialCost = (float) ($usage['totals']['cost'] ?? 0);
        $officialTokens = (int) ($usage['totals']['total_tokens'] ?? 0);
        $internalOpenAiTokens = (int) ($efficiency['totals']['openai_tokens'] ?? 0);
        $budget = max(0.0, (float) Env::get('OPENAI_MONTHLY_BUDGET_USD', 0));
        $usdBrl = max(0.0, (float) Env::get('OPENAI_USAGE_USD_BRL', 0));

        $projection = $officialCost;
        if ($officialCost > 0) {
            $today = new DateTimeImmutable('now');
            $daysInMonth = max(1, (int) $today->format('t'));
            $sampleDays = match ((string) ($usage['period'] ?? 'month')) {
                '7d' => 7,
                '30d' => 30,
                default => max(1, (int) $today->format('j')),
            };
            $projection = ($officialCost / $sampleDays) * $daysInMonth;
        }

        $coverage = $officialTokens > 0 ? min(1.0, $internalOpenAiTokens / $officialTokens) : 0.0;
        $untracked = max(0, $officialTokens - $internalOpenAiTokens);
        $budgetUsed = $budget > 0 ? $officialCost / $budget : 0.0;

        $dailyRows = is_array($usage['daily'] ?? null) ? $usage['daily'] : [];
        $dailyTokenValues = array_values(array_filter(array_map(static fn (array $row): int => max(0, (int) ($row['total_tokens'] ?? 0)), $dailyRows), static fn (int $value): bool => $value > 0));
        $dailyAverage = $dailyTokenValues !== [] ? array_sum($dailyTokenValues) / count($dailyTokenValues) : 0.0;
        $peakTokens = $dailyTokenValues !== [] ? max($dailyTokenValues) : 0;
        $tokenSpike = count($dailyTokenValues) >= 4 && $dailyAverage > 0 && $peakTokens >= ($dailyAverage * 1.8);

        $internalCost = (float) ($efficiency['totals']['estimated_cost'] ?? 0);
        $topAgent = is_array($efficiency['agents'][0] ?? null) ? $efficiency['agents'][0] : [];
        $topAgentCost = (float) ($topAgent['estimated_cost'] ?? 0);
        $agentConcentration = $internalCost > 0 ? $topAgentCost / $internalCost : 0.0;

        return [
            'monthly_budget_usd' => $budget,
            'budget_used_rate' => $budgetUsed,
            'projected_cost_usd' => $projection,
            'projected_budget_rate' => $budget > 0 ? $projection / $budget : 0.0,
            'usd_brl' => $usdBrl,
            'cost_brl' => $usdBrl > 0 ? $officialCost * $usdBrl : null,
            'projected_cost_brl' => $usdBrl > 0 ? $projection * $usdBrl : null,
            'internal_openai_tokens' => $internalOpenAiTokens,
            'official_openai_tokens' => $officialTokens,
            'tracking_coverage_rate' => $coverage,
            'untracked_tokens' => $untracked,
            'alert_level' => $budget <= 0 ? 'none' : ($budgetUsed >= 1 ? 'critical' : ($budgetUsed >= .9 ? 'danger' : ($budgetUsed >= .8 ? 'warning' : 'ok'))),
            'token_spike' => $tokenSpike,
            'daily_token_average' => (int) round($dailyAverage),
            'peak_daily_tokens' => $peakTokens,
            'agent_concentration_rate' => $agentConcentration,
            'top_agent_name' => (string) ($topAgent['agent_name'] ?? ''),
        ];
    }
}
