<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentBlueprintService;

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) $failures[] = $label;
};

$root = dirname(__DIR__, 2);
$serviceSource = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$controller = (string) file_get_contents($root . '/app/Controllers/AgentController.php');
$agentsView = (string) file_get_contents($root . '/app/Views/agents/index.php');
$companyView = (string) file_get_contents($root . '/app/Views/companies/settings.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$js = (string) file_get_contents($root . '/public/assets/js/app.js');

$assert(str_contains($serviceSource, 'updateWorkflowConfiguration'), 'serviço deve salvar personalização da ordem');
$assert(str_contains($serviceSource, 'applyWorkflowOrderToTriageFields'), 'ordem do fluxo deve influenciar coleta');
$assert(str_contains($controller, 'updateOperationalRules'), 'controller deve permitir regras operacionais na tela de agentes');
$assert(str_contains($routes, "'/agents/operational-rules'"), 'rota de regras operacionais deve existir');
$assert(str_contains($agentsView, 'Regras do atendimento'), 'cliente deve ver regras na tela de agentes');
$assert(str_contains($agentsView, 'Troca de segmento, modelo-base, versão e proteções técnicas'), 'tela deve explicar o que fica no RS Admin');
$assert(str_contains($agentsView, 'policy.fail_closed'), 'proteção fail-closed deve continuar representada');
$assert(str_contains($agentsView, 'Serviço de IA protegido'), 'cliente deve ver provedor/modelo como configuração técnica protegida');
$assert(str_contains($agentsView, 'Integrações técnicas protegidas'), 'integração externa existente deve ficar protegida para o cliente');
$assert(str_contains($controller, 'technicalCurrent'), 'backend deve preservar integração técnica quando cliente salvar o assistente');
$assert(str_contains($companyView, 'data-workflow-list'), 'RS Admin deve usar o novo editor responsivo de fluxo');
$assert(str_contains($css, '.agent-workflow-editor'), 'layout deve abandonar corredor horizontal');
$assert(str_contains($js, 'data-workflow-move'), 'editor deve permitir mover etapas');

$service = new AgentBlueprintService();
$method = new ReflectionMethod($service, 'applyWorkflowOrderToTriageFields');
$method->setAccessible(true);

$fields = [
    ['field_key' => 'requester_name', 'position' => 10],
    ['field_key' => 'is_for_self', 'position' => 20],
    ['field_key' => 'patient_age', 'position' => 30],
    ['field_key' => 'modality', 'position' => 40],
    ['field_key' => 'brief_demand', 'position' => 50],
];
$workflow = [
    ['step_key' => 'collect_modality', 'step_type' => 'collect', 'active' => 1, 'config' => []],
    ['step_key' => 'identify_intent', 'step_type' => 'collect', 'active' => 1, 'config' => []],
    ['step_key' => 'identify_subject', 'step_type' => 'collect', 'active' => 1, 'config' => []],
    ['step_key' => 'collect_age', 'step_type' => 'collect', 'active' => 1, 'config' => []],
    ['step_key' => 'eligibility', 'step_type' => 'policy', 'active' => 1, 'config' => []],
    ['step_key' => 'collect_demand', 'step_type' => 'collect', 'active' => 1, 'config' => []],
];

$ordered = $method->invoke($service, $fields, $workflow);
$keys = array_column($ordered, 'field_key');

$assert(($keys[0] ?? '') === 'modality', 'modalidade movida para o início deve ser pedida primeiro');
$assert(array_search('requester_name', $keys, true) < array_search('patient_age', $keys, true), 'ordem configurada deve ser preservada');
$assert(array_search('patient_age', $keys, true) < array_search('brief_demand', $keys, true), 'idade deve vir antes da demanda conforme fluxo');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - ordem do atendimento editável, efetiva e disponível ao cliente validada.\n";
