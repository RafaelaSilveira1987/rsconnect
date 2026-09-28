<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }
if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;

$root = dirname(__DIR__, 2);
$triage = new AgentTriageService();

$profile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'capabilities' => ['triage.enabled' => true, 'eligibility.enabled' => false],
    'policies' => [],
    'triage_fields' => [
        ['field_key'=>'modality','label'=>'Modalidade','field_type'=>'select','prompt_text'=>'Você prefere online ou presencial?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'options'=>[]],
        ['field_key'=>'custom_demanda','label'=>'Demanda','field_type'=>'text','prompt_text'=>'O que levou você a procurar atendimento?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'options'=>[]],
        ['field_key'=>'preferred_schedule','label'=>'Preferência','field_type'=>'text','prompt_text'=>'Qual dia e período ficam melhores?','active'=>1,'required_before_schedule'=>1,'required_for_completion'=>1,'options'=>[]],
    ],
];

// Reproduz o caso real: o contato responde modalidade e, antes da resposta do agente,
// envia uma pergunta sobre valor. O segundo balão não pode virar o valor do próximo
// campo livre (Demanda).
$burst = $triage->simulateMessageBurst(
    $profile,
    ['collected'=>[], 'current_field_key'=>'modality'],
    ['Prefiro online', 'Qual o valor da consulta?']
);

$checks = [];
$checks['modalidade é coletada normalmente'] = ($burst['collected']['modality'] ?? '') === 'online';
$checks['pergunta de valor não preenche campo personalizado Demanda'] = trim((string) ($burst['collected']['custom_demanda'] ?? '')) === '';
$checks['cursor permanece na Demanda depois da dúvida informativa'] = ($burst['current_field_key'] ?? '') === 'custom_demanda';

$demandTurn = $triage->simulateTurn(
    $profile,
    [
        'collected'=>$burst['collected'],
        'current_field_key'=>$burst['current_field_key'],
        'last_intent'=>'conversation',
        'status'=>'collecting',
    ],
    'Perdi alguém muito especial e está difícil seguir os dias.'
);
$checks['resposta declarativa preenche a Demanda'] = str_contains((string) ($demandTurn['collected']['custom_demanda'] ?? ''), 'Perdi alguém');
$checks['depois da Demanda o fluxo avança para preferência'] = ($demandTurn['state']['current_field_key'] ?? '') === 'preferred_schedule';

$questionProfile = $profile;
$questionProfile['triage_fields'][1]['options'] = ['accept_question_as_answer'=>true];
$questionTurn = $triage->simulateTurn(
    $questionProfile,
    [
        'collected'=>['modality'=>'online'],
        'current_field_key'=>'custom_demanda',
        'last_intent'=>'conversation',
        'status'=>'collecting',
    ],
    'Vocês aceitam convênio?'
);
$checks['campo personalizado pode aceitar pergunta quando configurado'] = trim((string) ($questionTurn['collected']['custom_demanda'] ?? '')) === 'Vocês aceitam convênio?';

$service = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$blueprint = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$view = (string) file_get_contents($root . '/app/Views/agents/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$cache = (string) file_get_contents($root . '/app/Services/AiExactCacheService.php');

$checks['runtime possui proteção genérica para perguntas em custom_*'] = str_contains($service, 'captureConfiguredFreeTextValue')
    && str_contains($service, 'looksLikeStandaloneQuestion');
$checks['opção do campo é persistida sem apagar options_json existente'] = str_contains($blueprint, "accept_question_as_answer")
    && str_contains($blueprint, 'options_json = :options_json');
$checks['UI permite exceção explícita para campos que realmente esperam perguntas'] = str_contains($view, 'Aceitar uma pergunta do contato como resposta desta informação');
$checks['cache exato foi invalidado para 36.37.5'] = str_contains($cache, "'runtime_contract' => '36.37.5'");
$checks['pacote identifica 36.37.5'] = str_contains($version, 'RS Connect 36.37.5');

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - perguntas informativas não contaminam campos personalizados no fluxo.\n";
