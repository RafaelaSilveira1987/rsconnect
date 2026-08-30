<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$migration = $read('database/migrations/093_public_signup_asaas_trial.sql');
$manifest = $read('database/migrations/manifest.php');
$routes = $read('routes/web.php');
$service = $read('app/Services/PublicSignupService.php');
$payment = $read('app/Services/PaymentGatewayService.php');
$login = $read('app/Views/auth/login.php');
$admin = $read('app/Views/signup/admin.php');
$version = $read('app/Services/AppVersionService.php');

$checks = [
    'migration cria estrutura do cadastro público' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS public_signup_settings')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS public_signup_sessions')
        && str_contains($migration, 'CREATE TABLE IF NOT EXISTS tenant_subscription_gateways'),
    'manifest inclui migration 093' => str_contains($manifest, "['sequence' => 100, 'file' => '093_public_signup_asaas_trial.sql']"),
    'rotas públicas e administrativas existem' => str_contains($routes, "'/signup'")
        && str_contains($routes, "'/signup/status'")
        && str_contains($routes, "'/settings/public-signup'"),
    'checkout usa cartão recorrente e vencimento futuro' => str_contains($service, "'billingTypes' => ['CREDIT_CARD']")
        && str_contains($service, "'chargeTypes' => ['RECURRENT']")
        && str_contains($service, "'nextDueDate'"),
    'sandbox Asaas usa endpoint oficial atual' => str_contains($service, 'https://api-sandbox.asaas.com/v3')
        && str_contains($payment, 'https://api-sandbox.asaas.com/v3'),
    'callback não provisiona sem webhook' => str_contains($service, "in_array(\$event, ['CHECKOUT_PAID', 'SUBSCRIPTION_CREATED'], true)")
        && str_contains($payment, 'public_signup'),
    'webhook Asaas seleciona o gateway pelo token' => str_contains($payment, 'gatewayForWebhook')
        && str_contains($payment, "['asaas-access-token']")
        && str_contains($payment, 'hash_equals($expectedToken, $providedToken)'),
    'login exibe CTA de sete dias' => str_contains($login, 'Começar <?= (int)')
        && str_contains($login, "Router::url('/signup')"),
    'admin oferece configuração isolada' => str_contains($admin, 'Inscrição pública e trial Asaas')
        && str_contains($admin, 'Plano Inicial — IA RS Connect'),
    'versão e migration exigida atualizadas' => str_contains($version, 'RS Connect 36.24.0')
        && str_contains($version, "REQUIRED_MIGRATION = '093_public_signup_asaas_trial.sql'"),
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

echo "OK - cadastro público e trial Asaas v36.24.0 validados.\n";
