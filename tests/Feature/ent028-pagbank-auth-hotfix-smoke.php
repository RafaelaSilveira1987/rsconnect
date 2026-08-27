<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Services/PaymentGatewayService.php';
require_once $root . '/app/Controllers/PaymentGatewayController.php';

use App\Controllers\PaymentGatewayController;
use App\Services\PaymentGatewayService;

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

$controllerReflection = new ReflectionClass(PaymentGatewayController::class);
$controller = $controllerReflection->newInstanceWithoutConstructor();
$normalizeKey = $controllerReflection->getMethod('normalizePagBankApiKey');
$normalizeKey->setAccessible(true);

$check($normalizeKey->invoke($controller, 'Bearer token-123') === 'token-123', 'remove prefixo Bearer do token informado');
$check($normalizeKey->invoke($controller, 'Authorization: Bearer token-456') === 'token-456', 'remove cabeçalho Authorization completo');
$check($normalizeKey->invoke($controller, "  'token-789'  ") === 'token-789', 'remove aspas e espaços acidentais');

$serviceReflection = new ReflectionClass(PaymentGatewayService::class);
$service = $serviceReflection->newInstanceWithoutConstructor();
$normalizeUrl = $serviceReflection->getMethod('normalizePagBankBaseUrl');
$normalizeUrl->setAccessible(true);

$check(
    $normalizeUrl->invoke($service, 'https://sandbox.api.pagseguro.com/checkouts', 'sandbox') === 'https://sandbox.api.pagseguro.com',
    'remove endpoint /checkouts da URL base Sandbox'
);
$check(
    $normalizeUrl->invoke($service, 'api.pagseguro.com/checkouts/', 'production') === 'https://api.pagseguro.com',
    'normaliza URL base de produção sem protocolo'
);

$rejected = false;
try {
    $normalizeUrl->invoke($service, 'https://sandbox.api.pagseguro.com', 'production');
} catch (Throwable) {
    $rejected = true;
}
$check($rejected, 'rejeita URL Sandbox com ambiente Produção');

$paymentService = (string) file_get_contents($root . '/app/Services/PaymentGatewayService.php');
$controllerSource = (string) file_get_contents($root . '/app/Controllers/PaymentGatewayController.php');
$view = (string) file_get_contents($root . '/app/Views/payment_gateways/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(str_contains($paymentService, 'PagBank recusou a autenticação'), 'erro técnico da AWS é convertido em orientação clara');
$check(str_contains($controllerSource, 'normalizePagBankApiKey'), 'token é normalizado antes de ser salvo');
$check(str_contains($view, 'sem escrever <code>Authorization:</code> ou <code>Bearer</code>'), 'interface orienta o formato correto do token');
$check(str_contains($version, 'RS Connect 36.20.13.1'), 'versão do hotfix foi atualizada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo hotfix PagBank: {$passes} verificações aprovadas.\n";
