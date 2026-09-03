#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Env;
use App\Services\N8nWorkflowControlService;

$root = dirname(__DIR__);
require_once $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Env::load($root . '/.env');

$activate = in_array('--activate', $argv, true);
$status = (new N8nWorkflowControlService())->operationsMonitor($activate);

echo "==================================================\n";
echo "RS CONNECT — MONITOR OPERACIONAL NO N8N\n";
echo "==================================================\n\n";

if (!($status['configured'] ?? false)) {
    echo '[ERRO] ' . ($status['error'] ?? 'N8N_BASE_URL/N8N_API_KEY não configuradas.') . "\n";
    exit(2);
}
if (!($status['available'] ?? false)) {
    echo '[ERRO] ' . ($status['error'] ?? 'API do n8n indisponível.') . "\n";
    exit(2);
}
if (!($status['found'] ?? false)) {
    echo '[ERRO] ' . ($status['error'] ?? 'Workflow do monitor não encontrado.') . "\n";
    exit(3);
}

echo '[OK] Workflow: ' . ($status['workflow_name'] ?? 'Monitor operacional')
    . ' | ID=' . ($status['workflow_id'] ?? '-') . "\n";
echo '[INFO] Publicado/ativo: ' . (!empty($status['active']) ? 'SIM' : 'NÃO') . "\n";
echo '[INFO] Gatilho de agenda/cron: ' . (!empty($status['schedule_trigger_present']) ? 'SIM' : 'NÃO') . "\n";

if (!empty($status['activation_attempted'])) {
    echo '[INFO] Reativação solicitada pela API pública do n8n: '
        . (!empty($status['activation_succeeded']) ? 'CONFIRMADA' : 'NÃO CONFIRMADA') . "\n";
}

if (!empty($status['last_execution_available'])) {
    echo '[INFO] Última execução conhecida: '
        . ($status['last_execution_started_at'] ?? '-')
        . ' | status=' . ($status['last_execution_status'] ?? '-')
        . ' | mode=' . ($status['last_execution_mode'] ?? '-') . "\n";
} else {
    echo "[INFO] Histórico de execuções não disponível pela chave atual ou ainda sem execução registrada.\n";
}

if (!empty($status['error'])) {
    echo '[ERRO] ' . $status['error'] . "\n";
    exit(4);
}
if (empty($status['active'])) {
    echo "[ERRO] O workflow continua inativo.\n";
    exit(5);
}
if (empty($status['schedule_trigger_present'])) {
    echo "[ERRO] O workflow está ativo, mas não possui gatilho de agenda/cron reconhecido.\n";
    exit(6);
}

echo "\nAPROVADO - Monitor operacional publicado e com gatilho automático.\n";
exit(0);
