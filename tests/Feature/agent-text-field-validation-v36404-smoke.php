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
    'capabilities' => ['triage.enabled' => true, 'eligibility.enabled' => false],
    'policies' => [],
    'triage_fields' => [
        ['field_key'=>'patient_age','label'=>'Idade','field_type'=>'number','prompt_text'=>'Qual é a idade?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'options'=>[]],
        ['field_key'=>'custom_demanda','label'=>'Demanda','field_type'=>'text','prompt_text'=>'O que levou você a procurar atendimento?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'options'=>[]],
        ['field_key'=>'preferred_schedule','label'=>'Preferência','field_type'=>'text','prompt_text'=>'Qual dia e período ficam melhores?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'options'=>[]],
    ],
];

// Caso observado em produção: uma frase genérica de interesse foi persistida em
// custom_demanda antes da resposta real da etapa. O runtime deve desconsiderá-la e
// aceitar a resposta explícita posterior.
$turn = $triage->simulateTurn(
    $profile,
    [
        'collected' => [
            'patient_age' => 22,
            'custom_demanda' => 'gostaria de saber sobre a terapia',
        ],
        'current_field_key' => 'preferred_schedule',
        'last_intent' => 'schedule',
        'status' => 'collecting',
    ],
    'Sim, estou iniciando a faculdade e tenho estado muito ansiosa.'
);

$check(
    trim((string) ($turn['collected']['custom_demanda'] ?? '')) === 'Sim, estou iniciando a faculdade e tenho estado muito ansiosa.',
    'resposta explícita substitui valor genérico persistido anteriormente'
);
$check(
    ($turn['state']['current_field_key'] ?? '') === 'preferred_schedule',
    'depois da demanda válida o cursor avança para preferência de agenda'
);

$generic = $triage->simulateTurn(
    $profile,
    [
        'collected' => ['patient_age' => 22],
        'current_field_key' => 'custom_demanda',
        'last_intent' => 'schedule',
        'status' => 'collecting',
    ],
    'Gostaria de saber sobre a terapia.'
);
$check(
    trim((string) ($generic['collected']['custom_demanda'] ?? '')) === '',
    'frase genérica de interesse não satisfaz campo textual estruturado'
);
$check(
    ($generic['state']['current_field_key'] ?? '') === 'custom_demanda',
    'cursor permanece na etapa quando a mensagem não contém resposta operacional'
);

$substantial = $triage->simulateTurn(
    $profile,
    [
        'collected' => ['patient_age' => 22],
        'current_field_key' => 'custom_demanda',
        'last_intent' => 'schedule',
        'status' => 'collecting',
    ],
    'Estou iniciando a faculdade e tenho estado muito ansiosa desde então.'
);
$check(
    str_contains((string) ($substantial['collected']['custom_demanda'] ?? ''), 'faculdade'),
    'relato declarativo substancial continua sendo aceito'
);

$futureProfile = $profile;
$futureProfile['triage_fields'] = [
    ['field_key'=>'modality','label'=>'Modalidade','field_type'=>'select','prompt_text'=>'Online ou presencial?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'options'=>[]],
    $profile['triage_fields'][1],
];
$future = $triage->simulateMessageBurst(
    $futureProfile,
    ['collected'=>[], 'current_field_key'=>'modality'],
    ['Gostaria de saber sobre a terapia']
);
$check(
    trim((string) ($future['collected']['custom_demanda'] ?? '')) === '',
    'mensagem genérica também não é capturada antecipadamente como campo futuro'
);

$root = dirname(__DIR__, 2);
$service = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$cache = (string) file_get_contents($root . '/app/Services/AiExactCacheService.php');
$check(str_contains($service, 'sanitizeWeakCollectedFreeTextValues'), 'runtime revalida campos livres já persistidos');
$check(str_contains($service, 'looksLikeGenericInformationalOpening'), 'runtime distingue interesse genérico de resposta estruturada');
$check(str_contains($version, 'RS Connect 36.40.4'), 'pacote identifica a versão 36.40.4');
$check(str_contains($cache, "'runtime_contract' => '36.40.4'"), 'cache exato é invalidado para o novo contrato');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - 36.40.4 mantém campos textuais alinhados à resposta real da etapa.\n";
