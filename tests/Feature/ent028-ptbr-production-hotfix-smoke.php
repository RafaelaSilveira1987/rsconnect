<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);

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

$billing = (string) file_get_contents($root . '/app/Views/billing/index.php');
$paymentGateways = (string) file_get_contents($root . '/app/Views/payment_gateways/index.php');
$companies = (string) file_get_contents($root . '/app/Views/companies/index.php');
$crm = (string) file_get_contents($root . '/app/Views/crm/admin.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(str_contains($billing, "'starter' => 'Inicial'") && str_contains($billing, "'business' => 'Empresarial'"), 'billing traduz nomes de planos comerciais');
$check(str_contains($billing, "'active' => 'Ativo'") && str_contains($billing, "'created' => 'Criado'"), 'billing traduz status externos do gateway');
$check(str_contains($billing, '$formatPlanName') && str_contains($billing, '$formatExternalStatus'), 'billing usa formatadores dedicados para textos em português');
$check(str_contains($paymentGateways, '<option value="production">Produção</option>') && str_contains($paymentGateways, '<option value="sandbox">Ambiente de teste</option>'), 'cadastro do gateway mantém ambiente de produção disponível e padrão');
$check(str_contains($companies, '>Inicial</option>') && str_contains($companies, '>Empresarial</option>'), 'cadastro de empresas usa rótulos dos planos em português');
$check(str_contains($crm, '>Inicial</option>') && str_contains($crm, '>Empresarial</option>'), 'conversão de lead para cliente usa rótulos em português');
$check(str_contains($version, 'RS Connect 36.20.13.4') && str_contains($version, 'correção dos rótulos comerciais'), 'versão do pacote foi atualizada');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo do hotfix PT-BR/Produção: {$passes} verificações aprovadas.\n";
