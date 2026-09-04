<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$flowFile = $root . '/app/Services/ConversationFlowService.php';
$automationFile = $root . '/app/Services/AiAutomationService.php';
$preScheduleFile = $root . '/app/Services/PreSchedulingService.php';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$flowFile, $automationFile, $preScheduleFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$flow = (string) file_get_contents($flowFile);
$automation = (string) file_get_contents($automationFile);
$preSchedule = (string) file_get_contents($preScheduleFile);
$version = (string) file_get_contents($versionFile);
$layout = (string) file_get_contents($layoutFile);

$checks = [
    'finalidade comercial explícita pode virar demanda' => str_contains($flow, 'schedulingPurposeCandidate(')
        && str_contains($flow, "['schedule', 'reschedule']")
        && str_contains($flow, 'mb_strlen($token) >= 6'),
    'consulta genérica continua removida da finalidade' => str_contains($flow, '|consulta|sessao|atendimento|horario|horarios|'),
    'demonstração e reunião não são removidas da finalidade' => preg_match('/private function schedulingPurposeCandidate.*?private function isOnlySchedulingMessage/s', $flow, $purposeMatch) === 1
        && !str_contains($purposeMatch[0], '|demonstracao|')
        && !str_contains($purposeMatch[0], '|reuniao|'),
    'fila rápida identifica intenção antes do processamento' => str_contains($automation, '$intentProbe = (new PreSchedulingService())->detectIntent($content, false);')
        && str_contains($automation, '$schedulingIntent = !empty($intentProbe[\'has_intent\']);'),
    'erro da agenda fica observável' => str_contains($automation, 'calendar.pre_schedule_error')
        && str_contains($automation, 'Falha ao processar a intenção de agenda antes da resposta da IA.'),
    'falha de agenda bloqueia fallback livre da IA' => str_contains($automation, 'calendar_fail_closed')
        && str_contains($automation, 'Falha na camada determinística de agenda:'),
    'falha continua elegível para reprocessamento' => str_contains($automation, "'event' => 'ai.failed'"),
    'pré-agendamento ainda persiste antes da etapa seguinte' => str_contains($preSchedule, 'INSERT INTO calendar_appointments')
        && str_contains($preSchedule, 'calendar.pre_scheduled')
        && str_contains($preSchedule, "\$result['created'] = true;"),
    'versão identifica 36.27.17 sem migration nova' => str_contains($version, 'RS Connect 36.27.17')
        && str_contains($version, "REQUIRED_MIGRATION = '101_agent_scheduling_specialist_routing.sql'"),
    'cache de front-end renovado' => str_contains($layout, 'app.css?v=36.27.17')
        && str_contains($layout, 'app.js?v=36.27.17'),
];

$failures = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
    if (!$ok) {
        $failures[] = $label;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - persistência segura da intenção de agenda v36.27.17 validada.\n";
