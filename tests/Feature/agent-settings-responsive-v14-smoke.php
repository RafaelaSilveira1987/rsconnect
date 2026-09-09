<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'card aberto ocupa toda a grade' => str_contains($css, '.agent-card.is-settings-open') && str_contains($css, 'grid-column: 1 / -1'),
    'canais usam grade responsiva' => str_contains($css, 'repeat(auto-fit, minmax(min(100%, 280px), 1fr))'),
    'JS sincroniza estado do card' => str_contains($js, 'syncAgentSettingsCard') && str_contains($js, "details.addEventListener('toggle'"),
    'somente um painel completo fica aberto' => str_contains($js, 'closeOtherAgentSettings'),
    'linguagem simples na configuração' => str_contains($view, 'Configurar assistente') && str_contains($view, 'Em quais números este assistente atende?'),
    'versão atualizada' => str_contains($version, 'RS Connect 36.28.3'),
    'cache de CSS e JS renovado' => str_contains($layout, 'app.css?v=36.28.3') && str_contains($layout, 'app.js?v=36.28.3'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

exit($failed === [] ? 0 : 1);
