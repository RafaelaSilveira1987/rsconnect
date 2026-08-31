<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$controller = $read('app/Controllers/PaymentGatewayController.php');
$signup = $read('app/Services/PublicSignupService.php');
$payment = $read('app/Services/PaymentGatewayService.php');
$javascript = $read('public/assets/js/app.js');
$migration = $read('database/migrations/094_normalize_asaas_api_base_url.sql');
$manifest = $read('database/migrations/manifest.php');
$version = $read('app/Services/AppVersionService.php');

$checks = [
    'controller limpa URL customizada do Asaas' => str_contains($controller, "if (\$provider === 'asaas')")
        && str_contains($controller, "\$apiBaseUrl = '';"),
    'cadastro público ignora api_base_url legado' => str_contains($signup, 'antigos em api_base_url')
        && !str_contains($signup, "str_replace('https://sandbox.asaas.com/api/v3'"),
    'serviço financeiro prioriza endpoint oficial Asaas' => str_contains($payment, "if (\$provider === 'asaas')")
        && str_contains($payment, 'https://api-sandbox.asaas.com/v3')
        && str_contains($payment, 'https://api.asaas.com/v3'),
    'interface desabilita URL customizada para Asaas' => str_contains($javascript, "const isAsaas=provider==='asaas'")
        && str_contains($javascript, 'baseUrlInput.disabled=isAsaas')
        && str_contains($javascript, "if(isAsaas){baseUrlInput.value='';"),
    'migration limpa api_base_url de gateways Asaas' => str_contains($migration, "WHERE provider = 'asaas'")
        && str_contains($migration, 'SET api_base_url = NULL'),
    'manifest inclui migration 094' => str_contains($manifest, "['sequence' => 101, 'file' => '094_normalize_asaas_api_base_url.sql']"),
    'versão exige migration 094' => str_contains($version, 'RS Connect 36.24.1')
        && str_contains($version, "REQUIRED_MIGRATION = '094_normalize_asaas_api_base_url.sql'"),
];

$failed = [];
foreach ($checks as $label => $passed) {
    echo ($passed ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$passed) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - URL oficial do Asaas v36.24.1 validada.\n";
