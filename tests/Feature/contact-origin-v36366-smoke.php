<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$webhook = file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php') ?: '';
$contact = file_get_contents($root . '/app/Controllers/ContactController.php') ?: '';
$conversation = file_get_contents($root . '/app/Controllers/ConversationController.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/118_contact_origin.sql') ?: '';

$applyStart = strpos($webhook, 'private function applyContactsUpsert');
$applyEnd = strpos($webhook, 'private function refreshContactAvatarIfMissing', $applyStart ?: 0);
$apply = ($applyStart !== false && $applyEnd !== false) ? substr($webhook, $applyStart, $applyEnd - $applyStart) : '';

$checks = [
    'contacts sync does not call upsertContact' => $apply !== '' && !str_contains($apply, '$this->upsertContact('),
    'contacts sync requires existing contact' => str_contains($apply, 'SELECT id') && str_contains($apply, 'OR phone = :phone'),
    'manual origin' => str_contains($contact, 'origin = "manual"'),
    'message origin' => str_contains($webhook, '"whatsapp_message"'),
    'human outbound origin' => str_contains($conversation, '"human_outbound"'),
    'migration origin column' => str_contains($migration, 'ADD COLUMN origin'),
    'migration detects whatsapp sync' => str_contains($migration, "SET c.origin = 'whatsapp_sync'"),
    'required migration' => str_contains($version, "118_contact_origin.sql"),
    'version label' => str_contains($version, '36.36.6'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'FAILED: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'OK contact-origin-v36366-smoke' . PHP_EOL;
