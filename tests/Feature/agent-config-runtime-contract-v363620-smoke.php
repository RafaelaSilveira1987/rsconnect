<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentRuntimeConfigurationService;

$root = dirname(__DIR__, 2);
$blueprint = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$triage = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$flow = (string) file_get_contents($root . '/app/Services/ConversationFlowService.php');
$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/119_agent_workflow_runtime_contract.sql');
$manifest = (string) file_get_contents($root . '/database/migrations/manifest.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$check(str_contains($migration, "'$.field_keys'") && str_contains($migration, "'$.action_key'"), 'migration persiste vínculo etapa/campo e ação no banco');
$check(str_contains($manifest, "119_agent_workflow_runtime_contract.sql"), 'manifest inclui migration 119');
$check(str_contains($version, "REQUIRED_MIGRATION = '119_agent_workflow_runtime_contract.sql'"), 'runtime exige o contrato novo');
$check(!str_contains($blueprint, '$fallbackMap'), 'runtime não usa mapa hardcoded de step_key para campos');
$check(str_contains($blueprint, "config['field_keys']") && str_contains($blueprint, 'applyWorkflowOrderToTriageFields'), 'ordem usa config_json persistido');
$check(!preg_match('/ansiedade\|depress\|sofrimento\|emocion\|relacionamento\|luto\|crise/u', $triage), 'triagem não infere demanda por sintomas hardcoded');
$check(!str_contains($flow, '$signals ='), 'fluxo não usa lista clínica hardcoded para preencher demanda');
$check(str_contains($triage, 'AgentRuntimeConfigurationService') && str_contains($triage, 'agent_configuration_invalid'), 'configuração inválida falha de forma segura antes da IA');
$check(str_contains($view, 'Validação antes de atender') && str_contains($view, 'Informações desta etapa'), 'tela mostra contrato executável e vínculo da etapa');

$audit = new AgentRuntimeConfigurationService();
$valid = $audit->audit([
    'workflow' => [
        ['step_key'=>'who','label'=>'Quem será atendido','step_type'=>'collect','config'=>['field_keys'=>['is_for_self']],'active'=>1,'position'=>10],
        ['step_key'=>'demand','label'=>'Demanda','step_type'=>'collect','config'=>['field_keys'=>['brief_demand']],'active'=>1,'position'=>20],
        ['step_key'=>'calendar','label'=>'Agenda','step_type'=>'action','config'=>['action_key'=>'calendar.pre_schedule'],'active'=>1,'position'=>30],
    ],
    'triage_fields' => [
        ['field_key'=>'is_for_self','label'=>'Quem será atendido','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'prompt_text'=>'Para quem é o atendimento?'],
        ['field_key'=>'brief_demand','label'=>'Demanda','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'prompt_text'=>'Qual é a sua necessidade?'],
    ],
]);
$check(!empty($valid['ok']) && empty($valid['errors']), 'auditor aceita fluxo coerente configurado');

$invalid = $audit->audit([
    'workflow' => [
        ['step_key'=>'calendar','label'=>'Agenda','step_type'=>'action','config'=>['action_key'=>'calendar.pre_schedule'],'active'=>1,'position'=>10],
        ['step_key'=>'demand','label'=>'Demanda','step_type'=>'collect','config'=>['field_keys'=>['brief_demand']],'active'=>1,'position'=>20],
    ],
    'triage_fields' => [
        ['field_key'=>'brief_demand','label'=>'Demanda','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'prompt_text'=>'Qual é a sua necessidade?'],
    ],
]);
$check(empty($invalid['ok']) && $invalid['errors'] !== [], 'auditor bloqueia campo obrigatório posicionado depois da agenda');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - contrato de runtime orientado pela configuração validado.\n";
