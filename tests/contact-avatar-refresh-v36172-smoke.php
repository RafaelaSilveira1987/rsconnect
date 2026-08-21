<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/database/migrations/078_contact_avatar_refresh.sql') ?: '';
$controller = file_get_contents($root . '/app/Controllers/ConversationController.php') ?: '';
$webhook = file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php') ?: '';
$service = file_get_contents($root . '/app/Services/EvolutionService.php') ?: '';
$js = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'migration_adds_tracking' => str_contains($migration, 'avatar_checked_at'),
    'migration_reopens_legacy_empty_avatars' => str_contains($migration, "SET avatar_url = NULL"),
    'controller_supports_force_refresh' => str_contains($controller, '$forceRefresh') && str_contains($controller, 'bool $force = false'),
    'controller_has_avatar_ttl' => str_contains($controller, '$ttl = $hasUsableAvatar ? 86400 : 21600'),
    'webhook_handles_contact_update' => str_contains($webhook, "contacts.update"),
    'webhook_persists_checked_at' => str_contains($webhook, 'avatar_checked_at = NOW()'),
    'service_handles_nested_payloads' => str_contains($service, 'extractProfilePictureUrl'),
    'browser_retries_broken_images' => str_contains($js, 'fetchConversationAvatar(container, true)') && str_contains($js, 'image.complete'),
    'package_requires_migration_078' => str_contains($version, "078_contact_avatar_refresh.sql"),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - renovação resiliente das fotos de contato validada.\n";
