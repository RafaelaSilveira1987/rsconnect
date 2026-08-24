<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\CalendarGoogleLifecycleService;

$tenantId = isset($argv[1]) && (int) $argv[1] > 0 ? (int) $argv[1] : null;
$result = (new CalendarGoogleLifecycleService())->runMaintenance($tenantId, 'cli');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(!empty($result['ok']) ? 0 : 1);
