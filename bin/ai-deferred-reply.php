<?php

declare(strict_types=1);

use App\Services\AiAutomationService;

// Evita abrir sessão HTTP em execução CLI.
$_SERVER['REQUEST_URI'] = '/health/live';
require_once dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['tenant:', 'conversation:', 'message:', 'wait::']);
$tenantId = max(0, (int) ($options['tenant'] ?? 0));
$conversationId = max(0, (int) ($options['conversation'] ?? 0));
$messageId = max(0, (int) ($options['message'] ?? 0));
$waitSeconds = max(0, min(3600, (int) ($options['wait'] ?? 0)));

if ($tenantId < 1 || $conversationId < 1 || $messageId < 1) {
    fwrite(STDERR, "Parâmetros inválidos para a retomada da IA.\n");
    exit(2);
}

try {
    $result = (new AiAutomationService())->resumeDeferredIncoming(
        $tenantId,
        $conversationId,
        $messageId,
        $waitSeconds
    );
    echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $exception) {
    $logDir = dirname(__DIR__) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    error_log(
        '[' . date('Y-m-d H:i:s') . '] deferred-ai: ' . $exception . PHP_EOL,
        3,
        $logDir . '/app.log'
    );
    fwrite(STDERR, json_encode([
        'ok' => false,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
