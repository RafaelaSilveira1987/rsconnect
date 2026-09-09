<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $value, ?string $encoding = null): int { return strlen($value); }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AgentTriageService;

$service = new AgentTriageService();
$method = new ReflectionMethod($service, 'resolveSchedulingIntent');

$restrictedSession = [
    'status' => 'completed',
    'eligibility_status' => 'blocked',
    'block_reason' => 'minimum_age',
    'last_intent' => 'conversation',
];

$checks = [];

$staleContext = 'quero marcar psicologo para minha filha ela tem 8 anos quero uma indicacao';
$followup = $method->invoke($service, $restrictedSession, 'quero uma indicação', $staleContext, false);
$checks['follow-up de indicação não herda intenção antiga de agenda'] = $followup === false;

$newSchedule = $method->invoke($service, $restrictedSession, 'quero agendar outro horário', 'quero agendar outro horario', false);
$checks['nova tentativa explícita de agenda continua protegida'] = $newSchedule === true;

$timeOnly = $method->invoke($service, $restrictedSession, 'quinta às 10h', 'quinta as 10h', false);
$checks['dia e horário após restrição também são tratados como agenda'] = $timeOnly === true;

$activeScheduleSession = [
    'status' => 'collecting',
    'eligibility_status' => 'pending',
    'block_reason' => null,
    'last_intent' => 'schedule',
];
$continuation = $method->invoke($service, $activeScheduleSession, 'online', 'online', false);
$checks['fluxo de agenda ativo continua reconhecendo respostas curtas'] = $continuation === true;

$root = dirname(__DIR__, 2);
$triageSource = (string) file_get_contents($root . '/app/Services/AgentTriageService.php');
$messageSource = (string) file_get_contents($root . '/app/Services/ConversationAutomationMessageService.php');

$checks['contexto recente é limitado às entradas após a última saída'] = str_contains($triageSource, 'SELECT MAX(o.id)')
    && str_contains($triageSource, 'm.id > COALESCE');
$checks['dedupe não engole resposta de uma nova entrada'] = str_contains($messageSource, 'SELECT MAX(i.id)')
    && str_contains($messageSource, 'm.id > COALESCE');

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
}
if ($failed !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "\nOK - restrição de agenda não trava follow-up como 'quero uma indicação'.\n";
