<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$js = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/app.css') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'componente global exposto' => str_contains($js, 'window.RSConnectDialog ='),
    'confirm programático disponível' => str_contains($js, 'confirm: (options) => rsDialog.confirm(options)'),
    'prompt visual disponível' => str_contains($js, 'prompt: (options) => rsDialog.prompt(options)'),
    'exclusão em massa usa diálogo visual' => str_contains($js, 'title: `Excluir ${selected} conversa${selected === 1 ?') && str_contains($js, "tone: 'danger'"),
    'exclusão de conexão usa diálogo visual' => str_contains($js, "title: discarding ? 'Excluir conexão e dados vinculados?' : 'Excluir conexão?'") && str_contains($js, 'rsDeleteApproved'),
    'sem window.confirm nativo' => !str_contains($js, 'window.confirm('),
    'sem window.alert nativo' => !str_contains($js, 'window.alert('),
    'sem window.prompt nativo' => !str_contains($js, 'window.prompt('),
    'prompt recebe estilo próprio' => str_contains($css, '.rs-confirm-prompt'),
    'tom destrutivo estilizado' => str_contains($css, '.rs-confirm-modal[data-tone="danger"] .rs-confirm-submit'),
    'cache visual atualizado' => str_contains($layout, 'app.css?v=36.27.9') && str_contains($layout, 'app.js?v=36.27.9'),
    'versão atualizada' => str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.27.9 — Diálogos visuais padronizados'"),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label . PHP_EOL;
    if (!$ok) $failed++;
}

if ($failed > 0) {
    echo "\n[FALHA] {$failed} divergência(s).\n";
    exit(1);
}

echo "\nOK - confirmações e prompts do aplicativo usam o padrão visual RS Connect.\n";
