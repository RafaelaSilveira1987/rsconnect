<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/app/Controllers/AgentTestController.php');
$view = (string) file_get_contents($root . '/app/Views/agent_tests/index.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'página desabilita cache de formulário antigo' => str_contains($controller, '$this->noStore();') && str_contains($controller, 'Cache-Control: no-store'),
    'endpoint lista assistentes pelo tenant' => str_contains($controller, 'public function agents(): void') && str_contains($controller, '$service->agents($tenantId)'),
    'rota do endpoint de assistentes existe' => str_contains($routes, "'/agent-tests/agents'") && str_contains($routes, "[AgentTestController::class, 'agents']"),
    'view recebe IDs confirmados pelo servidor' => str_contains($view, 'data-server-tenant-id') && str_contains($view, 'data-server-agent-id'),
    'browser restoration é neutralizado' => str_contains($view, 'syncServerSelection') && str_contains($view, "window.addEventListener('pageshow', syncServerSelection)"),
    'troca de empresa recarrega agentes do banco' => str_contains($view, 'data-agent-list-url') && str_contains($view, "cache: 'no-store'") && str_contains($view, 'data.agents'),
    'troca de assistente preserva tenant confirmado' => str_contains($view, 'new URLSearchParams({tenant_id: serverTenantId, agent_id: nextAgentId})'),
    'interface expõe tenant carregado pelo servidor' => str_contains($view, 'Empresa carregada pelo servidor: #<?= $selectedTenantId ?>'),
    'pacote 36.29.x identificado' => str_contains($version, 'RS Connect 36.29.'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - seletor do laboratório não mistura empresa e assistente por restauração visual do navegador.\n";
