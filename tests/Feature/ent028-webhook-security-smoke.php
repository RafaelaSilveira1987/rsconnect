<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Core/Env.php';
require_once $root . '/app/Core/Database.php';
require_once $root . '/app/Services/WebhookSecurityService.php';

use App\Services\WebhookSecurityService;

$failures = [];
$passes = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        $passes++;
        return;
    }
    echo "[FAIL] {$label}\n";
    $failures[] = $label;
};
$throwsCode = static function (callable $callback, int $code): bool {
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception->getCode() === $code;
    }
    return false;
};

$security = new WebhookSecurityService();
$secret = 'ent028-test-secret-with-at-least-32-characters';
$raw = '{"id":"evt-1","status":"PAID"}';
$now = (string) time();

$internalSignature = hash_hmac('sha256', $now . '.' . $raw, $secret);
try {
    $security->verifyInternalHmac('test.internal', $raw, $secret, [
        'X-RS-Timestamp' => $now,
        'X-RS-Signature' => $internalSignature,
    ], 300);
    $check(true, 'HMAC interno válido é aceito');
} catch (Throwable) {
    $check(false, 'HMAC interno válido é aceito');
}
$check($throwsCode(static fn () => $security->verifyInternalHmac('test.internal', $raw, $secret, [
    'X-RS-Timestamp' => $now,
    'X-RS-Signature' => str_repeat('0', 64),
], 300), 401), 'HMAC interno inválido é rejeitado');

$expired = (string) (time() - 1000);
$expiredSignature = hash_hmac('sha256', $expired . '.' . $raw, $secret);
$check($throwsCode(static fn () => $security->verifyInternalHmac('test.internal', $raw, $secret, [
    'X-RS-Timestamp' => $expired,
    'X-RS-Signature' => $expiredSignature,
], 300), 403), 'evento expirado é rejeitado');
$check($throwsCode(static fn () => $security->verifyInternalHmac('test.internal', $raw, '', [], 300), 503), 'segredo ausente gera erro explícito de configuração');

$pagBankSignature = hash('sha256', $secret . '-' . $raw);
try {
    $security->verifyPagBank($raw, $secret, ['x-authenticity-token' => $pagBankSignature]);
    $check(true, 'assinatura oficial PagBank válida é aceita');
} catch (Throwable) {
    $check(false, 'assinatura oficial PagBank válida é aceita');
}
$check($throwsCode(static fn () => $security->verifyPagBank($raw, $secret, ['x-authenticity-token' => str_repeat('f', 64)]), 401), 'assinatura PagBank inválida é rejeitada');

$stripeSignature = hash_hmac('sha256', $now . '.' . $raw, $secret);
try {
    $security->verifyStripe($raw, $secret, ['Stripe-Signature' => 't=' . $now . ',v1=' . $stripeSignature], 300);
    $check(true, 'assinatura Stripe com timestamp é aceita');
} catch (Throwable) {
    $check(false, 'assinatura Stripe com timestamp é aceita');
}

$requestId = 'req-ent028';
$dataId = '123456';
$manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $now . ';';
$mpSignature = hash_hmac('sha256', $manifest, $secret);
try {
    $security->verifyMercadoPago($secret, [
        'x-signature' => 'ts=' . $now . ',v1=' . $mpSignature,
        'x-request-id' => $requestId,
    ], $dataId, 300);
    $check(true, 'assinatura Mercado Pago é validada');
} catch (Throwable) {
    $check(false, 'assinatura Mercado Pago é validada');
}

try {
    $security->verifyStaticToken('payment.asaas', $secret, ['asaas-access-token' => $secret], ['asaas-access-token'], 32);
    $check(true, 'authToken Asaas é validado pelo header oficial');
} catch (Throwable) {
    $check(false, 'authToken Asaas é validado pelo header oficial');
}

$sanitized = $security->sanitize([
    'token' => 'secret-value',
    'nested' => ['authorization' => 'Bearer abc', 'status' => 'paid'],
]);
$check(($sanitized['token'] ?? '') === '[mascarado]' && ($sanitized['nested']['authorization'] ?? '') === '[mascarado]', 'dados sensíveis são mascarados antes do log');

$evolution = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$instance = (string) file_get_contents($root . '/app/Controllers/InstanceController.php');
$instanceView = (string) file_get_contents($root . '/app/Views/instances/index.php');
$n8n = (string) file_get_contents($root . '/app/Controllers/N8nTemplateController.php');
$payment = (string) file_get_contents($root . '/app/Services/PaymentGatewayService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/PaymentGatewayController.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$view = (string) file_get_contents($root . '/app/Views/payment_gateways/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/087_webhook_security_events.sql');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(!str_contains($instance, "&token=") && !str_contains($instanceView, "&token="), 'token Evolution não é incluído na URL nem na interface');
$check(str_contains($instance, "'X-RS-Connect-Token' => \$token"), 'Evolution recebe token somente por header');
$check(str_contains($evolution, 'reserveWebhookEvent') && str_contains($evolution, 'WebhookSecurityService'), 'Evolution usa proteção central e idempotência');
$check(str_contains($n8n, 'verifyInternalHmac') && str_contains($n8n, 'X-RS-Signature'), 'callback n8n exige HMAC e timestamp');
$check(str_contains($payment, 'verifyPagBank') && str_contains($payment, 'verifyStripe') && str_contains($payment, 'verifyMercadoPago'), 'pagamentos usam autenticação específica por provedor');
$check(str_contains($controller, 'PagBank') && str_contains($view, 'PagBank / PagSeguro'), 'configuração de cobrança PagBank/PagSeguro está disponível');
$check(str_contains($migration, 'UNIQUE KEY uq_webhook_security_source_event'), 'migration cria chave única de idempotência');
$check(str_contains($routes, "post('/webhooks/payments/pagbank'") && !str_contains($routes, "get('/webhooks/payments/pagbank'"), 'webhook PagBank aceita somente POST');
$check(str_contains($version, 'RS Connect 36.20.13') && str_contains($version, '087_webhook_security_events.sql'), 'versão e migration obrigatória foram atualizadas');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo ENT-028: {$passes} verificações aprovadas.\n";
