<?php

declare(strict_types=1);

if (!function_exists('mb_strlen')) { function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); } }
if (!function_exists('mb_substr')) { function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string { return $length === null ? substr($value, $start) : substr($value, $start, $length); } }
if (!function_exists('mb_strtolower')) { function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); } }

require_once dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentBlueprintService;
use App\Services\AgentTriageService;

$root = dirname(__DIR__, 2);

$blueprint = new AgentBlueprintService();
$applyWorkflow = new ReflectionMethod(AgentBlueprintService::class, 'applyWorkflowOrderToTriageFields');
$applyWorkflow->setAccessible(true);

$fields = [
    ['field_key' => 'is_for_self', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'position' => 10],
    ['field_key' => 'patient_age', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'position' => 20],
    ['field_key' => 'modality', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'position' => 30],
    // Ordem legada propositalmente errada: preferência vem antes da demanda.
    ['field_key' => 'preferred_schedule', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'position' => 40],
    ['field_key' => 'custom_demanda', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'position' => 90],
    ['field_key' => 'brief_demand', 'active' => 1, 'required_before_schedule' => 1, 'required_for_completion' => 1, 'position' => 100],
];
$workflow = [
    ['step_type' => 'collect', 'active' => 1, 'position' => 10, 'config' => ['field_keys' => ['is_for_self']]],
    ['step_type' => 'collect', 'active' => 1, 'position' => 20, 'config' => ['field_keys' => ['patient_age']]],
    ['step_type' => 'collect', 'active' => 1, 'position' => 30, 'config' => ['field_keys' => ['modality']]],
    ['step_type' => 'collect', 'active' => 1, 'position' => 40, 'config' => ['field_keys' => ['custom_demanda']]],
    ['step_type' => 'collect', 'active' => 1, 'position' => 50, 'config' => ['field_keys' => ['preferred_schedule']]],
    ['step_type' => 'action', 'active' => 1, 'position' => 60, 'config' => ['action_key' => 'calendar.pre_schedule']],
];
$effectiveFields = $applyWorkflow->invoke($blueprint, $fields, $workflow);
$keys = array_map(static fn (array $row): string => (string) ($row['field_key'] ?? ''), $effectiveFields);
$byKey = [];
foreach ($effectiveFields as $field) {
    $byKey[(string) ($field['field_key'] ?? '')] = $field;
}

$profile = [
    'status' => 'active',
    'interaction_mode' => 'hybrid',
    'capabilities' => ['triage.enabled' => true, 'eligibility.enabled' => false],
    'triage_fields' => $effectiveFields,
    'policies' => [],
];
$triage = new AgentTriageService();

// Simula conversa antiga cujo cursor ainda apontava para preferência, embora o admin
// tenha movido Demanda para antes dela.
$state = [
    'collected' => [
        'is_for_self' => true,
        'patient_age' => 35,
        'modality' => 'online',
    ],
    'current_field_key' => 'preferred_schedule',
    'last_intent' => 'schedule',
    'status' => 'collecting',
    'eligibility_status' => 'pending',
];
$demandTurn = $triage->simulateTurn($profile, $state, 'Perdi alguém muito especial e não estou conseguindo levar meus dias.');
$preferenceTurn = $triage->simulateTurn($profile, (array) ($demandTurn['state'] ?? []), 'quinta feira a tarde');

// Reproduz exatamente a conversa reportada: por causa do cursor antigo, a preferência
// já foi coletada antes da Demanda. Quando a resposta da Demanda chega, o fluxo precisa
// ficar pronto para retomar a agenda no MESMO turno, e não esperar outra mensagem.
$outOfOrderFinalState = [
    'collected' => [
        'is_for_self' => true,
        'patient_age' => 35,
        'modality' => 'online',
        'preferred_schedule' => 'quinta feira a tarde',
    ],
    'current_field_key' => 'custom_demanda',
    'last_intent' => 'schedule',
    'status' => 'collecting',
    'eligibility_status' => 'pending',
];
$finalDemandTurn = $triage->simulateTurn(
    $profile,
    $outOfOrderFinalState,
    'Perdi alguém muito especial, não estou conseguindo levar meus dias.'
);

$triagePhp = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$blueprintPhp = (string) file_get_contents($root . '/app/Services/AgentBlueprintService.php');
$professionalPhp = (string) file_get_contents($root . '/app/Services/ProfessionalCalendarService.php');
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$calendarController = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$cachePhp = (string) file_get_contents($root . '/app/Services/AiExactCacheService.php');
$versionPhp = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'workflow reordena demanda antes da preferência' => array_search('custom_demanda', $keys, true) < array_search('preferred_schedule', $keys, true),
    'campo fora do workflow fica inativo no runtime' => empty($byKey['brief_demand']['active']) && empty($byKey['brief_demand']['required_for_completion']),
    'campos do workflow viram parte efetiva da conclusão' => !empty($byKey['custom_demanda']['required_for_completion']) && !empty($byKey['preferred_schedule']['required_for_completion']),
    'campos antes da agenda ficam obrigatórios antes da consulta' => !empty($byKey['custom_demanda']['required_before_schedule']) && !empty($byKey['preferred_schedule']['required_before_schedule']),
    'cursor antigo é corrigido para demanda no turno seguinte' => trim((string) ($demandTurn['collected']['custom_demanda'] ?? '')) !== '',
    'depois da demanda o fluxo avança para preferência' => (string) ($demandTurn['state']['current_field_key'] ?? '') === 'preferred_schedule',
    'preferência é coletada depois da demanda' => trim((string) ($preferenceTurn['collected']['preferred_schedule'] ?? '')) !== '',
    'depois da preferência não sobra coleta anterior à agenda' => ($preferenceTurn['missing_before_schedule'] ?? []) === [],
    'demanda final com preferência já coletada libera agenda imediatamente' => ($finalDemandTurn['missing_before_schedule'] ?? []) === []
        && (string) ($finalDemandTurn['state']['status'] ?? '') === 'ready'
        && (($finalDemandTurn['state']['current_field_key'] ?? null) === null || ($finalDemandTurn['state']['current_field_key'] ?? '') === ''),
    'runtime possui retomada de agenda no mesmo turno da última coleta' => str_contains($triagePhp, 'schedule_resume_ready')
        && str_contains($triagePhp, 'workflowCalendarReady'),
    'runtime reconcilia cursor persistido com ordem atual' => str_contains($triagePhp, 'o cursor antigo') && str_contains($triagePhp, '$expectedCurrentField'),
    'runtime deriva flags diretamente do workflow' => str_contains($blueprintPhp, 'required_for_completion') && str_contains($blueprintPhp, 'workflowFieldPosition'),
    'agenda interna aceita consulta compartilhada sem owner' => str_contains($professionalPhp, '$usesInternalSharedCalendar') && str_contains($professionalPhp, 'owner_pending'),
    'google continua exigindo profissional quando configurado' => str_contains($professionalPhp, '!empty($tenantSettings[\'require_owner\']) && !$usesInternalSharedCalendar'),
    'card de pré-agendamento recebe layout dedicado' => str_contains($calendarView, "is-pre-schedule") && str_contains($css, '.calendar-row.is-pre-schedule'),
    'pré-agendamento usa rótulos genéricos dos campos configurados' => str_contains($calendarController, 'SELECT field_key, label FROM tenant_triage_fields')
        && str_contains($calendarView, '$dynamicTriageRows')
        && str_contains($calendarView, '$triageFieldLabels'),
    'demanda legada só aparece quando o legado possui valor real' => str_contains($calendarView, '$showLegacyDemand = $currentDemandSummary !==')
        && str_contains($calendarView, '<?php if ($showLegacyDemand): ?>'),
    'valores do pré-agendamento não quebram letra por letra' => str_contains($css, 'overflow-wrap: break-word') && str_contains($css, 'word-break: normal'),
    'cache exato foi invalidado' => str_contains($cachePhp, "'runtime_contract' => '36.37.4'"),
    'pacote identifica versão 36.37.4' => str_contains($versionPhp, 'RS Connect 36.37.4'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - v36.37.4 mantém a conversa contínua até a Agenda interna e corrige a leitura do pré-agendamento.\n";
