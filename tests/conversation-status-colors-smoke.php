<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$view = (string) file_get_contents($root . '/app/Views/conversations/index.php');
$javascript = (string) file_get_contents($root . '/public/assets/js/app.js');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'lista recebe status no servidor' => str_contains($view, 'conversation-list-row status-')
        && str_contains($view, 'data-conversation-status=')
        && str_contains($view, 'data-conversation-list-status'),
    'painel selecionado recebe status' => str_contains($view, 'data-selected-conversation-panel')
        && str_contains($view, 'conversation-status-')
        && str_contains($view, 'data-conversation-status-badge'),
    'polling normaliza e atualiza status' => str_contains($javascript, 'normalizeConversationStatus')
        && str_contains($javascript, 'applySelectedConversationStatus')
        && str_contains($javascript, "row.classList.remove('status-open', 'status-pending', 'status-closed')"),
    'renderização dinâmica inclui rótulo' => str_contains($javascript, 'conversation-status-badge status-${conversationStatus}')
        && str_contains($javascript, 'conversationStatusText'),
    'cores para os três estados' => str_contains($css, '.conversation-list-row.status-open')
        && str_contains($css, '.conversation-list-row.status-pending')
        && str_contains($css, '.conversation-list-row.status-closed'),
    'painel acompanha o status' => str_contains($css, '.conversation-chat.conversation-status-open')
        && str_contains($css, '.conversation-chat.conversation-status-pending')
        && str_contains($css, '.conversation-chat.conversation-status-closed'),
    'não depende somente da cor' => str_contains($view, '$statusLabel[$conversationStatus]'),
    'versão e cache atualizados' => str_contains($version, 'RS Connect 36.12.1')
        && str_contains($layout, 'app.css?v=36.12.1')
        && str_contains($layout, 'app.js?v=36.12.1'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - conversas abertas, pendentes e encerradas possuem identificação visual e textual sincronizada.\n";
