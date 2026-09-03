#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'control' => $root . '/app/Services/N8nWorkflowControlService.php',
    'operations' => $root . '/app/Services/OperationsService.php',
    'version' => $root . '/app/Services/AppVersionService.php',
    'audit' => $root . '/bin/monitoring-source-audit.php',
    'ensure' => $root . '/bin/ensure-operations-monitor-workflow.php',
];

foreach ($files as $label => $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "[ERRO] Arquivo ausente ({$label}): {$file}\n");
        exit(1);
    }
}

$content = [];
foreach ($files as $label => $file) {
    $content[$label] = (string) file_get_contents($file);
}

$checks = [
    'lê ID oficial do monitor pelo ambiente' => str_contains($content['control'], "N8N_OPERATIONS_MONITOR_WORKFLOW_ID"),
    'prioriza seleção por ID configurado' => str_contains($content['control'], "'selection_mode'] = 'configured_id'")
        && str_contains($content['control'], '$this->monitorWorkflowId'),
    'mantém fallback por nome somente quando necessário' => str_contains($content['control'], "'selection_mode'] = 'name_fallback'"),
    'bloqueia ambiguidade com múltiplos monitores ativos' => str_contains($content['control'], "'ambiguity_detected'] = true")
        && str_contains($content['control'], 'Há mais de um workflow ativo com identidade de Monitor operacional'),
    'detecta monitor ativo duplicado além do oficial' => str_contains($content['control'], "'duplicate_active_count'")
        && str_contains($content['control'], 'Monitor operacional ativo duplicado além do workflow oficial'),
    'impede autoativação enquanto houver duplicado ativo' => str_contains($content['control'], 'A reativação automática foi bloqueada porque existe outro Monitor operacional ativo'),
    'healthcheck operacional denuncia duplicidade' => str_contains($content['operations'], 'Isso pode executar verificações e notificações em duplicidade'),
    'auditoria pode exigir ID explícito' => str_contains($content['audit'], '--require-monitor-id')
        && str_contains($content['audit'], 'N8N_OPERATIONS_MONITOR_WORKFLOW_ID é obrigatório nesta auditoria'),
    'runner exibe ID oficial e modo de seleção' => str_contains($content['ensure'], 'Workflow oficial configurado')
        && str_contains($content['ensure'], 'Modo de seleção'),
    'tela de versão expõe ID do monitor sem tratá-lo como segredo' => str_contains($content['version'], 'n8n Monitor workflow ID')
        && str_contains($content['version'], "Env::get('N8N_OPERATIONS_MONITOR_WORKFLOW_ID'"),
    'versão 36.27.13 registrada' => str_contains($content['version'], 'RS Connect 36.27.13')
        && str_contains($content['version'], 'Monitor operacional com ID único'),
];

$failed = 0;
foreach ($checks as $label => $ok) {
    if ($ok) {
        echo "[OK] {$label}\n";
        continue;
    }
    echo "[FALHA] {$label}\n";
    $failed++;
}

if ($failed > 0) {
    echo "\nFALHA - {$failed}/" . count($checks) . " verificações não passaram.\n";
    exit(1);
}

echo "\nAPROVADO - " . count($checks) . '/' . count($checks) . " verificações passaram.\n";
exit(0);
