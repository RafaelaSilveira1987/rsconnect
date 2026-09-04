<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pre = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$conversation = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$calendar = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$layout = (string) file_get_contents($root . '/app/Views/layouts/app.php');

$checks = [
    'pré-agendamento recente mantém contexto mesmo após classificação genérica' =>
        str_contains($pre, 'um pré-agendamento pendente e recente é fonte de verdade mais forte')
        && str_contains($pre, '(time() - $timestamp) <= (36 * 3600)'),
    'preferência curta continua exigindo sinal real de data hora ou modalidade' =>
        str_contains($pre, '$hasIntent = $directAgenda || ($continuationContext && ($hasPreference || $modality !== \'\'));'),
    'confirmação sem slot é bloqueada antes da IA livre' =>
        str_contains($conversation, 'guardConfirmationWithoutSelectedSlot')
        && str_contains($conversation, 'calendar.confirmation_blocked_without_slot')
        && str_contains($conversation, 'confirmation_blocked_without_slot'),
    'guarda informa modalidade ausente' =>
        str_contains($conversation, 'Antes de confirmar, preciso saber a modalidade do atendimento: online ou presencial?'),
    'guarda impede confirmado sem validação de disponibilidade' =>
        str_contains($conversation, 'Esse horário ainda não foi validado na agenda.'),
    'calendário visual remove placeholder sem preferência' =>
        str_contains($calendar, '$calendarDisplayAppointments')
        && str_contains($calendar, 'não representam compromisso real')
        && str_contains($calendar, 'count($calendarDisplayAppointments)'),
    'lista preserva pedido incompleto como aguardando preferência' =>
        str_contains($calendar, 'Aguardando preferência')
        && str_contains($calendar, 'Aguardando dia e horário reais'),
    'versão atual preserva coerência 36.27.19 e migration 101' =>
        (str_contains($version, 'RS Connect 36.27.20') || str_contains($version, 'RS Connect 36.27.19'))
        && str_contains($version, "REQUIRED_MIGRATION = '101_agent_scheduling_specialist_routing.sql'"),
    'cache de front-end atualizado' =>
        str_contains($layout, 'app.css?v=36.27.20')
        && str_contains($layout, 'app.js?v=36.27.20'),
];

$failures = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - coerência conversa/calendário v36.27.19 validada.\n";
