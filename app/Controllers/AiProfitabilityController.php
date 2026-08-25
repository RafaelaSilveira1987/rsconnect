<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Router;
use App\Core\View;
use App\Services\AiProfitabilityHistoryService;

final class AiProfitabilityController
{
    public function index(): void
    {
        $tenantId = max(0, (int) ($_GET['tenant_id'] ?? 0));
        $months = (int) ($_GET['months'] ?? 6);
        if (!in_array($months, [3, 6, 12, 24], true)) $months = 6;
        $refresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
        $simulatedRevenue = $this->moneyOrNull($_GET['simulated_revenue_brl'] ?? null);

        $service = new AiProfitabilityHistoryService();
        $portfolio = $service->portfolio();
        $portfolioHistory = $service->portfolioHistory(min(12, $months));
        $selected = $tenantId > 0
            ? $service->tenantDashboard($tenantId, $months, $simulatedRevenue, $refresh)
            : [];

        View::render('ai_profitability.index', [
            'title' => 'Resultados por cliente',
            'tenantOptions' => $service->tenantOptions(),
            'selectedTenantId' => $tenantId,
            'historyMonths' => $months,
            'portfolioProfitability' => $portfolio,
            'portfolioProfitabilityHistory' => $portfolioHistory,
            'selectedProfitability' => $selected,
            'simulatedRevenueBrl' => $simulatedRevenue,
            'refreshUrl' => Router::url('/ai-profitability') . '?' . http_build_query(array_filter([
                'tenant_id' => $tenantId ?: null,
                'months' => $months,
                'simulated_revenue_brl' => $simulatedRevenue,
                'refresh' => 1,
            ], static fn (mixed $value): bool => $value !== null && $value !== '')),
        ]);
    }

    private function moneyOrNull(mixed $value): ?float
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') return null;
        $raw = str_replace(['R$', ' '], '', $raw);
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (str_contains($raw, ',')) {
            $raw = str_replace(',', '.', $raw);
        }
        return is_numeric($raw) ? max(0.0, round((float) $raw, 2)) : null;
    }
}
