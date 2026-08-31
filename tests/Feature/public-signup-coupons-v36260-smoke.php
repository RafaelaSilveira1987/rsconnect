<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$service = $read('app/Services/PublicSignupService.php');
$couponService = $read('app/Services/PublicSignupCouponService.php');
$controller = $read('app/Controllers/PublicSignupController.php');
$admin = $read('app/Views/signup/admin.php');
$signup = $read('app/Views/signup/index.php');
$billing = $read('app/Views/billing/subscription.php');
$routes = $read('routes/web.php');
$migration = $read('database/migrations/096_public_signup_coupons.sql');
$manifest = $read('database/migrations/manifest.php');
$version = $read('app/Services/AppVersionService.php');
$css = $read('public/assets/css/app.css');

$checks = [
    'migration cria cupons e vínculo com a inscrição' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS public_signup_coupons')
        && str_contains($migration, 'ADD COLUMN coupon_id')
        && str_contains($migration, 'ADD COLUMN original_amount')
        && str_contains($migration, 'ADD COLUMN discount_restored_at'),
    'manifest inclui migration 096' => str_contains($manifest, "['sequence' => 103, 'file' => '096_public_signup_coupons.sql']"),
    'serviço valida regras de cupom' => str_contains($couponService, "public const VERSION = '36.26.0';")
        && str_contains($couponService, "['percentage', 'fixed']")
        && str_contains($couponService, "['first_charge', 'recurring']")
        && str_contains($couponService, 'max_redemptions_per_email')
        && str_contains($couponService, 'final_amount'),
    'cadastro grava desconto e envia valor líquido ao checkout' => str_contains($service, "'coupon_id' => \$coupon['id'] ?? null")
        && str_contains($service, "'amount' => \$finalAmount")
        && str_contains($service, "'value' => (float) \$data['amount']"),
    'primeira cobrança restaura mensalidade integral' => str_contains($service, 'restoreFirstChargeCouponAfterPayment')
        && str_contains($service, "'/subscriptions/' . rawurlencode(\$externalSubscriptionId)")
        && str_contains($service, "['value' => \$originalAmount]"),
    'rotas públicas e administrativas existem' => str_contains($routes, '/signup/coupon/validate')
        && str_contains($routes, '/settings/public-signup/coupons/save')
        && str_contains($routes, '/settings/public-signup/coupons/toggle')
        && str_contains($controller, 'public function validateCoupon(): void')
        && str_contains($controller, 'public function saveCoupon(): void'),
    'tela pública aplica cupom sem sair do cadastro' => str_contains($signup, 'Possui um cupom?')
        && str_contains($signup, '/signup/coupon/validate')
        && str_contains($signup, 'O desconto continua nas renovações.'),
    'admin foi reorganizado e gerencia cupons' => str_contains($admin, 'Oferta no login')
        && str_contains($admin, 'Plano, gateway e prazos')
        && str_contains($admin, 'Cupons de desconto')
        && str_contains($admin, 'Criar novo cupom')
        && str_contains($css, '.public-signup-config-layout')
        && str_contains($css, '.public-coupon-list'),
    'portal do cliente mostra benefício aplicado' => str_contains($billing, 'Benefício promocional')
        && str_contains($billing, 'Promoção aplicada'),
    'versão e migration obrigatória atualizadas' => str_contains($version, 'RS Connect 36.26.0')
        && str_contains($version, "REQUIRED_MIGRATION = '096_public_signup_coupons.sql'"),
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

echo "OK - cupons de assinatura e nova tela v36.26.0 validados.\n";
