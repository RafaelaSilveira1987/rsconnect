<?php

declare(strict_types=1);

use App\Services\ReportingAggregationService;

require_once dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['start::', 'end::', 'tenant::']);
$start = (string) ($options['start'] ?? date('Y-m-d', strtotime('-29 days')));
$end = (string) ($options['end'] ?? date('Y-m-d'));
$tenantId = isset($options['tenant']) && (int) $options['tenant'] > 0 ? (int) $options['tenant'] : null;

$service = new ReportingAggregationService();
if (!$service->isAvailable()) {
    fwrite(STDERR, "A tabela report_daily_metrics não existe. Execute a migration 048 primeiro.\n");
    exit(2);
}

try {
    $service->rebuildRange($tenantId, $start, $end);
    $totals = $service->totals($tenantId, $start, $end);
    echo json_encode([
        'ok' => true,
        'tenant_id' => $tenantId,
        'start' => $start,
        'end' => $end,
        'messages' => (int) (($totals['messages_incoming'] ?? 0) + ($totals['messages_outgoing'] ?? 0)),
        'conversations' => (int) ($totals['conversations_started'] ?? 0),
        'contacts' => (int) ($totals['contacts_new'] ?? 0),
        'warnings' => $service->warnings(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . PHP_EOL);
    exit(1);
}
