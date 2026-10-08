<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/AutomationWebhookService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/N8nFlowController.php');
$automationController = (string) file_get_contents($root . '/app/Controllers/AutomationController.php');
$hub = (string) file_get_contents($root . '/app/Views/n8n_flows/hub.php');
$index = (string) file_get_contents($root . '/app/Views/n8n_flows/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$failed = 0;
$check = static function (bool $condition, string $label) use (&$failed): void {
    if ($condition) {
        echo "[OK] {$label}\n";
        return;
    }
    $failed++;
    echo "[FALHA] {$label}\n";
};

$check(
    str_contains($service, 'WHERE tenant_id = :tenant_id')
        && str_contains($service, 'AND status = "active"')
        && str_contains($service, 'private function flowsForEvent'),
    'disparo automático seleciona somente fluxos ativos da empresa'
);

$tenantBlockStart = strpos($service, 'if ($tenantId > 0) {', strpos($service, 'public function dispatch'));
$fallbackStart = strpos($service, '$fallback = trim((string) Env::get(\'N8N_WEBHOOK_URL\'', (int) $tenantBlockStart);
$tenantBlock = ($tenantBlockStart !== false && $fallbackStart !== false)
    ? substr($service, $tenantBlockStart, $fallbackStart - $tenantBlockStart)
    : '';
$check(
    str_contains($tenantBlock, 'return $results;')
        && !str_contains($tenantBlock, "'Nenhum fluxo n8n ativo para este evento/empresa.'"),
    'empresa sem fluxo ativo encerra sem skipped e sem cair no webhook global'
);

$check(
    str_contains($service, "SELECT id, flow_key, template_key, name, events_json, status, webhook_url_encrypted")
        && str_contains($service, "if ((string) (\$flow['status'] ?? '') !== 'active')")
        && str_contains($service, 'Fluxo inativo: nenhuma chamada automática foi realizada.'),
    'URL explícita legada também respeita o status inativo do fluxo cadastrado'
);

$check(
    str_contains($service, '$isManualTest = $event === \'n8n.flow.test\';')
        && str_contains($service, 'if ($isManualTest)')
        && str_contains($service, 'Teste manual é deliberado'),
    'botão Testar fluxo continua permitido mesmo com fluxo inativo'
);

$check(
    !str_contains($service, "\$this->log(\$tenantId, null, \$event, 'skipped'")
        && str_contains($controller, "WHERE l.status IN (\"success\", \"error\")")
        && str_contains($controller, "status IN ('success','error')"),
    'painéis de execução consideram somente chamadas HTTP realmente tentadas'
);

$check(
    str_contains($hub, 'Somente chamadas HTTP realmente tentadas')
        && str_contains($index, 'fluxos inativos não geram execução'),
    'interface explica o novo contrato operacional'
);

$check(
    str_contains($automationController, "'n8nConfigured' => \$tenantN8nFlows > 0,")
        && !str_contains($automationController, "N8N_WEBHOOK_URL', '')) !== ''"),
    'tela de automações considera apenas integrações ativas por empresa'
);

$check(
    str_contains($version, "RS Connect 36.42.4 — Fluxos inativos isolados por empresa")
        && ($manifest['package_version'] ?? '') === '36.42.4'
        && ($manifest['database']['required_migration'] ?? '') === '124_calendar_slot_capacity_mode.sql',
    'release 36.42.4 registrada sem migration nova'
);

exit($failed > 0 ? 1 : 0);
