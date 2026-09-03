<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = 0;
$failures = 0;

$assertContains = static function (string $file, string $needle, string $label) use (&$checks, &$failures): void {
    $checks++;
    $content = @file_get_contents($file);
    if (!is_string($content) || !str_contains($content, $needle)) {
        $failures++;
        echo "[FALHA] {$label}\n";
        return;
    }
    echo "[OK] {$label}\n";
};

$service = $root . '/app/Services/N8nWorkflowControlService.php';
$ops = $root . '/app/Services/OperationsService.php';
$version = $root . '/app/Services/AppVersionService.php';
$runner = $root . '/bin/ensure-operations-monitor-workflow.php';

$assertContains($service, '/workflows/\' . rawurlencode($workflowId) . \'/activate', 'usa endpoint público de ativação do n8n');
$assertContains($service, 'RS Connect - Monitor operacional', 'localiza o workflow crítico pelo nome');
$assertContains($service, 'n8n-nodes-base.scheduleTrigger', 'valida Schedule Trigger');
$assertContains($service, '/executions?limit=5&workflowId=', 'consulta última execução quando permitido');
$assertContains($runner, 'operationsMonitor($activate)', 'runner permite reativação explícita');
$assertContains($ops, 'Enquanto permanecer assim, as verificações automáticas não disparam.', 'healthcheck denuncia monitor inativo');
$assertContains($ops, 'gatilho de agenda/cron não foi encontrado', 'healthcheck denuncia ausência de agenda');
$assertContains($version, 'RS Connect 36.27.13', 'versão 36.27.13 registrada');

if ($failures > 0) {
    echo "\nFALHA - {$failures}/{$checks} verificações falharam.\n";
    exit(1);
}

echo "\nAPROVADO - {$checks}/{$checks} verificações passaram.\n";
