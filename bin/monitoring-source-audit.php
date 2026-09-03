<?php

declare(strict_types=1);

use App\Core\Autoloader;
use App\Core\Database;
use App\Core\Env;
use App\Services\AccessControlService;
use App\Services\N8nLiveMetricsService;
use App\Services\N8nWorkflowControlService;
use App\Services\TenantMetricsService;

$root = dirname(__DIR__);
require_once $root . '/app/Core/Autoloader.php';
Autoloader::register($root . '/app');
Env::load($root . '/.env');

$requireN8n = in_array('--require-n8n-live', $argv, true);
$requireMonitor = in_array('--require-monitor-active', $argv, true);
$requireMonitorId = in_array('--require-monitor-id', $argv, true);
$failures = 0;

echo "==================================================\n";
echo "RS CONNECT — AUDITORIA DE FONTES DO MONITOR\n";
echo "==================================================\n\n";

try {
    $tenantCounts = (new TenantMetricsService())->counts();
    if (($tenantCounts['available'] ?? false) !== true) {
        echo "[ERRO] Não foi possível obter a contagem canônica de empresas.\n";
        $failures++;
    } else {
        echo '[OK] Empresas: total=' . (int) ($tenantCounts['total'] ?? 0)
            . ' | ativas=' . (int) ($tenantCounts['active'] ?? 0)
            . ' | não ativas=' . (int) ($tenantCounts['non_active'] ?? 0) . "\n";
    }
} catch (Throwable $exception) {
    echo '[ERRO] Empresas: ' . $exception->getMessage() . "\n";
    $failures++;
}

try {
    $accessSummary = (new AccessControlService())->securitySummary();
    if (($accessSummary['blocked_tenants_available'] ?? false) !== true) {
        echo "[ERRO] Fonte de bloqueios comerciais indisponível.\n";
        $failures++;
    } else {
        $blocked = is_array($accessSummary['blocked_tenants'] ?? null) ? $accessSummary['blocked_tenants'] : [];
        echo '[OK] Bloqueios comerciais reais: ' . count($blocked) . "\n";
        foreach ($blocked as $tenant) {
            echo '     - ' . trim((string) ($tenant['name'] ?? 'Empresa'))
                . ' | ' . trim((string) ($tenant['access_code'] ?? ''))
                . ' | ' . trim((string) ($tenant['access_title'] ?? 'Acesso bloqueado')) . "\n";
        }
    }
} catch (Throwable $exception) {
    echo '[ERRO] Bloqueios comerciais: ' . $exception->getMessage() . "\n";
    $failures++;
}

try {
    $pdo = Database::connection();
    $local = 0;
    foreach (['n8n_tenant_flows', 'n8n_flows'] as $table) {
        try {
            $local += (int) $pdo->query("SELECT COUNT(*) FROM {$table} WHERE status = 'active'")->fetchColumn();
        } catch (Throwable) {
        }
    }

    $live = (new N8nLiveMetricsService())->snapshot();
    if (!empty($live['available'])) {
        echo '[OK] n8n live: total=' . (int) ($live['total'] ?? 0)
            . ' | ativos=' . (int) ($live['active'] ?? 0)
            . ' | inativos=' . (int) ($live['inactive'] ?? 0)
            . ' | arquivados=' . (int) ($live['archived'] ?? 0)
            . ' | cadastros locais ativos=' . $local . "\n";
        if ((int) ($live['active'] ?? 0) !== $local) {
            echo "[ATENCAO] Há divergência entre o n8n real e o cadastro local do RS Connect.\n";
        }
    } else {
        echo '[ATENCAO] n8n live não confirmado: ' . (string) ($live['error'] ?? 'sem detalhe') . "\n";
        echo '[INFO] Cadastros locais marcados como ativos: ' . $local . "\n";
        if ($requireN8n) {
            $failures++;
        }
    }
} catch (Throwable $exception) {
    echo '[ERRO] n8n: ' . $exception->getMessage() . "\n";
    if ($requireN8n) {
        $failures++;
    }
}


try {
    $monitor = (new N8nWorkflowControlService())->operationsMonitor(false);
    $configuredId = trim((string) ($monitor['configured_workflow_id'] ?? ''));
    $selectionMode = (string) ($monitor['selection_mode'] ?? '-');

    echo '[INFO] Monitor oficial configurado: ' . ($configuredId !== '' ? $configuredId : 'NÃO')
        . ' | seleção=' . $selectionMode . "\n";

    if ($requireMonitorId && $configuredId === '') {
        echo "[ERRO] N8N_OPERATIONS_MONITOR_WORKFLOW_ID é obrigatório nesta auditoria.\n";
        $failures++;
    }

    $duplicateCount = max(0, (int) ($monitor['duplicate_active_count'] ?? 0));
    if ($duplicateCount > 0) {
        echo '[ERRO] Há ' . $duplicateCount . " Monitor operacional ativo duplicado no n8n.\n";
        foreach (($monitor['duplicate_active_workflows'] ?? []) as $duplicate) {
            if (!is_array($duplicate)) {
                continue;
            }
            echo '     - ' . trim((string) ($duplicate['id'] ?? '-'))
                . ' | ' . trim((string) ($duplicate['name'] ?? 'RS Connect - Monitor operacional')) . "\n";
        }
        if ($requireMonitor) {
            $failures++;
        }
    }

    if (!empty($monitor['available']) && !empty($monitor['found'])) {
        echo '[OK] Monitor n8n encontrado: ' . ($monitor['workflow_name'] ?? 'RS Connect - Monitor operacional')
            . ' | ID=' . ($monitor['workflow_id'] ?? '-')
            . ' | ativo=' . (!empty($monitor['active']) ? 'sim' : 'não')
            . ' | agenda=' . (!empty($monitor['schedule_trigger_present']) ? 'sim' : 'não') . "\n";
        if (!empty($monitor['last_execution_available'])) {
            echo '     Última execução: ' . ($monitor['last_execution_started_at'] ?? '-')
                . ' | status=' . ($monitor['last_execution_status'] ?? '-') . "\n";
        }
        if ($requireMonitor && (empty($monitor['active']) || empty($monitor['schedule_trigger_present']) || $duplicateCount > 0)) {
            if ($duplicateCount === 0) {
                $failures++;
            }
        }
    } else {
        echo '[ATENCAO] Monitor operacional do n8n não confirmado: ' . ($monitor['error'] ?? 'sem detalhe') . "\n";
        if ($requireMonitor) {
            $failures++;
        }
    }
} catch (Throwable $exception) {
    echo '[ERRO] Monitor operacional n8n: ' . $exception->getMessage() . "\n";
    if ($requireMonitor) {
        $failures++;
    }
}

echo "\n";
if ($failures > 0) {
    echo "FALHA - {$failures} fonte(s) obrigatória(s) não foram validadas.\n";
    exit(1);
}

echo "APROVADO - fontes cadastrais e comerciais validadas" . ($requireN8n ? ', incluindo n8n live' : '') . ($requireMonitor ? ', com Monitor operacional ativo' : '') . ".\n";
