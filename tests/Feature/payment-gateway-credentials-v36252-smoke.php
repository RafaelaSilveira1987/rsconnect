<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$controller = $read('app/Controllers/PaymentGatewayController.php');
$service = $read('app/Services/PaymentGatewayService.php');
$view = $read('app/Views/payment_gateways/index.php');
$js = $read('public/assets/js/app.js');

$checks = [
    'edição identifica o provedor original antes de preservar credenciais' => str_contains($controller, 'SELECT provider, api_key_encrypted, webhook_secret_encrypted')
        && str_contains($controller, '$sameProvider'),
    'configuração incompleta é salva com segurança como inativa' => str_contains($controller, '$credentialWarnings')
        && str_contains($controller, "$status = 'inactive';")
        && str_contains($controller, 'Meio de pagamento salvo como inativo'),
    'troca de provedor não reaproveita credenciais antigas' => str_contains($controller, '$sameProvider ? (string) ($existing[\'api_key_encrypted\'] ?? \'\') : \'\''),
    'listagem informa se chave e webhook estão configurados' => str_contains($service, 'AS has_api_key')
        && str_contains($service, 'AS has_webhook_secret')
        && str_contains($view, 'data-has-api-key')
        && str_contains($view, 'data-has-webhook-secret'),
    'formulário diferencia API Key do Asaas e preserva chave já cadastrada' => str_contains($js, 'API Key do Asaas')
        && str_contains($js, 'Chave já cadastrada — deixe vazio para manter')
        && str_contains($js, 'drawer.dataset.hasApiKey'),
    'credenciais obrigatórias são validadas antes do envio' => str_contains($js, 'apiKeyInput.required=active&&apiRequired&&!hasStoredApiKey')
        && str_contains($js, 'webhookInput.required=!isPagBank&&active&&webhookRequired&&!hasStoredWebhookSecret')
        && str_contains($js, 'form.reportValidity()'),
    'cache dos assets foi atualizado' => str_contains($read('app/Views/layouts/app.php'), 'app.js?v=36.25.2'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Falhas:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK: credenciais dos meios de pagamento v36.25.2\n";
