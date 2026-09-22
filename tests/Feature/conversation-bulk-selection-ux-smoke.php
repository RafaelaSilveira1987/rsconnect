<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$passes = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        $passes++;
        return;
    }
    echo "[FAIL] {$label}\n";
    $failures[] = $label;
};

$view = (string) file_get_contents($root . '/app/Views/conversations/index.php');
$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$docs = (string) file_get_contents($root . '/app/Views/docs/index.php');

$check(!str_contains($view, 'data-toggle-bulk-read'), 'botão Selecionar foi removido do cabeçalho de Conversas');
$check(str_contains($view, 'data-conversation-select') && str_contains($view, 'data-bulk-read-form'), 'checkbox individual e barra de ações em lote continuam disponíveis');
$check(str_contains($js, "const active = selected > 0;") && str_contains($js, 'setToolbarVisible(active);'), 'barra de ações aparece automaticamente quando existe seleção');
$check(str_contains($js, "form.hidden = !active;") && str_contains($js, "if (event.key === 'Escape' && selectedCount() > 0) clearSelection();"), 'barra some ao limpar a seleção e Escape cancela o lote');
$check(!str_contains($js, "const toggle = document.querySelector('[data-toggle-bulk-read]');"), 'JavaScript não depende mais do botão Selecionar removido');
$check(str_contains($js, "const selectionMarkup = bulkSelectionAvailable ?"), 'atualização em tempo real respeita permissão para exibir checkboxes');
$check(str_contains($docs, 'Marque uma conversa pelo checkbox'), 'ajuda da plataforma descreve o novo fluxo de seleção direta');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo seleção direta de Conversas: {$passes} verificações aprovadas.\n";
