<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\TenantHealthService;

$result = (new TenantHealthService())->runAll(null, 'cron');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(empty($result['errors']) ? 0 : 1);
