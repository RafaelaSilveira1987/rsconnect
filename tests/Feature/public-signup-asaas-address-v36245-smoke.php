<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$signup = file_get_contents($root . '/app/Services/PublicSignupService.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$start = strpos($signup, 'private function createAsaasCheckout');
$end = strpos($signup, 'private function configuredGateway', $start === false ? 0 : $start);
$method = ($start !== false && $end !== false) ? substr($signup, $start, $end - $start) : '';

$checks = [
    'checkout recorrente preservado' => str_contains($method, "'chargeTypes' => ['RECURRENT']")
        && str_contains($method, "'cycle' => 'MONTHLY'"),
    'customerData parcial não é enviado' => !str_contains($method, "'customerData'")
        && !str_contains($method, "'address' =>"),
    'endereço fica no ambiente seguro do Asaas' => str_contains($method, 'o próprio pagador informa e confirma endereço'),
    'dados essenciais continuam armazenados no cadastro' => str_contains($signup, "'document' => (string) \$data['document']")
        || str_contains($signup, "'document' => \$document"),
    'versão atualizada' => str_contains($signup, "public const VERSION = '36.24.5';")
        && str_contains($version, 'RS Connect 36.24.5'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}
if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - endereço do Checkout Asaas v36.24.5 validado.\n";
