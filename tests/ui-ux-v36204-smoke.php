<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$css = $read('public/assets/css/app.css');
$billing = $read('app/Views/billing/index.php');
$payments = $read('app/Views/payment_gateways/index.php');
$layout = $read('app/Views/layouts/app.php');
$version = $read('app/Services/AppVersionService.php');
$docs = $read('docs/guias/relatorio-ui-ux-v36.20.4.md');

$checks = [
    'rodapé não fica sobre os campos' => str_contains($css, 'RS Connect 36.20.4')
        && str_contains($css, 'position: static !important')
        && str_contains($css, 'padding-bottom: 20px !important'),
    'acompanhamento neutraliza grade antiga' => str_contains($css, 'form.attention-followup-form')
        && str_contains($css, 'grid-template-columns: 1fr !important')
        && str_contains($css, '.attention-followup-actions'),
    'agenda comercial não reduz o funil' => str_contains($css, '.admin-crm-layout')
        && str_contains($css, 'flex-direction: column !important')
        && str_contains($css, '.admin-crm-agenda'),
    'situação da cobrança possui rótulo' => str_contains($billing, 'billing-status-field')
        && str_contains($billing, 'Situação da cobrança')
        && str_contains($billing, 'Salvar situação'),
    'meio de pagamento usa linguagem simples' => str_contains($payments, 'Meios de pagamento')
        && str_contains($payments, 'Salvar meio de pagamento'),
    'assets foram atualizados' => (
        (str_contains($layout, 'app.css?v=36.20.4') && str_contains($layout, 'app.js?v=36.20.4') && str_contains($version, 'RS Connect 36.20.4'))
        || (str_contains($layout, 'app.css?v=36.20.5') && str_contains($layout, 'app.js?v=36.20.5') && str_contains($version, 'RS Connect 36.20.5'))
    ),
    'documentação foi incluída' => str_contains($docs, 'Problemas observados')
        && str_contains($docs, 'Regra para novas telas'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

exit($failed === [] ? 0 : 1);
