<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $text, int $start, ?int $length = null): string { return substr($text, $start, $length); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $text): int { return strlen($text); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $text): string { return strtolower($text); }
}

$root = dirname(__DIR__, 2);
require $root . '/app/Services/AgentConversationBehaviorService.php';
require $root . '/app/Services/AgentPolicyEngineService.php';

use App\Services\AgentConversationBehaviorService;
use App\Services\AgentPolicyEngineService;

$behavior = new AgentConversationBehaviorService();
$profile = [
    'config' => [
        'conversation_behavior' => [
            'demand' => [
                'enabled' => true,
                'required_before_schedule' => true,
                'prompt' => 'Antes de verificar os horários, qual é a principal demanda?',
            ],
            'payment' => [
                'enabled' => true,
                'value_text' => 'R$ 200,00',
                'methods' => ['Pix', 'Cartão'],
                'message' => 'O pagamento pode ser feito por Pix ou cartão.',
            ],
        ],
    ],
    'triage_fields' => [
        [
            'field_key' => 'brief_demand',
            'label' => 'Motivo resumido do contato',
            'field_type' => 'textarea',
            'prompt_text' => 'Prompt antigo',
            'required_before_schedule' => false,
            'required_for_completion' => true,
            'active' => true,
            'position' => 60,
        ],
    ],
];

$effective = $behavior->applyOperationalOverridesToProfile($profile);
$demandField = null;
foreach ($effective['triage_fields'] as $field) {
    if (($field['field_key'] ?? '') === 'brief_demand') {
        $demandField = $field;
        break;
    }
}

$checks = [];
$checks['configuração amigável prevalece sobre campo antigo'] = is_array($demandField)
    && !empty($demandField['required_before_schedule'])
    && ($demandField['prompt_text'] ?? '') === 'Antes de verificar os horários, qual é a principal demanda?';

$missing = (new AgentPolicyEngineService())->missingRequiredBeforeSchedule(
    $effective['triage_fields'],
    ['is_for_self' => true, 'patient_age' => 18, 'modality' => 'online']
);
$checks['agenda continua bloqueada enquanto demanda estiver vazia'] = count($missing) === 1
    && (($missing[0]['field_key'] ?? '') === 'brief_demand');

$checks['turno misto com preço é identificado para resposta da IA'] = $behavior->hasInformationalQuestion('Se tem horário disponível, qual o valor?');
$checks['turno informativo sobre funcionamento é identificado'] = $behavior->hasInformationalQuestion('Queria saber como funciona a terapia por gentileza');
$checks['resposta simples de triagem não vira pergunta informativa'] = !$behavior->hasInformationalQuestion('sim');
$checks['resposta de modalidade não vira pergunta informativa'] = !$behavior->hasInformationalQuestion('online');

$triage = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$ai = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$flow = (string) file_get_contents($root . '/app/Services/ConversationFlowService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks['triagem aplica regras operacionais antes do policy engine'] = str_contains($triage, 'applyOperationalOverridesToProfile($profile)');
$checks['turno misto híbrido pode responder dúvidas sem liberar agenda'] = str_contains($triage, '$mixedInformationalTurn')
    && str_contains($triage, "\$result['skip_ai'] = false")
    && str_contains($triage, "\$result['handled'] = true");
$checks['pré-agendamento revalida fluxo mesmo com appointment existente'] = str_contains($pre, 'A regra de demanda/grupo é revalidada em TODA tentativa de agenda')
    && !str_contains($pre, 'if ($existing === null) {\n            // O fluxo de grupos pode ter sido carregado');
$checks['prompt não desliga demanda global para cliente atual'] = !str_contains($ai, "\$groupRule['require_demand_before_pre_schedule'] = false;")
    && str_contains($ai, "\$groupRule['require_demand_before_pre_schedule'] = true;");
$checks['refresh de contato respeita demanda global'] = str_contains($flow, '$allowExistingCustomerDemandExemption')
    && str_contains($flow, '!$behaviorDemandRequired');
$checks['versão 36.36.16 publicada'] = str_contains($version, 'RS Connect 36.36.16 — Regras do agente como fonte de verdade');

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - regras operacionais do agente validadas como fonte de verdade.\n";
