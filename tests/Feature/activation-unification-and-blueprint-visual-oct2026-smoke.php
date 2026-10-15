<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Services/VisualBlueprintEditorService.php';

use App\Services\VisualBlueprintEditorService;

function ensure(bool $passed, string $message): void
{
    if (!$passed) throw new RuntimeException($message);
}

$root = dirname(__DIR__, 2);
$layout = file_get_contents($root . '/app/Views/layouts/app.php');
$onboarding = file_get_contents($root . '/app/Views/onboarding/index.php');
$views = file_get_contents($root . '/app/Views/agent_blueprints/index.php');
$controller = file_get_contents($root . '/app/Controllers/AgentBlueprintController.php');
ensure(substr_count($layout, '<span>Preparar atendimento</span>') === 0, 'Menu duplicado ainda visível');
ensure(str_contains($onboarding, 'Configurar atendimento →'), 'Configuração expressa não integrada à Ativação');
ensure(str_contains($views, 'editor_mode'), 'Editor visual não disponível');
ensure(str_contains($controller, 'base_version_id'), 'Proteção contra versão desatualizada ausente');

$input = [
    'interaction_mode' => 'form', 'calendar_pre_schedule' => '1',
    'questions' => [
        'requester_name' => ['prompt' => 'Qual é o seu nome?', 'required_before_schedule' => '1'],
        'brief_demand' => ['prompt' => 'Como podemos ajudar?', 'required_before_schedule' => '1'],
    ],
];
$compiler = new VisualBlueprintEditorService();
$result = $compiler->compile([], $input);
ensure(($result['interaction_mode'] ?? '') === 'form', 'Ritmo não aplicado');
ensure($result['capabilities']['calendar.pre_schedule'] === true, 'Agenda não habilitada');
ensure($result['capabilities']['calendar.confirm'] === false, 'Confirmação automática não pode ligar');
ensure($result['capabilities']['calendar.human_approval'] === true, 'Aprovação humana obrigatória');
ensure($result['workflow'][count($result['workflow']) - 1]['key'] === 'human_approval', 'Falta aprovação no fluxo');
ensure($result['triage_fields'][0]['prompt'] === 'Qual é o seu nome?', 'Pergunta não aplicada');

$previous = $result;
$previous['custom_rule'] = ['risk_level' => 2];
$previous['capabilities']['handoff.audio'] = true;
$previous['triage_fields'][0]['other_value'] = 'preserve';
$input['calendar_pre_schedule'] = '0';
$result = $compiler->compile($previous, $input);
ensure($result['custom_rule']['risk_level'] === 2, 'Perdeu configuração avançada');
ensure($result['capabilities']['handoff.audio'] === true, 'Perdeu capability não administrada');
ensure($result['triage_fields'][0]['other_value'] === 'preserve', 'Perdeu detalhes internos de campo');
ensure($result['capabilities']['calendar.pre_schedule'] === false, 'Agenda não desligada');
ensure($result['capabilities']['calendar.confirm'] === false, 'Confirmação indevidamente habilitada');
$keys = array_column($result['workflow'], 'key');
ensure(!in_array('calendar', $keys, true) && !in_array('human_approval', $keys, true), 'Etapas da agenda não desligadas');
try {
    $compiler->compile($result, ['interaction_mode' => 'form', 'calendar_pre_schedule' => '0',
        'questions' => ['untrusted_key' => ['prompt' => 'Teste', 'required_before_schedule' => '0']]]);
    throw new RuntimeException('Aceitou campo inexistente');
} catch (InvalidArgumentException) {}

$custom = $result;
$custom['workflow'][] = ['key' => 'custom_business_action', 'label' => 'Ação especial', 'type' => 'action', 'position' => 100];
try {
    $compiler->compile($custom, ['interaction_mode' => 'form', 'calendar_pre_schedule' => '0']);
    throw new RuntimeException('Editor visual modificou fluxo especial');
} catch (InvalidArgumentException) {}

echo "OK - Ativação unificada e blueprint visual seguro\n";
