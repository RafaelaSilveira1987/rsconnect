<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/app/Controllers/MobileApiController.php') ?: '';
$routes = file_get_contents($root . '/routes/web.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'controller messages action' => str_contains($controller, 'function conversationMessages()'),
    'controller read action' => str_contains($controller, 'function markConversationRead()'),
    'messages route' => str_contains($routes, "'/mobile/conversations/messages'"),
    'read route' => str_contains($routes, "'/mobile/conversations/read'"),
    'list does not eagerly load all messages' => !str_contains(substr($controller, strpos($controller, 'public function conversations()'), strpos($controller, 'public function conversationMessages()') - strpos($controller, 'public function conversations()')), 'FROM conversation_messages'),
    'version label' => str_contains($version, '36.36.3'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FAILED: " . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK mobile-api-v36363-smoke" . PHP_EOL;
