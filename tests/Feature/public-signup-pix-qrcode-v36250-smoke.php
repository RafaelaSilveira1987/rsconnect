<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = file_get_contents($root . '/app/Services/PublicSignupService.php') ?: '';
$view = file_get_contents($root . '/app/Views/signup/index.php') ?: '';
$status = file_get_contents($root . '/app/Views/signup/status.php') ?: '';
$migration = file_get_contents($root . '/database/migrations/095_public_signup_pix_qrcode.sql') ?: '';
$manifest = file_get_contents($root . '/database/migrations/manifest.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'formulário oferece cartão e Pix' => str_contains($service, "'pix_enabled' => !empty")
        && str_contains($migration, 'ADD COLUMN pix_enabled')
        && str_contains($view, 'value="credit_card"')
        && str_contains($view, 'value="pix"')
        && str_contains($view, 'Pix QR Code'),
    'checkout Pix é avulso e cartão permanece recorrente' => str_contains($service, "'billingTypes' => [\$isPix ? 'PIX' : 'CREDIT_CARD']")
        && str_contains($service, "'chargeTypes' => [\$isPix ? 'DETACHED' : 'RECURRENT']")
        && str_contains($service, "if (!\$isPix)"),
    'Pix só provisiona após confirmação financeira' => str_contains($service, "PAYMENT_CONFIRMED")
        && str_contains($service, "PAYMENT_RECEIVED")
        && str_contains($service, "CHECKOUT_PAID"),
    'renovação Pix usa boleto com QR Code' => str_contains($service, "'billingType' => 'BOLETO'")
        && str_contains($service, 'renovacao mensal com QR Code Pix')
        && str_contains($service, 'signup-renewal:'),
    'migração adiciona método e bônus' => str_contains($migration, 'ADD COLUMN payment_method')
        && str_contains($migration, 'ADD COLUMN bonus_days'),
    'manifest inclui migration 095' => str_contains($manifest, "['sequence' => 102, 'file' => '095_public_signup_pix_qrcode.sql']"),
    'status diferencia Pix e cartão' => str_contains($status, 'Pix confirmado')
        && str_contains($status, 'Próxima renovação'),
    'versão atualizada' => str_contains($service, "public const VERSION = '36.25.1';")
        && str_contains($version, 'RS Connect 36.25.1')
        && str_contains($version, "REQUIRED_MIGRATION = '095_public_signup_pix_qrcode.sql'"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - cadastro público com Pix QR Code v36.25.1 validado.\n";
