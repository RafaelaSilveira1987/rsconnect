<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/PaymentGatewayService.php');

$checks = [
    'Asaas confirmation is mapped to paid' => str_contains($service, "'PAYMENT_CONFIRMED'") && str_contains($service, "return 'paid';"),
    'Asaas received is mapped to paid' => str_contains($service, "'PAYMENT_RECEIVED'"),
    'Asaas capture refusal stays open for retry' => str_contains($service, "'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED'") && str_contains($service, "return 'open';"),
    'Asaas risk reproval stays open for retry' => str_contains($service, "'PAYMENT_REPROVED_BY_RISK_ANALYSIS'") && str_contains($service, "billing_status = \"overdue\""),
    'Asaas pending/risk analysis is not treated as paid' => str_contains($service, "'PAYMENT_AWAITING_RISK_ANALYSIS'") && str_contains($service, "return 'open';"),
    'invoice lookup falls back to Asaas payment id' => str_contains($service, 'OR external_payment_id = :external_payment_id'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - integração Asaas: confirmação, recusa, análise e conciliação por payment.id validados.\n";

