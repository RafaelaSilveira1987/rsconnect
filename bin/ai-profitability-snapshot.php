#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\AiProfitabilityHistoryService;

$started = microtime(true);
try {
    $rawMonth = trim((string) ($argv[1] ?? ''));
    $month = $rawMonth !== '' ? new \DateTimeImmutable($rawMonth . '-01') : new \DateTimeImmutable('first day of this month');
    $month = $month->modify('first day of this month')->setTime(0, 0, 0);
    $service = new AiProfitabilityHistoryService();
    $rows = [];
    foreach ($service->tenantOptions() as $tenant) {
        $tenantId = (int) ($tenant['id'] ?? 0);
        if ($tenantId < 1) continue;
        $metrics = $service->monthMetrics($tenantId, $month, true);
        $rows[] = [
            'tenant_id' => $tenantId,
            'tenant_name' => (string) ($tenant['name'] ?? ''),
            'period_month' => (string) ($metrics['period_month'] ?? ''),
            'revenue_brl' => (float) ($metrics['revenue_brl'] ?? 0),
            'ai_cost_brl' => (float) ($metrics['ai_cost_brl'] ?? 0),
            'margin_percent' => $metrics['margin_percent'] ?? null,
        ];
    }
    echo json_encode([
        'ok' => true,
        'period_month' => $month->format('Y-m-01'),
        'tenants' => count($rows),
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'rows' => $rows,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}
