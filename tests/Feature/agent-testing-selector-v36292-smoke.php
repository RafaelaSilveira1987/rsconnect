<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = (string) file_get_contents($root . '/app/Controllers/AgentTestController.php');
$view = (string) file_get_contents($root . '/app/Views/agent_tests/index.php');
$cli = (string) file_get_contents($root . '/bin/test-agent.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'tenant solicitado é validado contra lista real' => str_contains($controller, '$validTenantIds') && str_contains($controller, 'in_array($tenantId, $validTenantIds, true)'),
    'agente solicitado é validado contra empresa selecionada' => str_contains($controller, '$validAgentIds') && str_contains($controller, 'in_array($selectedAgentId, $validAgentIds, true)'),
    'seletores de empresa e assistente usam formulários separados' => substr_count($view, 'class="agent-lab-select-form"') >= 2,
    'troca de empresa não reaproveita agent_id antigo' => str_contains($view, '<select name="tenant_id" required onchange="this.form.submit()">'),
    'troca de assistente preserva tenant correto' => str_contains($view, '<input type="hidden" name="tenant_id" value="<?= $selectedTenantId ?>">'),
    'lista mostra quantidade de assistentes' => str_contains($view, 'assistentes encontrados nesta empresa') && str_contains($view, 'apenas 1 assistente cadastrado'),
    'assistente atual fica visível no simulador' => str_contains($view, 'Assistente atual: #'),
    'versão do laboratório fica visível' => str_contains($view, 'Lab <?= View::e($labVersion) ?>'),
    'CLI expõe versão instalada' => str_contains($cli, "'version'") && str_contains($cli, 'RS Connect Agent Lab'),
    'pacote 36.29.2 identificado' => str_contains($version, 'RS Connect 36.29.2'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - laboratório permite trocar empresa/assistente sem carregar seleção inválida anterior.\n";
