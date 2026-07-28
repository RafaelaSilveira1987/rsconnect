<?php
$root = dirname(__DIR__);
$view = file_get_contents($root . '/app/Views/conversations/index.php');
$css = file_get_contents($root . '/public/assets/css/app.css');
$js = file_get_contents($root . '/public/assets/js/app.js');
$checks = [
    'open trigger' => str_contains($view, 'data-new-conversation-open'),
    'drawer shell' => str_contains($view, 'data-new-conversation-shell'),
    'form preserved' => str_contains($view, 'data-new-conversation-form'),
    'lookup preserved' => str_contains($view, 'data-new-conversation-search'),
    'fixed shell' => str_contains($css, '.new-conversation-shell { position:fixed'),
    'responsive drawer' => str_contains($css, '.new-conversation-drawer'),
    'open logic' => str_contains($js, 'function openNewConversation()'),
    'close logic' => str_contains($js, 'function closeNewConversation()'),
];
foreach ($checks as $label => $ok) {
    if (!$ok) { fwrite(STDERR, "FAIL: $label\n"); exit(1); }
}
echo "OK - novo atendimento abre em drawer sem depender da largura da Caixa de Entrada.\n";
