<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$triage = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$css = (string) file_get_contents($root . '/public/assets/css/app.css');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'pré-agendamento retoma somente na conclusão da triagem' => !str_contains($pre, 'recoverIntentFromTriage') && str_contains($triage, 'schedule_resume_ready') && str_contains($triage, '$wasCollectingSchedule'),
    'retomada aproveita modalidade e preferência coletadas sem contaminar mensagens futuras' => str_contains($triage, "collected['modality']") && str_contains($triage, "collected['preferred_schedule']") && str_contains($webhook, "triageResult['schedule_resume_ready']"),
    'badges não quebram em coluna vertical' => str_contains($css, '.badge { display: inline-flex;') && str_contains($css, 'white-space: nowrap; flex: 0 0 auto;'),
    'grade das políticas usa auto-fit responsivo' => str_contains($css, 'repeat(auto-fit, minmax(min(100%, 320px), 1fr))'),
    'formulários das regras empilham no tablet' => str_contains($css, '.agent-policy-card .form-grid.two,') && str_contains($css, '.agent-rule-card-content .form-grid.two { grid-template-columns: 1fr; }'),
    'cache do layout principal foi renovado' => str_contains($layout, 'app.css?v=36.36.8') && str_contains($layout, 'app.js?v=36.36.8'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

exit($failed === [] ? 0 : 1);
