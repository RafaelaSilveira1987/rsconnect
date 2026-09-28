<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$agentController = (string) file_get_contents($root . '/app/Controllers/AgentController.php');
$companyController = (string) file_get_contents($root . '/app/Controllers/CompanyController.php');
$agentView = (string) file_get_contents($root . '/app/Views/agents/index.php');
$companyView = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$js = (string) file_get_contents($root . '/public/assets/js/app.js');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'UI do agente mostra exclusão somente para coleta' =>
        str_contains($agentView, 'data-workflow-delete')
        && str_contains($agentView, '$workflowType === \'collect\''),
    'UI administrativa também oferece exclusão de coleta' =>
        str_contains($companyView, 'data-workflow-delete')
        && str_contains($companyView, '$workflowType === \'collect\''),
    'frontend confirma e marca exclusão explicitamente' =>
        str_contains($js, 'workflow_delete_keys[]')
        && str_contains($js, 'window.confirm')
        && str_contains($js, 'step.remove()'),
    'controllers encaminham lista de exclusão' =>
        str_contains($agentController, 'workflow_delete_keys')
        && str_contains($companyController, 'workflow_delete_keys'),
    'backend possui exclusão protegida' =>
        str_contains($service, 'deleteWorkflowSteps')
        && str_contains($service, 'Somente etapas de coleta podem ser excluídas'),
    'campos órfãos são desativados sem apagar cadastro' =>
        str_contains($service, 'orphanedFieldKeysAfterWorkflowDeletion')
        && str_contains($service, 'required_for_completion = 0')
        && str_contains($service, 'active = 0'),
    'compatibilidade de demanda não recria etapa apagada' =>
        str_contains($service, "in_array('brief_demand', \$orphanedByDeletion, true)")
        && str_contains($service, "['demand']['enabled'] = false"),
    'estilo do botão está presente' => str_contains($css, '.workflow-delete-btn'),
    'cache de CSS e JS foi renovado' => str_contains($layout, 'app.css?v=36.37.1') && str_contains($layout, 'app.js?v=36.37.1'),
];

$fail = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $fail[] = $label;
    }
}

if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - exclusão segura da Ordem do atendimento presente no pacote.\n";
