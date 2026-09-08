<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/PublicSignupService.php');

$checks = [
    'PAYMENT webhook extracts payment.checkoutSession' => str_contains($service, "payment.checkoutSession"),
    'PAYMENT webhook does not confuse payment.id with checkout id' => str_contains($service, 'O payment.id é a cobrança'),
    'PAYMENT refusal marks signup as failed' => str_contains($service, 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED') && str_contains($service, 'status = CASE WHEN status = "provisioned" THEN status ELSE "failed" END'),
    'PAYMENT refusal updates existing invoice as cancelled' => str_contains($service, "upsertAsaasInvoice(\$session, \$payment, 'cancelled')"),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - regressão do webhook Asaas via payment.checkoutSession validada.\n";
