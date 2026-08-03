#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\OperationsService;

$started = microtime(true);
try {
    $service = new OperationsService();
    $service->runChecks(true, 'cli');
    $dashboard = $service->dashboard();
    echo json_encode([
        'ok' => true,
        'checked_at' => \App\Core\Clock::nowUtc(),
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'summary' => $dashboard['summary'] ?? [],
        'overall' => $dashboard['overall'] ?? [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
