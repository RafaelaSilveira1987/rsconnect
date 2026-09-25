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

use App\Services\AiResponseContractService;
use App\Services\AgentConversationBehaviorService;
use App\Services\CalendarConversationService;

$root = dirname(__DIR__, 2);
$fail = [];
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $fail[] = $label;
};

$profile = [
    'interaction_mode' => 'hybrid',
    'triage_fields' => [],
];
$contract = new AiResponseContractService();

$handoff = $contract->validate(
    $profile,
    ['current_field_key' => '', 'collected' => []],
    'Prefiro presencial',
    'Vou registrar sua preferência e encaminhar para a Mariana verificar a disponibilidade. Assim que ela confirmar, aviso você.'
);
$check(empty($handoff['ok']), 'contrato rejeita encaminhamento inventado para a profissional verificar disponibilidade');
$check(in_array('unsupported_human_check', $handoff['violations'] ?? [], true), 'encaminhamento falso é classificado como consulta humana não suportada');
$check(in_array('unsupported_calendar_action', $handoff['violations'] ?? [], true), 'encaminhamento falso também é classificado como ação de agenda não suportada');

$busy = $contract->validate(
    $profile,
    ['current_field_key' => '', 'collected' => []],
    'Quarta feira às 11h',
    'Verificamos que essa opção de horário já está preenchida. Podemos tentar outros horários.'
);
$check(empty($busy['ok']), 'contrato rejeita causa inventada para indisponibilidade');
$check(in_array('unsupported_calendar_reason', $busy['violations'] ?? [], true), '“horário preenchido” é identificado como causa não comprovada');

$waitlist = $contract->validate(
    $profile,
    ['current_field_key' => '', 'collected' => []],
    'Tem outro horário?',
    'Posso registrar seus dias e te avisar assim que abrir um encaixe.'
);
$check(empty($waitlist['ok']), 'contrato rejeita promessa de aviso de encaixe sem lista de espera real');
$check(in_array('unsupported_waitlist_promise', $waitlist['violations'] ?? [], true), 'promessa de lista de espera é identificada');

$neutral = $contract->validate(
    $profile,
    ['current_field_key' => '', 'collected' => []],
    'Tem outro horário?',
    'Esse horário não está disponível. Posso te mostrar outras opções reais da agenda.'
);
$check(!empty($neutral['ok']), 'indisponibilidade neutra sem causa inventada permanece permitida');

// Reproduz a regra real do caso reportado sem depender de banco: presencial
// somente às segundas e tentativa numa quarta-feira.
$behavior = new AgentConversationBehaviorService();
$cacheProperty = new ReflectionProperty(AgentConversationBehaviorService::class, 'settingsCache');
$cacheProperty->setAccessible(true);
$cacheProperty->setValue(null, [999999 => [
    'modalities' => [
        'presencial' => ['enabled'=>true,'allowed_days'=>['mon'],'message'=>''],
        'online' => ['enabled'=>true,'allowed_days'=>[],'message'=>''],
    ],
]]);
$preferenceRule = $behavior->schedulingPreferenceRule(999999, [
    'appointment_modality' => 'presencial',
    'starts_at' => '2026-09-30 11:00:00',
    'timezone' => 'America/Sao_Paulo',
]);
$check(empty($preferenceRule['allowed']), 'quarta-feira é barrada antes da agenda quando presencial está configurado apenas para segunda');
$check(($preferenceRule['code'] ?? '') === 'modality_day_not_allowed', 'restrição usa código de regra, não falso conflito de agenda');
$check(str_contains(mb_strtolower((string) ($preferenceRule['message'] ?? '')), 'segunda-feira'), 'resposta explica o dia realmente configurado');
$check(!preg_match('/ocupad|preenchid|lotad/u', mb_strtolower((string) ($preferenceRule['message'] ?? ''))), 'resposta de regra não inventa que horário está ocupado/preenchido');
$cacheProperty->setValue(null, []);

$calendar = new CalendarConversationService();
$safeTemplate = new ReflectionMethod(CalendarConversationService::class, 'safeAvailabilityTemplate');
$safeTemplate->setAccessible(true);
$sanitized = (string) $safeTemplate->invoke(
    $calendar,
    'Verificamos que essa opção de horário já está preenchida. Posso te avisar assim que abrir um encaixe.',
    'Esse horário não está disponível. Pode me informar outra preferência?'
);
$check($sanitized === 'Esse horário não está disponível. Pode me informar outra preferência?', 'template legado mentiroso é substituído por indisponibilidade factual');

$triageSource = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$check(str_contains($triageSource, 'workflowHasCalendarAction'), 'triagem reconhece ação de agenda configurada na Ordem do atendimento');
$check(str_contains($triageSource, "schedule_resume_reason'] = \$workflowCalendarReady ? 'configured_workflow'"), 'fim da coleta retoma a agenda pelo workflow sem exigir nova intenção do lead');

$preSource = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$check(!str_contains($preSource, 'sendPreferenceAcknowledgement('), 'confirmação promissória de preferência foi removida do backend');
$check(str_contains($preSource, 'schedulingPreferenceRule'), 'regra de dia/modalidade é validada antes da consulta de disponibilidade');
$check(str_contains($preSource, "'rule_blocked'"), 'preferência fora da regra é interrompida antes de consultar agenda');

$behaviorSource = (string) file_get_contents($root . '/app/Services/AgentConversationBehaviorService.php');
$check(str_contains($behaviorSource, 'modality_day_not_allowed'), 'runtime distingue restrição de modalidade de indisponibilidade do calendário');
$check(str_contains($behaviorSource, 'Você prefere um desses dias?'), 'restrição explica os dias configurados sem dizer que o horário está ocupado');

$calendarSource = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$check(str_contains($calendarSource, 'safeAvailabilityTemplate'), 'mensagens customizadas antigas de agenda passam por saneamento factual');
$check(str_contains($calendarSource, '$unsafeCause') && str_contains($calendarSource, '$unsafeWaitlist'), 'saneamento bloqueia causa inventada e promessa de espera');

$automationSource = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$check(str_contains($automationSource, 'ai.contract.fail_closed'), 'segunda violação do LLM agora falha fechado');
$check(str_contains($automationSource, 'safeFallbackForConversation'), 'fail-closed usa resposta derivada do estado persistido');
$check(str_contains($automationSource, '$claimsBusyCause'), 'defesa final também neutraliza alegação de horário ocupado/preenchido');

$modelSource = (string) file_get_contents($root . '/app/Services/AiModelService.php');
$check(str_contains($modelSource, 'A mensagem de estado da consulta é exclusiva do backend'), 'LLM não recebe mais mensagem legada de consulta como texto para imitar');
$check(str_contains($modelSource, 'A aprovação humana só se aplica depois que o backend selecionar ou pré-reservar um horário real'), 'aprovação humana não autoriza encaminhamento antes de existir slot real');

$contractSource = (string) file_get_contents($root . '/app/Services/AiResponseContractService.php');
$check(str_contains($contractSource, "'violations' => ['runtime_contract_unavailable']"), 'falha ao carregar o estado do contrato também é fail-closed');

$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$cache = (string) file_get_contents($root . '/app/Services/AiExactCacheService.php');
$check(str_contains($version, 'RS Connect 36.36.27 — Agenda factual e sem promessas duplicadas'), 'pacote identifica a versão 36.36.27');
$check(str_contains($version, "REQUIRED_MIGRATION = '120_agent_turn_state_cursor.sql'"), 'não exige nova migration além da 120');
$check(str_contains($cache, "'runtime_contract' => '36.36.27'"), 'cache exato é invalidado para o novo contrato de agenda');

if ($fail !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $fail) . "\n");
    exit(1);
}

echo "OK - contrato factual da agenda e remoção de promessas duplicadas validados.\n";
