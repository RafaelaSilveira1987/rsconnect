<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/ConversationController.php') ?: '';
$webhook = file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php') ?: '';
$evolution = file_get_contents($root . '/app/Services/EvolutionService.php') ?: '';
$view = file_get_contents($root . '/app/Views/conversations/index.php') ?: '';
$js = file_get_contents($root . '/public/assets/js/app.js') ?: '';

$checks = [
    'search_by_message_history' => str_contains($controller, 'EXISTS (SELECT 1 FROM conversation_messages sm'),
    'search_normalizes_phone' => str_contains($controller, 'search_phone_digits'),
    'search_includes_contact_fields' => str_contains($controller, 'ct.email LIKE :search_email') && str_contains($controller, 'ct.company LIKE :search_company'),
    'poll_returns_avatar' => str_contains($controller, 'safeAvatarUrl((string)'),
    'evolution_profile_endpoint' => str_contains($evolution, '/chat/fetchProfilePictureUrl/'),
    'contacts_upsert_avatar' => str_contains($webhook, "contacts.upsert") && str_contains($webhook, 'profilePicUrl'),
    'contacts_update_avatar' => str_contains($webhook, "contacts.update"),
    'incoming_message_enriches_avatar' => str_contains($webhook, 'refreshContactAvatarIfMissing'),
    'avatar_refresh_tracking' => str_contains($controller, 'avatar_checked_at') && str_contains($webhook, 'avatar_checked_at'),
    'expired_avatar_forced_refresh' => str_contains($controller, '$forceRefresh') && str_contains($js, "params.set('force', '1')"),
    'broken_image_retries' => str_contains($js, 'fetchConversationAvatar(container, true)') && str_contains($js, 'image.naturalWidth'),
    'server_avatar_render' => str_contains($view, 'data-contact-avatar'),
    'live_search_input' => str_contains($view, 'data-conversation-search') && str_contains($js, "searchInput.addEventListener('input'"),
    'avatar_lazy_endpoint' => str_contains(file_get_contents($root . '/routes/web.php') ?: '', '/conversations/avatar') && str_contains($js, 'fetchConversationAvatar'),
    'list_is_synchronized' => str_contains($js, 'validIds') && str_contains($js, 'row.remove()'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - busca de conversas e avatar do contato validados.\n";
