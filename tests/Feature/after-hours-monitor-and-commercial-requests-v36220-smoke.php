<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];
$passes = 0;
$check = static function (bool $condition, string $label) use (&$failures, &$passes): void {
    if ($condition) {
        $passes++;
        echo "[OK] {$label}\n";
        return;
    }
    $failures[] = $label;
    echo "[FALHA] {$label}\n";
};

$migration = (string) file_get_contents($root . '/database/migrations/091_after_hours_monitor_and_quote_requests.sql');
$manifest = (string) file_get_contents($root . '/database/migrations/manifest.php');
$monitor = (string) file_get_contents($root . '/app/Services/AfterHoursMonitorService.php');
$monitorView = (string) file_get_contents($root . '/app/Views/operations/ai_reprocess.php');
$worker = (string) file_get_contents($root . '/bin/ai-after-hours-recovery.php');
$request = (string) file_get_contents($root . '/app/Services/CommercialRequestService.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$conversation = (string) file_get_contents($root . '/app/Views/conversations/index.php');
$crm = (string) file_get_contents($root . '/app/Views/crm/pipeline.php');
$dashboard = (string) file_get_contents($root . '/app/Views/dashboard/client.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$check(str_contains($migration, 'ai_after_hours_monitor_settings') && str_contains($migration, 'crm_commercial_requests'), 'migration cria monitor e pendências comerciais');
$check(str_contains($manifest, "'sequence' => 98") && str_contains($manifest, '091_after_hours_monitor_and_quote_requests.sql'), 'manifest registra a migration 091');
$check(str_contains($monitor, 'GET_LOCK') && str_contains($monitor, 'interval_minutes'), 'monitor usa trava e intervalo configurável');
$check(str_contains($monitorView, 'Retomada pós-horário') && str_contains($monitorView, 'ai-after-hours-recovery.php'), 'tela operacional mostra monitor e comando EasyPanel');
$check(str_contains($worker, 'AfterHoursMonitorService') && str_contains($worker, 'cli_after_hours_monitor'), 'worker CLI executa a recuperação pós-horário');
$check(str_contains($routes, '/webhooks/ai-after-hours-recovery') && str_contains($routes, '/operations/ai-after-hours/run'), 'rotas HTTP e manual do monitor existem');
$check(str_contains($request, 'sim por favor') && str_contains($request, 'context_rule'), 'detecção contextual reconhece confirmação de orçamento');
$check(str_contains($request, 'activeRequest') && str_contains($request, 'removePendingTagIfUnused'), 'serviço evita duplicatas e remove tag somente quando seguro');
$check(str_contains($webhook, "'phase' => 'commercial_request'") && str_contains($webhook, 'CommercialRequestService'), 'webhook isola a criação da pendência comercial');
$check(str_contains($conversation, 'O cliente pediu um orçamento') && str_contains($conversation, 'Marcar orçamento atendido'), 'conversa exibe alerta acionável');
$check(str_contains($crm, 'Solicitações de orçamento') && str_contains($crm, 'response_sla_minutes'), 'CRM permite configurar orçamento e SLA');
$check(str_contains($dashboard, 'Orçamentos pendentes') && str_contains($dashboard, 'queue=quote_pending'), 'dashboard destaca orçamentos aguardando retorno');
$check(str_contains($version, 'RS Connect 36.22.0') && str_contains($version, '091_after_hours_monitor_and_quote_requests.sql'), 'versão e migration obrigatória estão atualizadas');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nResumo v36.22.0: {$passes} verificações aprovadas.\n";
