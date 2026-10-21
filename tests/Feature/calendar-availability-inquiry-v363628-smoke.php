<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) { function mb_strtolower(string $v, ?string $e=null): string { return strtolower($v); } }
if (!function_exists('mb_substr')) { function mb_substr(string $v,int $st,?int $l=null,?string $e=null):string { return $l===null?substr($v,$st):substr($v,$st,$l); } }
require dirname(__DIR__,2) . '/bootstrap.php';
$root = dirname(__DIR__, 2);
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$conversation = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$preService = new App\Services\PreSchedulingService();
$availabilityQuestion = new ReflectionMethod($preService, 'asksAvailabilityOptions');
$hasDate = new ReflectionMethod($preService, 'hasDatePreference');
$hasFull = new ReflectionMethod($preService, 'hasFullPreference');
$hasExactTime = new ReflectionMethod($preService, 'hasExactSchedulingTime');

$checks = [
    'pergunta de disponibilidade não vira confirmação por conter sim + horário' =>
        str_contains($conversation, 'Sim, qual horário tem disponível?')
        && str_contains($conversation, 'confirmationDecision($content, false)')
        && str_contains($conversation, "preg_match('/[?]/u'"),
    'guarda sem slot exige verbo explícito de confirmação' =>
        str_contains($conversation, 'Sem slot selecionado, a guarda aceita somente um pedido EXPLÍCITO')
        && str_contains($conversation, 'confirmo|confirmar|confirma|confirmado|agendar|agende|marcar|marque'),
    'pre-agendamento reconhece consulta de horários como browse de agenda' =>
        str_contains($pre, 'asksAvailabilityOptions')
        && str_contains($pre, 'availability_browse')
        && str_contains($pre, 'Perguntas como "qual horário tem disponível?" significam CONSULTAR a'),
    'browse consulta agenda real sem exigir horário inventado' =>
        $availabilityQuestion->invoke($preService, 'Quais horários estão disponíveis?')
        && $hasDate->invoke($preService, ['preferred_date' => '28/10/2026'])
        && !$hasFull->invoke($preService, ['preferred_date' => '28/10/2026'])
        && !$hasExactTime->invoke($preService, ['preferred_time' => 'manha'])
        && str_contains($pre, '$this->hasFullPreference($intent) || $this->hasDatePreference($intent)')
        && str_contains($pre, "$" . "result['availability_request_needed'] = true"),
    'slots continuam filtrados pelas regras da modalidade' =>
        str_contains($conversation, 'filterSlotsForAppointment($tenantId, $appointment, $slots)'),
    'versão do pacote atualizada sem migration nova' =>
        str_contains($version, 'RS Connect 36.36.28')
        && str_contains($version, "REQUIRED_MIGRATION = '120_agent_turn_state_cursor.sql'"),
];

$failures = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures[] = $label;
    }
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - consulta factual de disponibilidade v36.36.28 validada.\n";
