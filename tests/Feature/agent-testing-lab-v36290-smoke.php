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
        'calendar.read' => true,
        'calendar.pre_schedule' => true,
        'calendar.confirm' => false,
        'calendar.human_approval' => true,
        'policy.fail_closed' => true,
    ],
    'triage_fields' => [
        ['field_key'=>'requester_name','label'=>'Nome','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'prompt_text'=>'Como você se chama?'],
        ['field_key'=>'is_for_self','label'=>'Para quem','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'prompt_text'=>'O atendimento é para você?'],
        ['field_key'=>'patient_age','label'=>'Idade','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'prompt_text'=>'Qual é a idade da pessoa?'],
        ['field_key'=>'modality','label'=>'Modalidade','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'prompt_text'=>'Online ou presencial?'],
    ],
    'policies' => [
        ['policy_key'=>'minimum_age','policy_type'=>'number','value'=>14,'action_key'=>'block_schedule','customer_message'=>'Não realizamos atendimento para pessoas menores de 14 anos.','enabled'=>1],
        ['policy_key'=>'confirmation_requires_human','policy_type'=>'boolean','value'=>true,'action_key'=>'human_approval','customer_message'=>'A confirmação depende da equipe.','enabled'=>1],
    ],
];

$service = new AgentTriageService();
$state = [];
$checks = [];

$step1 = $service->simulateTurn($profile, $state, 'Quero marcar psicólogo para minha filha');
$state = $step1['state'];
$checks['intenção inicial entra em agenda e coleta dados'] = !empty($step1['scheduling_intent']) && ($step1['decision']['code'] ?? '') === 'triage_incomplete';

$step2 = $service->simulateTurn($profile, $state, 'Ela tem 8 anos');
$state = $step2['state'];
$checks['idade abaixo do mínimo bloqueia somente a agenda'] = ($step2['decision']['code'] ?? '') === 'minimum_age'
    && empty($step2['calendar_allowed'])
    && !empty($step2['conversation_continues'])
    && !empty($step2['should_use_rule_message']);

$step3 = $service->simulateTurn($profile, $state, 'Quero uma indicação');
$state = $step3['state'];
$checks['pedido de indicação não reativa intenção antiga'] = empty($step3['scheduling_intent'])
    && !empty($step3['should_use_ai'])
    && !empty($step3['conversation_continues'])
    && empty($step3['should_use_rule_message']);

$step4 = $service->simulateTurn($profile, $state, 'Então pode marcar quinta às 10h?');
$checks['nova tentativa de agenda continua bloqueada'] = !empty($step4['scheduling_intent'])
    && ($step4['decision']['code'] ?? '') === 'minimum_age'
    && empty($step4['calendar_allowed']);

$root = dirname(__DIR__, 2);
$checks['migration do laboratório existe'] = is_file($root . '/database/migrations/105_agent_testing_lab.sql');
$checks['serviço do laboratório existe'] = is_file($root . '/app/Services/AgentSimulationService.php');
$checks['tela do laboratório existe'] = is_file($root . '/app/Views/agent_tests/index.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$checks['rotas do laboratório estão protegidas no RS Admin'] = str_contains($routes, "'/agent-tests'") && str_contains($routes, "'super_admin'");

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - laboratório valida a regressão menor → indicação → nova tentativa de agenda.\n";
