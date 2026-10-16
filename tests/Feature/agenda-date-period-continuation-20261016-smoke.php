<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $text): string { return strtolower($text); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $text, int $start, ?int $length = null): string { return substr($text, $start, $length); }
}

$root = dirname(__DIR__, 2);
require_once $root . '/app/Services/SchedulingPreferenceResolverService.php';
require_once $root . '/app/Services/SchedulingPreferenceContextService.php';
require_once $root . '/app/Services/AgentTriageService.php';
require_once $root . '/app/Services/PreSchedulingService.php';

use App\Services\AgentTriageService;
use App\Services\PreSchedulingService;
use App\Services\SchedulingPreferenceContextService;
use App\Services\SchedulingPreferenceResolverService;

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
    echo "[OK] $message\n";
}

$year = date('Y');
$ymd = $year . '-10-17';
$human = '17/10/' . $year;
$nextHuman = '18/10/' . $year;
$resolver = new SchedulingPreferenceResolverService();
$context = new SchedulingPreferenceContextService();
$triage = new AgentTriageService();
$preSchedule = new PreSchedulingService();

$date = $resolver->resolve('gostaria de agendar uma consulta para o dia 17/10', true);
check(($date['preferred_date'] ?? '') === $ymd, 'Data 17/10 identificada na solicitação inicial (ano do teste 2026)');
$day = $context->mergeText('', 'dia 17/10');
$full = $context->mergeText($day, 'pela manhã');
check(str_contains($full, $human) && str_contains($full, 'manhã'), 'Preserva data ao receber período em outro balão');
$parts = $resolver->resolve('agendar ' . $full, false);
check(($parts['preferred_date'] ?? '') === $ymd && ($parts['preferred_time'] ?? '') === 'manhã', 'Retomada de agenda contém dia e período completos');
check(($context->mergeText($full, 'às 09:00') === ($human . ' 09:00')), 'Hora exata substitui período sem perder data');
check(($context->mergeText($full, '18/10') === ($nextHuman . ' manhã')), 'Troca explícita de data atualiza apenas o dia');
check(($context->mergeText(($human . ' 09:00'), '18/10') === $nextHuman), 'Nova data não reaproveita hora exata para pré-reserva automática');
check(($context->mergeText($full, 'pela tarde') === ($human . ' tarde')), 'Mudança de período preserva o dia');
check(($context->mergeText($full, 'obrigado') === $full), 'Mensagem sem preferência não apaga o contexto');

$hasSchedule = new ReflectionMethod($triage, 'hasSchedulePreference');
check($hasSchedule->invoke($triage, 'dia 17/10') === true, 'Triagem passa a reconhecer data numérica');

$extract = new ReflectionMethod($triage, 'extractDeterministic');
$collected = $extract->invoke($triage, [], 'dia 17/10', 'dia 17/10', null);
$collected = $extract->invoke($triage, $collected, 'pela manhã', 'pela manha', 'preferred_schedule', 'pela manhã', null);
check(str_contains((string) ($collected['preferred_schedule'] ?? ''), ($human . ' manhã')), 'Estado real da triagem combina a data e o período');

$hasExact = new ReflectionMethod($preSchedule, 'hasExactSchedulingTime');
check($hasExact->invoke($preSchedule, $resolver->resolve('17/10 pela manhã', true)) === false, 'Consulta de período não bloqueia pela existência de compromisso às 9h');
check($hasExact->invoke($preSchedule, $resolver->resolve('17/10 às 09:00', true)) === true, 'Conflito de compromisso ainda é validado para horário exato');

$hasDay = new ReflectionMethod($preSchedule, 'hasDatePreference');
check($hasDay->invoke($preSchedule, $resolver->resolve('agendar para 17/10', false)) === true, 'Apenas a data já permite procurar grade sem hora específica');
check($hasDay->invoke($preSchedule, $resolver->resolve('pela manhã', true)) === false, 'Período sozinho não deve consultar data arbitrária');

$hasFull = new ReflectionMethod($preSchedule, 'hasFullPreference');
$mergeIncoming = $context->mergeIntent($human, $resolver->resolve('pela manhã', true));
check($hasFull->invoke($preSchedule, $mergeIncoming) === true, 'Pré-agendamento pendente torna preferência completa após período');
check($hasFull->invoke($preSchedule, $resolver->resolve('pela manhã', true)) === false, 'Sem data anterior, período sozinho não libera consulta');

$period = new ReflectionMethod($preSchedule, 'periodFromIntent');
$resolved = $period->invoke($preSchedule, $mergeIncoming, 50);
check(str_starts_with($resolved['starts_at'], ($ymd . ' ')), 'Consulta ancorada em 17/10, não numa data padrão');

echo "OK: testes de continuidade da preferência concluídos.\n";
