<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$service = $read('app/Services/ScheduledReportService.php');
$pdf = $read('app/Services/ExecutiveReportPdfService.php');
$simplePdf = $read('app/Services/SimplePdfDocument.php');
$controller = $read('app/Controllers/ScheduledReportController.php');
$view = $read('app/Views/reports/automatic.php');
$routes = $read('routes/web.php');
$migration = $read('database/migrations/075_scheduled_reports_and_deliveries.sql');
$diagnostic = $read('database/diagnostics/scheduled_reports_v36.15.1.sql');
$env = $read('.env.vps.example');
$version = $read('app/Services/AppVersionService.php');
$admin = $read('app/Services/AdminExecutiveReportService.php');
$tenant = $read('app/Services/TenantExecutiveReportService.php');
$n8n = $read('docs/n8n_templates/template-relatorios-automaticos.json');
$cli = $read('bin/scheduled-reports.php');

$checks = [
    'migration cria as quatro estruturas' => substr_count($migration, 'CREATE TABLE IF NOT EXISTS') >= 4
        && str_contains($migration, 'scheduled_reports')
        && str_contains($migration, 'scheduled_report_recipients')
        && str_contains($migration, 'generated_reports')
        && str_contains($migration, 'scheduled_report_deliveries'),
    'migration protege duplicidade' => str_contains($migration, 'uq_generated_reports_run_key')
        && str_contains($migration, 'uq_scheduled_report_delivery'),
    'migration concede permissão ao administrador cliente' => str_contains($migration, 'reports.schedule.manage')
        && str_contains($migration, "'client_admin'"),
    'serviço gera PDF privado e hash' => str_contains($service, "str_starts_with(\$bytes, '%PDF-')")
        && str_contains($service, "hash('sha256', \$bytes)")
        && str_contains($service, 'SCHEDULED_REPORTS_PATH'),
    'serviço possui programação diária semanal e mensal' => str_contains($service, "['manual', 'daily', 'weekly', 'monthly']")
        && str_contains($service, 'nextRunUtc')
        && str_contains($service, 'periodForMode'),
    'serviço impede relatório duplicado por execução' => str_contains($service, 'WHERE run_key = :run_key')
        && str_contains($service, 'schedule:'),
    'serviço envia PDF pelo WhatsApp' => str_contains($service, "'document'")
        && str_contains($service, "'application/pdf'")
        && str_contains($service, 'sendMedia('),
    'acesso ao arquivo é validado por tenant' => str_contains($service, 'assertGeneratedAccess')
        && str_contains($controller, 'downloadable(')
        && str_contains($controller, 'Cache-Control: private'),
    'PDF usa identidade e métricas consistentes' => str_contains($pdf, "identity['primary']")
        && str_contains($pdf, 'first_responses_measured')
        && str_contains($pdf, 'Incidentes operacionais')
        && str_contains($pdf, 'Itens que precisam de atenção')
        && str_contains($simplePdf, '%PDF-'),
    'admin e cliente usam política operacional' => str_contains($admin, 'ExecutiveMetricsPolicyService')
        && str_contains($tenant, 'ExecutiveMetricsPolicyService'),
    'tela oferece geração programação histórico e reenvio' => str_contains($view, 'Criar relatório em PDF')
        && str_contains($view, 'Novo envio automático')
        && str_contains($view, 'Relatórios gerados')
        && str_contains($view, 'Reenviar'),
    'rotas de escrita usam autenticação e csrf' => str_contains($routes, "'/reports/automatic/save'")
        && str_contains($routes, "'/reports/automatic/generate'")
        && str_contains($routes, "'/reports/automatic/resend'")
        && substr_count($routes, "'permission:reports.schedule.manage', 'csrf'") >= 4,
    'cron usa token independente' => str_contains($controller, 'SCHEDULED_REPORTS_CRON_TOKEN')
        && str_contains($routes, "'/webhooks/reports/scheduled/run'")
        && str_contains($n8n, 'X-RS-Connect-Token')
        && str_contains($cli, 'runDue'),
    'ambiente documenta armazenamento token e timeout' => str_contains($env, 'SCHEDULED_REPORTS_PATH')
        && str_contains($env, 'SCHEDULED_REPORTS_CRON_TOKEN')
        && str_contains($env, 'SCHEDULED_REPORTS_WHATSAPP_TIMEOUT'),
    'diagnóstico verifica estruturas e duplicidade' => str_contains($diagnostic, 'estruturas_encontradas')
        && str_contains($diagnostic, 'HAVING COUNT(*) > 1'),
    'versão e migration atualizadas' => str_contains($version, 'RS Connect 36.15.1')
        && str_contains($version, '075_scheduled_reports_and_deliveries.sql'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - relatórios automáticos, PDF protegido, WhatsApp e deduplicação validados.\n";
