<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); }
}
if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;

$triage = new AgentTriageService();
$profile = [
    'status' => 'active',
    'interaction_mode' => 'form',
    'config' => [
        'conversation_behavior' => [
            'demand' => [
                'enabled' => true,
                'required_before_schedule' => true,
                'prompt' => 'Conte brevemente o motivo do atendimento.',
            ],
        ],
    ],
    'capabilities' => [
        'triage.enabled' => true,
        'eligibility.enabled' => true,
        'calendar.pre_schedule' => true,
    ],
    'policies' => [],
    'triage_fields' => [
        ['field_key'=>'is_for_self','label'=>'Quem será atendido','field_type'=>'boolean','prompt_text'=>'O atendimento é para você?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'patient_age','label'=>'Idade','field_type'=>'number','prompt_text'=>'Qual é a idade?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'modality','label'=>'Modalidade','field_type'=>'select','prompt_text'=>'Qual modalidade você prefere?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'brief_demand','label'=>'Demanda','field_type'=>'textarea','prompt_text'=>'Conte brevemente o motivo do atendimento.','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'preferred_schedule','label'=>'Preferência','field_type'=>'text','prompt_text'=>'Qual dia e período você prefere?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
    ],
];

$fail = [];
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $fail[] = $label;
};

$state = ['collected'=>[], 'last_intent'=>'conversation', 'status'=>'collecting'];
$r1 = $triage->simulateTurn($profile, $state, 'Quero agendar uma consulta');
$check(($r1['state']['current_field_key'] ?? '') === 'is_for_self', 'primeira pendência segue a ordem configurada');
$check(empty($r1['state']['collected']['brief_demand'] ?? null), 'pedido de agenda não preenche demanda automaticamente');

$r2 = $triage->simulateTurn($profile, $r1['state'], 'Sim');
$check(($r2['state']['current_field_key'] ?? '') === 'patient_age', 'segunda etapa é idade');

$r3 = $triage->simulateTurn($profile, $r2['state'], '39');
$check(($r3['state']['current_field_key'] ?? '') === 'modality', 'terceira etapa é modalidade');

$r4 = $triage->simulateTurn($profile, $r3['state'], 'online');
$check(($r4['state']['current_field_key'] ?? '') === 'brief_demand', 'demanda vem antes da preferência quando configurada assim');

$r5 = $triage->simulateTurn($profile, $r4['state'], 'Ansiedade e dificuldade para dormir');
$check(($r5['state']['current_field_key'] ?? '') === 'preferred_schedule', 'preferência só vem depois da demanda');
$check(trim((string) ($r5['state']['collected']['brief_demand'] ?? '')) !== '', 'demanda é registrada quando a etapa configurada está sendo respondida');

if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - sequência configurada executada sem inferência de negócio.\n";
