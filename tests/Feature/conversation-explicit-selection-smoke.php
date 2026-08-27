<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/app/Controllers/ConversationController.php') ?: '';
$view = file_get_contents($root . '/app/Views/conversations/index.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(
    !str_contains($controller, '$selectedId = (int) $conversations[0][\'id\'];'),
    'O controller não deve selecionar automaticamente a primeira conversa.'
);
$assert(
    str_contains($controller, 'Uma conversa somente é aberta quando o usuário a seleciona explicitamente.'),
    'A regra de seleção explícita deve estar documentada no controller.'
);
$assert(
    str_contains($view, 'Nenhuma conversa é aberta automaticamente.'),
    'O painel vazio deve orientar o usuário sobre a abertura por clique.'
);
$assert(
    str_contains($view, '$selected ? $normalizeStatus') && str_contains($view, " : '';"),
    'O painel sem conversa não deve herdar visual de status aberto.'
);
$assert(
    str_contains($view, 'if ($selected):'),
    'O histórico deve continuar sendo renderizado quando uma conversa é selecionada.'
);
$assert(
    str_contains($view, 'conversation_uuid') || str_contains($view, "PublicId::encode('conversation'"),
    'Os links de conversa devem continuar usando identificador público.'
);
$assert(str_contains($version, 'RS Connect 36.15.1'), 'A versão do pacote deve ser 36.15.0.');
$assert(
    str_contains($layout, 'app.css?v=36.15.1') && str_contains($layout, 'app.js?v=36.15.1'),
    'O cache dos assets deve ser renovado para 36.15.0.'
);

if ($failures !== []) {
    fwrite(STDERR, "Falhas no smoke test de seleção explícita de conversa:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK: conversas são abertas somente após seleção explícita.\n";
