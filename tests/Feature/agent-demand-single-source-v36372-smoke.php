<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentConversationBehaviorService;
use App\Services\AgentTriageService;

$root = dirname(__DIR__, 2);
$behaviorService = new AgentConversationBehaviorService();

$profile = [
    'interaction_mode' => 'hybrid',
    'config' => [
        'conversation_behavior' => [
            'demand' => [
                'enabled' => true,
                'required_before_schedule' => true,
                'prompt' => 'Pergunta histórica que não deve antecipar o roteiro.',
            ],
            'service_mode' => ['mode' => 'choice', 'fixed_modality' => 'presencial'],
            'modalities' => [
                'online' => ['enabled' => true],
                'presencial' => ['enabled' => true],
            ],
        ],
    ],
    'triage_fields' => [
        ['field_key' => 'patient_age', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'prompt_text' => 'Qual é a idade?'],
        ['field_key' => 'brief_demand', 'active' => 0, 'required_before_schedule' => 0, 'required_for_completion' => 0, 'prompt_text' => 'Qual é o motivo?'],
    ],
];

$effective = $behaviorService->applyOperationalOverridesToProfile($profile);
$fields = [];
foreach (($effective['triage_fields'] ?? []) as $field) {
    if (is_array($field)) $fields[(string) ($field['field_key'] ?? '')] = $field;
}
$promptBlock = $behaviorService->promptBlock($profile);
$runtimeProfile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'capabilities' => ['triage.enabled' => true, 'eligibility.enabled' => false],
    'config' => $profile['config'],
    'triage_fields' => [
        ['field_key' => 'is_for_self', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'prompt_text' => 'O atendimento é para você?'],
        ['field_key' => 'patient_age', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'prompt_text' => 'Qual é a idade?'],
        ['field_key' => 'brief_demand', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'prompt_text' => 'Qual é o motivo?'],
        ['field_key' => 'modality', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'prompt_text' => 'Online ou presencial?'],
    ],
    'policies' => [],
];
$triage = new AgentTriageService();
$turn1 = $triage->simulateTurn($runtimeProfile, ['collected' => [], 'current_field_key' => 'is_for_self'], 'Sim, seria para mim');
$turn2 = $triage->simulateTurn($runtimeProfile, (array) ($turn1['state'] ?? []), '25 anos');
$turn3 = $triage->simulateTurn($runtimeProfile, (array) ($turn2['state'] ?? []), 'Perdi alguém muito importante e quero apoio para lidar com isso.');
$turn4 = $triage->simulateTurn($runtimeProfile, (array) ($turn3['state'] ?? []), 'Prefiro online');

$behaviorPhp = (string) file_get_contents($root . '/app/Services/AgentConversationBehaviorService.php');
$blueprintPhp = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$flowPhp = (string) file_get_contents($root . '/app/Services/ConversationFlowService.php');
$triagePhp = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$aiPhp = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$viewPhp = (string) file_get_contents($root . '/app/Views/agents/index.php');
$cachePhp = (string) file_get_contents($root . '/app/Services/AiExactCacheService.php');
$versionPhp = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'comportamento histórico não reativa brief_demand fora do workflow' => empty($fields['brief_demand']['active']),
    'comportamento histórico não torna brief_demand obrigatório fora do workflow' => empty($fields['brief_demand']['required_before_schedule']),
    'prompt operacional manda seguir somente a Ordem do atendimento' => str_contains($promptBlock, 'siga exclusivamente a próxima informação indicada pela Ordem do atendimento'),
    'prompt operacional não injeta a antiga regra autônoma de demanda' => !str_contains($promptBlock, 'Demanda: para novo lead/interessado'),
    'fluxo com comportamento legado ligado ainda segue idade antes da demanda' => ($turn1['state']['current_field_key'] ?? '') === 'patient_age',
    'demanda só aparece quando o cursor chega à etapa configurada' => ($turn2['state']['current_field_key'] ?? '') === 'brief_demand',
    'resposta da demanda é registrada uma vez e avança para modalidade' => ($turn3['state']['current_field_key'] ?? '') === 'modality' && trim((string) ($turn3['collected']['brief_demand'] ?? '')) !== '',
    'depois da modalidade a demanda não volta a ser perguntada' => ($turn4['state']['current_field_key'] ?? '') !== 'brief_demand',
    'salvamento não sincroniza mais conversation_behavior para brief_demand' => str_contains($blueprintPhp, 'conversation_behavior não sincroniza mais brief_demand')
        && !str_contains($blueprintPhp, 'active_update\' => $enabled ? 1 : 0'),
    'workflow desativa campos fora de etapas ativas' => str_contains($blueprintPhp, 'SET required_before_schedule = 0, active = 0')
        && str_contains($blueprintPhp, 'tudo que não está em uma etapa de Coleta ativa'),
    'regra por grupo é subordinada ao workflow quando ele existe' => str_contains($flowPhp, 'workflowDemandPolicy')
        && str_contains($flowPhp, "['require_demand_before_pre_schedule'] = !empty(\$workflowDemand['required'])"),
    'triagem não consulta conversation_behavior para decidir demanda' => str_contains($triagePhp, 'demanda segue a mesma regra de qualquer outra informação do')
        && !str_contains($triagePhp, '$behaviorDemandRequired'),
    'modelo não recria exigência de demanda pela camada de comportamento' => str_contains($aiPhp, 'Não reintroduza aqui uma')
        && !str_contains($aiPhp, '$demandBehavior = is_array($behavior[\'demand\'] ?? null)'),
    'UI com workflow transforma Entender demanda em status, não segundo controle' => str_contains($viewPhp, 'Seguindo a Ordem do atendimento')
        && str_contains($viewPhp, 'Esta seção não cria mais uma segunda trava de demanda.'),
    'UI mantém compatibilidade de demanda somente sem workflow' => str_contains($viewPhp, '<?php else: ?>')
        && str_contains($viewPhp, 'Compatibilidade para empresas sem Ordem do atendimento executável.'),
    'cache exato foi invalidado para o novo contrato' => str_contains($cachePhp, "'runtime_contract' => '36.37.2'"),
    'pacote identifica 36.37.2' => str_contains($versionPhp, 'RS Connect 36.37.2'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - demanda passou a ter uma única autoridade operacional: a Ordem do atendimento.\n";
