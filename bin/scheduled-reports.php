#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\ScheduledReportService;

$started = microtime(true);
try {
    $limit = isset($argv[1]) ? max(1, min(100, (int) $argv[1])) : 20;
    $result = (new ScheduledReportService())->runDue($limit);
    echo json_encode([
        'ok' => true,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
