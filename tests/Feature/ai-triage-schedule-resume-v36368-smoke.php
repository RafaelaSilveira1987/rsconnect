<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$triage = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$ai = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');

$checks = [
    'remoção da recuperação ampla que contaminava mensagens comuns' => !str_contains($pre, 'recoverIntentFromTriage') && !str_contains($pre, 'triageScheduleSnapshot'),
    'retomada da agenda ocorre somente ao concluir uma etapa de triagem' => str_contains($triage, 'schedule_resume_ready') && str_contains($triage, '$wasCollectingSchedule') && str_contains($triage, '$currentField !== null'),
    'retomada exige que não existam mais campos obrigatórios antes da agenda' => str_contains($triage, '$missingBeforeSchedule === []'),
    'retomada reaproveita preferência e modalidade já coletadas' => str_contains($triage, "collected['preferred_schedule']") && str_contains($triage, "collected['modality']") && str_contains($triage, "'agendar ' . implode"),
    'webhook usa o sinal pontual sem reclassificar toda mensagem posterior' => str_contains($webhook, "triageResult['schedule_resume_ready']") && str_contains($webhook, 'resumed_after_triage'),
    'fila/reprocessamento usa a mesma regra pontual' => str_contains($ai, "triageResult['schedule_resume_ready']") && str_contains($ai, "!empty(\$triageResult['scheduling_intent'])"),
    'melhorias visuais das configurações foram preservadas' => str_contains($css, 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))') && str_contains($css, 'white-space: nowrap; flex: 0 0 auto;'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nResumo: retomada pontual da agenda validada sem recuperação ampla da intenção.\n";
