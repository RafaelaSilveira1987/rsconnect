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

$serviceSource = (string) file_get_contents($root . '/app/Services/PaymentGatewayService.php');
$versionSource = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$migrationPath = $root . '/database/migrations/088_payment_reconciliation_schema_compat.sql';
$migration = is_file($migrationPath) ? (string) file_get_contents($migrationPath) : '';

$reflection = new ReflectionClass(PaymentGatewayService::class);

$check($reflection->hasMethod('columnExists'), 'serviço detecta colunas disponíveis no banco');
$check($reflection->hasMethod('optionalInvoiceTimestampSql'), 'SQL opcional de timestamps está centralizado');
$check(str_contains($serviceSource, "optionalInvoiceTimestampSql('payment_status_checked_at')"), 'persistência do Checkout possui fallback para coluna ausente');
$check(str_contains($serviceSource, "optionalInvoiceTimestampSql('external_imported_at')"), 'importação externa possui fallback compatível');
$check(str_contains($serviceSource, "columnExists('tenant_invoices', 'access_released_at')"), 'liberação de acesso não falha em schema parcial');
$check(is_file($migrationPath), 'migration 088 foi incluída');
$check(substr_count($migration, "information_schema.COLUMNS") >= 3, 'migration verifica as três colunas antes de alterar');
$check(str_contains($migration, 'payment_status_checked_at'), 'migration cria payment_status_checked_at quando ausente');
$check(str_contains($migration, 'idx_invoice_external_payment'), 'migration garante índice de conciliação');
$check(str_contains($versionSource, 'RS Connect 36.20.13.3'), 'versão do hotfix foi atualizada');
$check(str_contains($versionSource, '088_payment_reconciliation_schema_compat.sql') && str_contains($versionSource, '089_schema_migrations_registry.sql') && str_contains($versionSource, "REQUIRED_MIGRATION = '090_crm_conversation_automation.sql'"), 'migrations 088 e 089 permanecem no histórico e a 090 é obrigatória');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo hotfix schema PagBank: {$passes} verificações aprovadas.\n";
