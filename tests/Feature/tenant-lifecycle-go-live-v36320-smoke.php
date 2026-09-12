<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => is_file($root . '/' . $path) ? (string) file_get_contents($root . '/' . $path) : '';
$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $message . PHP_EOL;
    $ok ? $passes++ : $failures++;
};

require_once $root . '/app/Services/TenantLifecycleService.php';

use App\Services\TenantLifecycleService;

$statuses = TenantLifecycleService::statuses();
$check(array_keys($statuses) === ['onboarding', 'ready', 'live', 'suspended'], 'Ciclo operacional possui os quatro estados previstos.');
$check(TenantLifecycleService::allowedTargets('onboarding') === ['ready'], 'Onboarding exige passar por READY antes do Go-Live.');
$check(in_array('live', TenantLifecycleService::allowedTargets('ready'), true), 'READY permite Go-Live explícito.');
$check(in_array('ready', TenantLifecycleService::allowedTargets('live'), true) && in_array('suspended', TenantLifecycleService::allowedTargets('live'), true), 'LIVE pode voltar para homologação ou ser suspenso.');

$migration = $read('database/migrations/112_tenant_lifecycle_go_live.sql');
$manifestMigrations = $read('database/migrations/manifest.php');
$controller = $read('app/Controllers/CompanyController.php');
$routes = $read('routes/web.php');
$companyIndex = $read('app/Views/companies/index.php');
$overview = $read('app/Views/companies/overview.php');
$layout = $read('app/Views/layouts/app.php');
$metrics = $read('app/Services/ExecutiveMetricsPolicyService.php');
$teamMetrics = $read('app/Services/TeamProfessionalReportService.php');
$billing = $read('app/Controllers/BillingController.php');
$adminDashboard = $read('app/Services/AdminDashboardService.php');
$adminExecutive = $read('app/Services/AdminExecutiveDashboardService.php');
$version = $read('app/Services/AppVersionService.php');
$manifest = $read('manifest.json');
$testGuide = $read('TESTE_DA_VERSAO.md');
$rollback = $read('ROLLBACK.md');

$check(str_contains($migration, 'lifecycle_status') && str_contains($migration, "DEFAULT ''onboarding''"), 'Migration cria lifecycle_status com onboarding como padrão seguro.');
$check(str_contains($migration, 'tenant_lifecycle_events') && str_contains($migration, 'went_live_at') && str_contains($migration, 'suspended_at'), 'Migration cria histórico e marcos de Go-Live/suspensão.');
$check(str_contains($manifestMigrations, "['sequence' => 119, 'file' => '112_tenant_lifecycle_go_live.sql']"), 'Manifesto de migrations registra a sequência 119.');
$check(str_contains($routes, "'/companies/lifecycle'") && str_contains($controller, 'function updateLifecycle'), 'Superadmin possui endpoint protegido para alterar o ciclo operacional.');
$check(str_contains($controller, 'TenantLifecycleService::READY') && str_contains($controller, 'Go-Live confirmado'), 'Controller trata READY/LIVE com mensagens específicas.');
$check(str_contains($companyIndex, 'Colocar em produção') && str_contains($companyIndex, 'Ciclo operacional'), 'Lista de empresas expõe Go-Live e ciclo operacional.');
$check(str_contains($overview, 'Go-Live') && str_contains($overview, 'Ver histórico do ciclo'), 'Visão geral permite configurar e auditar o ciclo.');
$check(str_contains($layout, 'Ambiente de onboarding / homologação') && str_contains($layout, 'Ambiente pronto para produção'), 'Tenant recebe aviso visual fora de LIVE.');
$check(substr_count($metrics, 'TenantLifecycleService::productionAtSql') >= 3 && str_contains($metrics, 'currentLiveSql'), 'SLA executivo restringe métricas a janelas de produção.');
$check(substr_count($teamMetrics, 'TenantLifecycleService::productionAtSql') >= 5, 'Relatório de equipe também respeita janelas LIVE.');
$check(str_contains($billing, 'allowsProductionBilling') && str_contains($billing, 'Cobrança manual de produção bloqueada'), 'Cobrança manual é bloqueada antes do Go-Live.');
$check(str_contains($adminDashboard, "lifecycle_status = 'live'") && str_contains($adminExecutive, "lifecycle_status = 'live'"), 'MRR operacional considera somente tenants LIVE.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.32.0 — Production Readiness: Go-Live'") && str_contains($version, "REQUIRED_MIGRATION = '112_tenant_lifecycle_go_live.sql'"), 'Versão e migration obrigatória estão atualizadas.');
$check(str_contains($manifest, '"package_version": "36.32.0"') && str_contains($manifest, '112_tenant_lifecycle_go_live.sql'), 'Manifesto do pacote registra a release 36.32.0.');
$check(str_contains($testGuide, 'Cenário A — Onboarding') && str_contains($testGuide, 'Cenário C — Go-Live') && str_contains($testGuide, 'Critérios para aprovar a Fase A'), 'Pacote inclui roteiro operacional de configuração e validação.');
$check(str_contains($rollback, 'Não remova a migration 112') && str_contains($rollback, '36.31.3'), 'Pacote inclui rollback seguro para a versão anterior.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo Production Readiness 36.32.0: {$passes} verificações aprovadas.\n";
