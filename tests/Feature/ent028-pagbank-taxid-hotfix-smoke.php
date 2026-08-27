<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/app/Services/PaymentGatewayService.php';

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

$reflection = new ReflectionClass(PaymentGatewayService::class);
$service = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizeBrazilianTaxId');
$normalize->setAccessible(true);

$check($normalize->invoke($service, '529.982.247-25') === '52998224725', 'aceita CPF válido e remove formatação');
$check($normalize->invoke($service, '529.982.247-24') === '', 'rejeita CPF com dígito verificador inválido');
$check($normalize->invoke($service, '111.111.111-11') === '', 'rejeita CPF com todos os dígitos iguais');
$check($normalize->invoke($service, '11.222.333/0001-81') === '11222333000181', 'aceita CNPJ válido e remove formatação');
$check($normalize->invoke($service, '11.222.333/0001-82') === '', 'rejeita CNPJ com dígito verificador inválido');
$check($normalize->invoke($service, 'documento não informado') === '', 'ignora documento sem CPF ou CNPJ válido');

$source = (string) file_get_contents($root . '/app/Services/PaymentGatewayService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(str_contains($source, "str_contains(mb_strtolower(\$exception->getMessage()), 'customer.tax_id')"), 'possui recuperação específica para recusa de customer.tax_id');
$check(str_contains($source, "unset(\$payload['customer']['tax_id'])"), 'nova tentativa remove somente o documento recusado');
$check(str_contains($source, 'customer_modifiable'), 'checkout continua permitindo correção pelo comprador');
$check(str_contains($version, 'RS Connect 36.20.13.2'), 'versão do hotfix foi atualizada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo hotfix tax_id PagBank: {$passes} verificações aprovadas.\n";
