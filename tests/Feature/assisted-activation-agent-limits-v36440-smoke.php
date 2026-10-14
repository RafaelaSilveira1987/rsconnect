<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v, ?string $e=null): string { return strtolower($v); } }
if (!function_exists('mb_substr')) { function mb_substr(string $v,int $s,?int $l=null,?string $e=null): string { return $l===null?substr($v,$s):substr($v,$s,$l); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $v, ?string $e=null): int { return strlen($v); } }

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;

$profile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'capabilities' => [
        'triage.enabled' => true,
        'eligibility.enabled' => true,
        'calendar.pre_schedule' => true,
    ],
    'triage_fields' => [],
    'policies' => [
        [
            'policy_key' => 'assisted.discount_request',
            'policy_type' => 'json',
            'value' => [
                'condition' => ['field' => '_turn_intent', 'operator' => 'eq', 'value' => 'discount_request'],
                'applies_to' => ['conversation', 'calendar.*'],
            ],
            'action_key' => 'handoff',
            'customer_message' => 'Descontos dependem do responsável.',
            'enabled' => 1,
        ],
        [
            'policy_key' => 'assisted.refund_request',
            'policy_type' => 'json',
            'value' => [
                'condition' => ['field' => '_turn_intent', 'operator' => 'eq', 'value' => 'refund_request'],
                'applies_to' => ['conversation', 'calendar.*'],
            ],
            'action_key' => 'warn',
            'customer_message' => 'Reembolsos são avaliados pela equipe.',
            'enabled' => 1,
        ],
        [
            'policy_key' => 'assisted.custom_case.60',
            'policy_type' => 'json',
            'value' => [
                'condition' => ['field' => '_turn_text', 'operator' => 'contains', 'value' => 'terapia de casal'],
                'applies_to' => ['conversation', 'calendar.*'],
                'display_value' => 'terapia de casal',
            ],
            'action_key' => 'warn',
            'customer_message' => 'Esse serviço não é oferecido atualmente.',
            'enabled' => 1,
        ],
        [
            'policy_key' => 'assisted.maximum_age',
            'policy_type' => 'json',
            'value' => [
                'condition' => ['field' => 'patient_age', 'operator' => 'gt', 'value' => 60],
                'applies_to' => ['conversation', 'calendar.*'],
            ],
            'action_key' => 'block_schedule',
            'customer_message' => 'Atendemos até 60 anos.',
            'enabled' => 1,
        ],
    ],
];

$service = new AgentTriageService();
$checks = [];

$discount = $service->simulateTurn($profile, [], 'Se eu fechar hoje você consegue me dar 20% de desconto?');
$checks['desconto vira handoff determinístico'] = ($discount['decision']['decision'] ?? '') === 'handoff'
    && ($discount['decision']['policy_key'] ?? '') === 'assisted.discount_request'
    && !empty($discount['should_use_rule_message'])
    && empty($discount['should_use_ai']);

$normal = $service->simulateTurn($profile, $discount['state'] ?? [], 'Qual o endereço?');
$checks['intenção comercial é efêmera e não contamina próximo turno'] = ($normal['decision']['policy_key'] ?? null) !== 'assisted.discount_request';

$refund = $service->simulateTurn($profile, [], 'Quero o estorno do valor pago.');
$checks['warn comercial usa resposta controlada sem handoff'] = ($refund['decision']['decision'] ?? '') === 'warn'
    && !empty($refund['should_use_rule_message'])
    && empty($refund['should_use_ai'])
    && !empty($refund['conversation_continues']);

$custom = $service->simulateTurn($profile, [], 'Vocês fazem terapia de casal?');
$checks['caso personalizado usa política estruturada'] = ($custom['decision']['policy_key'] ?? '') === 'assisted.custom_case.60'
    && ($custom['decision']['decision'] ?? '') === 'warn'
    && !empty($custom['should_use_rule_message']);

$ageState = ['collected' => ['patient_age' => 65], 'last_intent' => 'schedule'];
$age = $service->simulateTurn($profile, $ageState, 'Quero agendar quinta às 10h.');
$checks['faixa etária máxima bloqueia agenda e mantém conversa'] = ($age['decision']['policy_key'] ?? '') === 'assisted.maximum_age'
    && empty($age['calendar_allowed'])
    && !empty($age['conversation_continues']);

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/routes/web.php');
$view = (string) file_get_contents($root . '/app/Views/onboarding/index.php');
$guide = (string) file_get_contents($root . '/app/Services/OnboardingGuideService.php');
$checks['onboarding mantém limites estruturados dentro da configuração simples'] = str_contains($routes, "'/onboarding/operation'")
    && str_contains($view, 'Quando o assistente não pode decidir sozinho?')
    && str_contains($view, 'name="discount_policy"')
    && str_contains($guide, 'saveBusinessOperation')
    && str_contains($guide, "'agent_limits'");

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - Ativação Assistida valida autoridade comercial, faixa etária e respostas controladas.\n";
