<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/InstanceController.php');
$javascript = file_get_contents($root . '/public/assets/js/app.js');
$layout = file_get_contents($root . '/app/Views/layouts/app.php');
$version = file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'status libera sessão PHP' => substr_count($controller, 'session_write_close();') >= 2,
    'reconhece connectionState 404' => str_contains($controller, "connectionstate http 404"),
    'prévia possui timeout' => str_contains($javascript, 'A verificação demorou mais que o esperado'),
    'erro deixa de permanecer verificando' => str_contains($javascript, "if (!deletePreviewState)")
        && str_contains($javascript, "'Verificação necessária'"),
    'polling pausa na exclusão' => str_contains($javascript, "deleteDrawer?.classList.contains('is-open')"),
    'cache atualizado' => str_contains($layout, 'app.js?v=36.20.8') && str_contains($layout, 'app.css?v=36.20.8'),
    'pacote atualizado' => str_contains($version, 'RS Connect 36.20.8'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
exit($failed === [] ? 0 : 1);
