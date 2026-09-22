<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v, ?string $e=null): string { return strtolower($v); } }
if (!function_exists('mb_substr')) { function mb_substr(string $v,int $s,?int $l=null,?string $e=null): string { return $l===null?substr($v,$s):substr($v,$s,$l); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $v, ?string $e=null): int { return strlen($v); } }
if (!function_exists('mb_stripos')) { function mb_stripos(string $h, string $n, int $o=0, ?string $e=null): int|false { return stripos($h, $n, $o); } }

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;
use App\Services\AiLocalReplyService;
use App\Services\AiModelService;

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures[] = $label;
    }
};

$profile = [
    'status' => 'active',
    'interaction_mode' => 'prompt',
    'capabilities' => [
        'triage.enabled' => true,
        'eligibility.enabled' => true,
        'calendar.pre_schedule' => true,
        'policy.fail_closed' => true,
    ],
    'triage_fields' => [
        ['field_key' => 'patient_age', 'label' => 'Idade', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'prompt_text' => 'Qual é a idade da pessoa que será atendida?'],
        ['field_key' => 'modality', 'label' => 'Modalidade', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'prompt_text' => 'Online ou presencial?'],
    ],
    'policies' => [
        ['policy_key' => 'minimum_age', 'policy_type' => 'number', 'value' => 14, 'action_key' => 'block_schedule', 'customer_message' => 'Atendimento somente a partir de 14 anos.', 'enabled' => 1],
    ],
];

$triage = new AgentTriageService();
$state = [
    'collected' => ['is_for_self' => true],
    'current_field_key' => 'patient_age',
    'last_intent' => 'schedule',
    'status' => 'collecting',
    'eligibility_status' => 'pending',
];

$approx = $triage->simulateTurn($profile, $state, 'Mais de 30');
$check(!isset($approx['collected']['patient_age']), 'Faixa aproximada não é convertida em idade exata inventada.');
$check(str_contains((string) ($approx['decision']['message'] ?? ''), 'idade exata'), 'Faixa aproximada gera uma única clarificação específica, sem repetir a pergunta genérica.');

$exact = $triage->simulateTurn($profile, $approx['state'], '30');
$check((int) ($exact['collected']['patient_age'] ?? 0) === 30, 'Resposta curta "30" é aceita quando o campo atual é idade.');
$check(($exact['decision']['evidence']['next_field'] ?? '') === 'modality', 'Após coletar a idade, o fluxo avança para o próximo campo em vez de perguntar idade novamente.');

$model = new AiModelService();
$guard = new ReflectionMethod($model, 'applyCurrentTurnContinuityGuard');
$guard->setAccessible(true);
$agent = [
    'name' => 'Rafa, Assistente da psicóloga Mariana Bernardes',
    'ai_greeting_mode' => 'all_contacts',
    '_is_opening_turn' => true,
    '_current_turn_text' => "Bom dia!\nGostaria de algumas informações sobre o atendimento",
    'prioritize_current_turn' => 1,
];
$opening = (string) $guard->invoke($model, 'Bom dia! Claro, posso ajudar com isso.', $agent, ['name' => 'Rafaela', 'phone' => '5532999999999']);
$check(str_contains($opening, 'Eu sou Rafa, Assistente da psicóloga Mariana Bernardes.'), 'Primeira resposta gerada se identifica pelo nome público do assistente.');
$check(substr_count($opening, 'Rafa, Assistente da psicóloga Mariana Bernardes') === 1, 'Identidade do assistente aparece apenas uma vez.');

$withContactName = (string) $guard->invoke($model, 'Bom dia, Rafaela! Claro, posso ajudar.', $agent, ['name' => 'Rafaela', 'phone' => '5532999999999']);
$check(str_contains($withContactName, 'Eu sou Rafa, Assistente da psicóloga Mariana Bernardes.'), 'Nome do contato parecido com o nome do assistente não impede a apresentação.');

$continued = (string) $guard->invoke($model, 'Claro, posso ajudar com isso.', array_merge($agent, ['_is_opening_turn' => false]), ['phone' => '5532999999999']);
$check(!str_contains($continued, 'Eu sou Rafa'), 'Conversa já iniciada não repete apresentação.');

$ageClarification = (string) $guard->invoke($model, 'Qual é a idade da pessoa que seria atendida?', array_merge($agent, [
    '_is_opening_turn' => false,
    '_current_turn_text' => 'Mais de 30',
]), ['phone' => '5532999999999']);
$check(str_contains($ageClarification, 'idade exata'), 'Barreira final impede que a IA repita mecanicamente a pergunta de idade após uma faixa aproximada.');

$local = new AiLocalReplyService();
$localResult = $local->match([
    'name' => 'Rafa, Assistente da psicóloga Mariana Bernardes',
    'ai_local_replies_enabled' => 1,
    'ai_greeting_reply' => 'Bom dia! Como posso ajudar?',
    'ai_greeting_mode' => 'all_contacts',
], 'Bom dia', [
    '_is_opening_turn' => true,
    'contact_status' => 'lead',
    'contact_group' => 'interested',
    'tags_json' => [],
]);
$check(str_contains((string) ($localResult['reply'] ?? ''), 'Eu sou Rafa, Assistente da psicóloga Mariana Bernardes.'), 'Saudação local da primeira resposta também inclui identificação.');

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - idade curta, clarificação de faixa e identificação de abertura validadas.\n";
