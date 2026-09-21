<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/app/Controllers/MobileApiController.php') ?: '';
$routes = file_get_contents($root . '/routes/web.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$docker = file_get_contents($root . '/Dockerfile') ?: '';

$checks = [
    'context action' => str_contains($controller, 'function conversationContext()'),
    'assignment action' => str_contains($controller, 'function conversationAssignment()'),
    'department action' => str_contains($controller, 'function conversationDepartment()'),
    'ownership service reused' => str_contains($controller, 'ConversationOwnershipService'),
    'safe mode action' => str_contains($controller, 'changeAssignment($pdo, $conversationId, null, \'release\')'),
    'authorization fallback' => str_contains($controller, 'REDIRECT_HTTP_AUTHORIZATION') && str_contains($controller, 'getallheaders'),
    'context route' => str_contains($routes, "'/mobile/conversations/context'"),
    'assignment route' => str_contains($routes, "'/mobile/conversations/assignment'"),
    'department route' => str_contains($routes, "'/mobile/conversations/department'"),
    'mobile login alias' => str_contains($routes, "'/mobile/auth/login'"),
    'docker forwards authorization' => str_contains($docker, 'SetEnvIf Authorization'),
    'version label' => str_contains($version, '36.36.4') || str_contains($version, '36.36.5'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FAILED: " . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK mobile-api-v36364-smoke" . PHP_EOL;
