#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\NotificationDeliveryService;

try {
    $limit = isset($argv[1]) ? max(1, min(200, (int) $argv[1])) : 50;
    $result = (new NotificationDeliveryService())->process($limit);
    echo json_encode(['ok' => true, 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit((int) ($result['failed'] ?? 0) > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
