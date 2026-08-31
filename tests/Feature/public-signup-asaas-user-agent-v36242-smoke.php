<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$signup = file_get_contents($root . '/app/Services/PublicSignupService.php') ?: '';
$payment = file_get_contents($root . '/app/Services/PaymentGatewayService.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$env = file_get_contents($root . '/.env.example') ?: '';

$checks = [
    'cadastro público injeta User-Agent' => str_contains($signup, '$headers = $this->withAsaasUserAgent($headers);')
        && str_contains($signup, "User-Agent: ' . mb_substr(\$userAgent, 0, 255)"),
    'cadastro possui fallback seguro' => str_contains($signup, "Env::get('ASAAS_USER_AGENT', 'RS-Connect/36.24.3')")
        && str_contains($signup, "preg_match('/[\\r\\n]/', \$userAgent)"),
    'gateway financeiro detecta apenas hosts oficiais Asaas' => str_contains($payment, "['api.asaas.com', 'api-sandbox.asaas.com']")
        && str_contains($payment, '$this->isAsaasApiUrl($url)'),
    'gateway financeiro injeta User-Agent' => str_contains($payment, '$headers = $this->withAsaasUserAgent($headers);')
        && str_contains($payment, "Env::get('ASAAS_USER_AGENT', 'RS-Connect/36.24.3')"),
    'versão do pacote atualizada' => str_contains($version, 'RS Connect 36.24.3')
        && str_contains($signup, "public const VERSION = '36.24.3';"),
    'variável opcional documentada' => str_contains($env, 'ASAAS_USER_AGENT=RS-Connect/36.24.3'),
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
echo "OK - User-Agent obrigatório do Asaas v36.24.2 validado.\n";
