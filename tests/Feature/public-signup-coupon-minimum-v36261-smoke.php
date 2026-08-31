<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$coupon = file_get_contents($root . '/app/Services/PublicSignupCouponService.php');
$signup = file_get_contents($root . '/app/Services/PublicSignupService.php');
$view = file_get_contents($root . '/app/Views/signup/index.php');
$admin = file_get_contents($root . '/app/Views/signup/admin.php');
$version = file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'versão do serviço de cupom' => str_contains($coupon, "public const VERSION = '36.26.1';"),
    'mínimo do Asaas centralizado' => str_contains($coupon, 'public const ASAAS_MINIMUM_CHARGE = 5.00;'),
    'cálculo preserva valor solicitado' => str_contains($coupon, 'requestedFinalAmount'),
    'valor final é limitado a cinco reais' => str_contains($coupon, '$minimumAdjusted ? self::ASAAS_MINIMUM_CHARGE'),
    'backend possui segunda proteção' => str_contains($signup, 'PublicSignupCouponService::ASAAS_MINIMUM_CHARGE'),
    'cliente recebe aviso antes do checkout' => str_contains($view, 'coupon.minimum_message'),
    'admin explica o mínimo do gateway' => str_contains($admin, 'O Asaas não aceita cobranças abaixo de R$ 5,00'),
    'versão do pacote atualizada' => str_contains($version, 'RS Connect 36.26.1'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - mínimo de R$ 5,00 do Asaas protegido na v36.26.1.\n";
