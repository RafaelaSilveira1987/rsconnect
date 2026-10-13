#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Services\BillingReminderService;
use App\Services\OperationsService;
use App\Services\SubscriptionLifecycleReminderService;

try {
    $billing = (new BillingReminderService())->runDueReminders('cron');
    $lifecycle = (new SubscriptionLifecycleReminderService())->runDueReminders('cron');
    try {
        (new OperationsService())->refreshBillingCronCheck();
    } catch (Throwable) {
    }

    echo json_encode([
        'ok' => true,
        'billing' => $billing,
        'lifecycle' => $lifecycle,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit((!empty($billing['errors']) || !empty($lifecycle['errors'])) ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
