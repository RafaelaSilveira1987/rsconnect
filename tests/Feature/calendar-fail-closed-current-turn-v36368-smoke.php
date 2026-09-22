<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$automation = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$language = (string) file_get_contents($root . '/app/Services/OperationalLanguageService.php');

$oldNeedle = '$result[\'scheduling_intent\'] = $schedulingIntent || !empty($triageResult[\'scheduling_intent\'])';
$newNeedle = '$result[\'scheduling_intent\'] = $schedulingIntent || !empty($result[\'resumed_after_triage\']);';

$checks = [
    'guard não usa intenção herdada da triagem como intenção atual' => !str_contains($automation, $oldNeedle) && str_contains($automation, $newNeedle),
    'retomada explícita da agenda continua protegida' => str_contains($automation, "resumed_after_triage") && str_contains($automation, 'calendar_fail_closed'),
    'alerta de camada determinística é classificado como agenda' => str_contains($language, "str_contains(\$joined, 'camada deterministica')") && str_contains($language, "return 'calendar';"),
    'mensagem operacional explica falha de pré-agendamento' => str_contains($language, 'este turno não concluiu uma criação ou atualização válida do pré-agendamento'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

exit($failed === [] ? 0 : 1);
