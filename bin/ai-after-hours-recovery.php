#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\AfterHoursMonitorService;

try {
    $result = (new AfterHoursMonitorService())->run(false, 'cli_after_hours_monitor');
    echo json_encode(['ok' => true, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(in_array((string) ($result['status'] ?? ''), ['error'], true) ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
