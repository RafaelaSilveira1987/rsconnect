<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$tenantMetrics = file_get_contents($root . '/app/Services/TenantMetricsService.php') ?: '';
$access = file_get_contents($root . '/app/Services/AccessControlService.php') ?: '';
$alerts = file_get_contents($root . '/app/Services/OperationalAlertService.php') ?: '';
$operations = file_get_contents($root . '/app/Services/OperationsService.php') ?: '';
$n8nLive = file_get_contents($root . '/app/Services/N8nLiveMetricsService.php') ?: '';
$admin = file_get_contents($root . '/app/Services/AdminDashboardService.php') ?: '';
$executive = file_get_contents($root . '/app/Services/AdminExecutiveDashboardService.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'fonte canônica de empresas existe' => str_contains($tenantMetrics, 'final class TenantMetricsService'),
    'dashboard administrativo usa a fonte canônica' => str_contains($admin, 'new TenantMetricsService()'),
    'dashboard executivo usa a fonte canônica' => str_contains($executive, 'new TenantMetricsService()'),
    'bloqueio comercial deriva da decisão real de acesso' => str_contains($access, '$status = $this->statusForTenant($tenantId)')
        && str_contains($access, 'isCommercialBlockCode'),
    'vigência encerrada é bloqueio comercial' => str_contains($access, "'subscription_period_expired'"),
    'teste expirado é bloqueio comercial' => str_contains($access, "'trial_expired'"),
    'inadimplência além da tolerância é bloqueio comercial' => str_contains($access, "'invoice_overdue_grace_exceeded'"),
    'suspensão e cancelamento são bloqueios comerciais' => str_contains($access, "'subscription_suspended'")
        && str_contains($access, "'subscription_canceled'"),
    'empresa inativa não é classificada como bloqueio comercial' => str_contains($access, 'FROM tenants WHERE status = "active"'),
    'tolerância de teste continua respeitada pela origem de acesso' => str_contains($access, "'trial_in_grace'")
        && str_contains($access, 'return $base;'),
    'falha de validação comercial não vira zero silencioso' => str_contains($access, "\$code === 'validation_unavailable'")
        && str_contains($access, '$this->blockedTenantsAvailable = false;'),
    'digest separa vigência atraso e suspensão' => str_contains($alerts, 'Vigência/teste encerrado:')
        && str_contains($alerts, 'Inadimplência além da tolerância:')
        && str_contains($alerts, 'Assinatura suspensa/cancelada:'),
    'n8n live usa API v1' => str_contains($n8nLive, "\$baseUrl . '/api/v1'") && str_contains($n8nLive, "/workflows?limit=250"),
    'n8n live autentica com API key' => str_contains($n8nLive, 'X-N8N-API-KEY:')
        && str_contains($n8nLive, "Env::get('N8N_API_KEY'"),
    'monitor usa n8n live como fonte real' => str_contains($operations, 'new N8nLiveMetricsService()')
        && str_contains($operations, 'confirmado(s) diretamente no n8n'),
    'monitor não promove cadastro local a estado real' => str_contains($operations, 'esse número é apenas referência local')
        && str_contains($operations, 'Configure N8N_API_KEY'),
    'versão do pacote incrementada' => str_contains($version, 'RS Connect 36.27.13 — Monitor operacional com ID único'),
    'tela de versão expõe existência da credencial sem revelar segredo' => str_contains($version, "'n8n API key'")
        && str_contains($version, "Env::get('N8N_API_KEY'"),
];

$failures = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures++;
    }
}

echo PHP_EOL;
if ($failures > 0) {
    echo "FALHA - {$failures} verificação(ões) não passaram." . PHP_EOL;
    exit(1);
}

echo "OK - bloqueio comercial e origem live do n8n validados estaticamente." . PHP_EOL;
