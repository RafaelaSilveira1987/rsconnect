<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;

$triage = new AgentTriageService();
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$profile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'config' => [
        'conversation_behavior' => [
            'service_mode' => ['mode' => 'choice'],
            'modalities' => [
                'online' => ['enabled' => true],
                'presencial' => ['enabled' => true],
            ],
        ],
    ],
    'capabilities' => ['triage.enabled'=>true,'eligibility.enabled'=>false,'calendar.pre_schedule'=>true],
    'policies' => [],
    'triage_fields' => [
        ['field_key'=>'is_for_self','label'=>'Para quem','field_type'=>'boolean','prompt_text'=>'É para você?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'patient_age','label'=>'Idade','field_type'=>'number','prompt_text'=>'Qual idade?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'modality','label'=>'Modalidade','field_type'=>'select','prompt_text'=>'Online ou presencial?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
        ['field_key'=>'custom_demanda','label'=>'Demanda','field_type'=>'text','prompt_text'=>'O que motivou o contato?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'options'=>[]],
        ['field_key'=>'preferred_schedule','label'=>'Preferência','field_type'=>'text','prompt_text'=>'Qual dia e período?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1],
    ],
];

$first = $triage->simulateMessageBurst(
    $profile,
    ['collected'=>[], 'current_field_key'=>'is_for_self'],
    ['queria marcar uma consulta']
);
$check(
    trim((string) ($first['collected']['custom_demanda'] ?? '')) === '',
    'pedido de agenda não é capturado antecipadamente como Demanda customizada'
);
$check(
    ($first['current_field_key'] ?? '') === 'is_for_self',
    'pedido de agenda preserva a primeira etapa configurada'
);

$state = ['collected'=>[], 'current_field_key'=>'is_for_self'];
foreach ([
    ['Sim', 'patient_age'],
    ['Tenho 32 anos', 'modality'],
    ['prefiro atendimento online, é melhor para mim', 'custom_demanda'],
] as [$message, $expected]) {
    $turn = $triage->simulateMessageBurst($profile, $state, [$message]);
    $check(($turn['current_field_key'] ?? '') === $expected, 'ordem avança para ' . $expected . ' após: ' . $message);
    $state = ['collected'=>$turn['collected'], 'current_field_key'=>$turn['current_field_key']];
}
$check(
    trim((string) ($state['collected']['custom_demanda'] ?? '')) === '',
    'Demanda permanece pendente até resposta própria da etapa'
);

$recovered = $triage->simulateTurn(
    $profile,
    [
        'collected'=>[
            'is_for_self'=>true,
            'patient_age'=>32,
            'modality'=>'online',
            'custom_demanda'=>'queria marcar uma consulta',
        ],
        'current_field_key'=>'preferred_schedule',
        'last_intent'=>'schedule',
        'status'=>'collecting',
    ],
    'Estou muito ansiosa desde que comecei a faculdade.'
);
$check(
    str_contains((string) ($recovered['collected']['custom_demanda'] ?? ''), 'ansiosa'),
    'estado antigo contaminado por pedido de agenda é saneado e aceita a demanda real'
);
$check(
    ($recovered['state']['current_field_key'] ?? '') === 'preferred_schedule',
    'após a demanda real o cursor segue para preferência'
);

$root = dirname(__DIR__, 2);
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$cache = (string) file_get_contents($root . '/app/Services/AiExactCacheService.php');
$service = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$check(str_contains($version, 'RS Connect 36.40.5'), 'pacote identifica a versão 36.40.5');
$check(str_contains($cache, "'runtime_contract' => '36.40.5'"), 'cache exato usa o contrato 36.40.5');
$check(str_contains($service, 'looksLikeStandaloneSchedulingRequest'), 'runtime saneia pedidos operacionais persistidos em campos livres');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - 36.40.5 preserva a Ordem e não transforma pedido de agenda em Demanda futura.\n";
