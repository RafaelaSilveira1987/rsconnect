<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = [];

$view = file_get_contents($root . '/app/Views/conversations/index.php') ?: '';
$js = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/app.css') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks['dispensa usa título contextual'] = str_contains($view, 'data-confirm-title="Dispensar alerta comercial?"');
$checks['dispensa usa ação contextual'] = str_contains($view, 'data-confirm-action="Dispensar alerta"') && str_contains($view, 'data-confirm-cancel="Manter alerta"');
$checks['confirm nativo removido do handler declarativo'] = !str_contains($js, "if (!window.confirm(form.dataset.confirm)) event.preventDefault();");
$checks['modal visual é criado pelo app'] = str_contains($js, "className = 'rs-confirm-modal'") && str_contains($js, "rsConfirm.open(form, event.submitter || null)");
$checks['submit confirmado evita loop'] = str_contains($js, "form.dataset.confirmApproved = '1'");
$checks['modal suporta escape'] = str_contains($js, "event.key === 'Escape'");
$checks['estilo visual do modal existe'] = str_contains($css, '.rs-confirm-dialog') && str_contains($css, '.rs-confirm-actions');
$checks['modal é responsivo'] = str_contains($css, '@media (max-width: 560px)') && str_contains($css, '.rs-confirm-actions { flex-direction: column-reverse; }');
$checks['cache assets atualizado'] = str_contains($layout, 'app.css?v=36.27.8') && str_contains($layout, 'app.js?v=36.27.8');
$checks['versão atualizada'] = str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.27.8 — Confirmações visuais padronizadas'");

$failures = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label . PHP_EOL;
    if (!$ok) $failures++;
}

echo PHP_EOL;
if ($failures === 0) {
    echo 'OK - confirmação comercial usa modal visual padronizado do RS Connect.' . PHP_EOL;
    exit(0);
}

echo "FALHA - {$failures} verificação(ões) não passaram." . PHP_EOL;
exit(1);
