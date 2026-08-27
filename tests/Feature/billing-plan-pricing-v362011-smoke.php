<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'controller' => $root . '/app/Controllers/BillingController.php',
    'view' => $root . '/app/Views/billing/index.php',
    'js' => $root . '/public/assets/js/app.js',
    'migration' => $root . '/database/migrations/086_plan_ai_mode_and_commitment.sql',
    'version' => $root . '/app/Services/AppVersionService.php',
];

foreach ($files as $label => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo {$label} ausente: {$file}\n");
        exit(1);
    }
}

$contents = array_map(static fn (string $file): string => (string) file_get_contents($file), $files);
$required = [
    ['migration', 'own_ai_monthly_price'],
    ['migration', "WHEN 'starter' THEN 69.00"],
    ['migration', "WHEN 'starter' THEN 99.00"],
    ['migration', "WHEN 'pro' THEN 129.00"],
    ['migration', "WHEN 'pro' THEN 179.00"],
    ['migration', "WHEN 'business' THEN 259.00"],
    ['migration', "WHEN 'business' THEN 349.00"],
    ['migration', "'$.users', 3"],
    ['migration', "'$.agents', 2"],
    ['view', 'data-pricing-mode="rs_connect"'],
    ['view', 'data-pricing-mode="tenant"'],
    ['view', 'data-pricing-term="6"'],
    ['view', 'data-pricing-term="12"'],
    ['controller', 'ai_billing_mode'],
    ['controller', 'commitment_months'],
    ['js', 'refreshCommercial'],
    ['version', "086_plan_ai_mode_and_commitment.sql"],
];

foreach ($required as [$fileKey, $needle]) {
    if (!str_contains($contents[$fileKey], $needle)) {
        fwrite(STDERR, "FALHA: marcador ausente em {$fileKey}: {$needle}\n");
        exit(1);
    }
}

$price = static fn (float $base, float $discount): float => round($base * (1 - $discount / 100), 2);
$expectations = [
    [$price(99, 8), 91.08],
    [$price(179, 15), 152.15],
    [$price(259, 8), 238.28],
    [$price(349, 15), 296.65],
];
foreach ($expectations as [$actual, $expected]) {
    if (abs($actual - $expected) > 0.001) {
        fwrite(STDERR, "FALHA: cálculo comercial divergente: {$actual} != {$expected}\n");
        exit(1);
    }
}

echo "OK - matriz de preços, modalidades de IA e fidelidade v36.20.11 validadas.\n";
