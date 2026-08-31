<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$billingController = file_get_contents($root . '/app/Controllers/BillingController.php') ?: '';
$billingView = file_get_contents($root . '/app/Views/billing/subscription.php') ?: '';
$signupService = file_get_contents($root . '/app/Services/PublicSignupService.php') ?: '';
$signupController = file_get_contents($root . '/app/Controllers/PublicSignupController.php') ?: '';
$signupAdmin = file_get_contents($root . '/app/Views/signup/admin.php') ?: '';
$routes = file_get_contents($root . '/routes/web.php') ?: '';
$css = file_get_contents($root . '/public/assets/css/app.css') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$openInvoiceSnippet = <<<'TXT'
$this->upsertAsaasInvoice($session, $payment, 'open');
TXT;
$getWithoutBodySnippet = <<<'TXT'
if (!in_array($method, ['GET', 'HEAD'], true))
TXT;

$checks = [
    'portal consulta gateway da assinatura' => str_contains($billingController, 'tenant_subscription_gateways')
        && str_contains($billingController, 'gateway_environment')
        && str_contains($billingController, 'public_signup_sessions'),
    'webhook registra cobrança aberta' => str_contains($signupService, 'PAYMENT_CREATED')
        && str_contains($signupService, $openInvoiceSnippet)
        && str_contains($signupService, "'payment_method' => (string)"),
    'portal exibe trial cobrança e forma de pagamento' => str_contains($billingView, 'Detalhes da assinatura')
        && str_contains($billingView, 'Forma de pagamento')
        && str_contains($billingView, 'Primeira cobrança')
        && str_contains($billingView, 'Último pagamento')
        && str_contains($billingView, 'client-billing-timeline'),
    'sandbox fica visível ao cliente' => str_contains($billingView, 'Assinatura em ambiente de homologação')
        && str_contains($css, '.client-billing-environment-alert'),
    'teste de conexão usa GET sem body' => str_contains($signupService, 'testGatewayConnection')
        && str_contains($signupService, '/myAccount/commercialInfo/')
        && str_contains($signupService, $getWithoutBodySnippet),
    'rota protegida do teste Asaas existe' => str_contains($routes, '/settings/public-signup/test-gateway')
        && str_contains($signupController, 'public function testGateway(): void')
        && str_contains($signupAdmin, 'Testar conexão com o Asaas'),
    'checklist de produção está disponível' => str_contains($signupAdmin, 'Validação para produção')
        && str_contains($signupAdmin, 'Pronto para o teste real controlado')
        && str_contains($signupAdmin, 'Deixe o Pix desativado'),
    'versão 36.25.1 aplicada' => str_contains($version, 'RS Connect 36.25.1')
        && str_contains($signupService, "public const VERSION = '36.25.1';"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - portal financeiro e validação Asaas Produção v36.25.1 validados.\n";
