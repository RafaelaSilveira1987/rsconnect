<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$automationFile = $root . '/app/Services/AiAutomationService.php';
$preScheduleFile = $root . '/app/Services/PreSchedulingService.php';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$automationFile, $preScheduleFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$automation = (string) file_get_contents($automationFile);
$preSchedule = (string) file_get_contents($preScheduleFile);
$version = (string) file_get_contents($versionFile);
$layout = (string) file_get_contents($layoutFile);

$checks = [
    'agenda é reavaliada dentro do próprio handleIncoming antes da IA livre' => str_contains($automation, '36.27.18: toda mensagem que chega à IA depois da janela de espera passa')
        && str_contains($automation, '$calendarGuard = $this->processSchedulingDuringReprocess('),
    'mensagem consumida pela agenda encerra antes do modelo' => str_contains($automation, '$calendarHandled && $calendarSkipAi')
        && str_contains($automation, "'calendar.pre_schedule.handled'"),
    'intenção de agenda habilitada falha fechada quando não é persistida' => str_contains($automation, '$schedulingIntent && $preSchedulingEnabled && !$calendarHandled')
        && str_contains($automation, 'calendar.pre_schedule_unhandled')
        && str_contains($automation, 'calendar_fail_closed'),
    'guarda usa o mesmo message id real recebido' => str_contains($automation, "'message_id' => \$storedMessageId")
        && str_contains($automation, "'incoming_message_id' => \$storedMessageId"),
    'guarda respeita agenda desabilitada pelo tenant' => str_contains($automation, '$preSchedulingEnabled = (new PreSchedulingService())->isEnabled('),
    'persistência do pré-agendamento continua presente' => str_contains($preSchedule, 'INSERT INTO calendar_appointments')
        && str_contains($preSchedule, "\$result['created'] = true;")
        && str_contains($preSchedule, "\$result['appointment_id'] = \$appointmentId;"),
    'prepare duplicado da preferência foi removido' => !str_contains($preSchedule, '$pdo->prepare(' . "\n" . '            $pdo->prepare('),
    'versão identifica 36.27.18 sem migration nova' => str_contains($version, 'RS Connect 36.27.18')
        && str_contains($version, "REQUIRED_MIGRATION = '101_agent_scheduling_specialist_routing.sql'"),
    'cache de front-end renovado' => str_contains($layout, 'app.css?v=36.27.18')
        && str_contains($layout, 'app.js?v=36.27.18'),
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

echo "\nOK - guarda determinística da agenda antes da IA v36.27.18 validada.\n";
