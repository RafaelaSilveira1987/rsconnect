<?php

declare(strict_types=1);

use App\Services\AiReprocessService;

require_once dirname(__DIR__) . '/bootstrap.php';

try {
    $result = (new AiReprocessService())->runScheduledIfDue();
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
