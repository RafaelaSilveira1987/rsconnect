<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$calendarConversationFile = $root . '/app/Services/CalendarConversationService.php';
$aiModelFile = $root . '/app/Services/AiModelService.php';
$versionFile = $root . '/app/Services/AppVersionService.php';
$layoutFile = $root . '/app/Views/layouts/app.php';

foreach ([$calendarConversationFile, $aiModelFile, $versionFile, $layoutFile] as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, "FALHA: arquivo ausente: {$file}\n");
        exit(1);
    }
}

$calendar = (string) file_get_contents($calendarConversationFile);
$aiModel = (string) file_get_contents($aiModelFile);
$version = (string) file_get_contents($versionFile);
$layout = (string) file_get_contents($layoutFile);

$checks = [
    'confirmação curta é interceptada antes da IA' => str_contains($calendar, 'handlePendingConfirmation(')
        && str_contains($calendar, "'no_pending_confirmation'")
        && str_contains($calendar, "'appointment_confirmed'"),
    'confirmação exige horário realmente selecionado' => str_contains($calendar, 'a.status = "awaiting_approval"')
        && str_contains($calendar, 'a.availability_status IN ("slot_selected", "validated")')
        && str_contains($calendar, 'COALESCE(a.chosen_availability_slot_id, 0) > 0'),
    'resposta pode é reconhecida como confirmação' => str_contains($calendar, "'pode confirmar'")
        && str_contains($calendar, "'pode agendar'")
        && str_contains($calendar, "return 'affirmative';"),
    'aprovação humana prevalece sobre confirmação da IA' => str_contains($calendar, "empty(\$settings['ai_can_confirm']) || !empty(\$settings['require_human_approval'])")
        && str_contains($calendar, 'calendar.appointment_confirmation_waiting_human'),
    'seleção pede confirmação quando a IA pode confirmar' => str_contains($calendar, 'Posso confirmar o agendamento?')
        && str_contains($calendar, "!empty(\$settings['ai_can_confirm']) && empty(\$settings['require_human_approval'])"),
    'confirmação valida disponibilidade antes de persistir' => str_contains($calendar, '->canApprove($tenantId, $appointment)')
        && str_contains($calendar, '->confirmMarkedAppointment($tenantId, $appointmentId)')
        && str_contains($calendar, '->syncConfirmedAppointment($tenantId, $appointmentId, false, true)'),
    'confirmação converte pré-agendamento em compromisso real' => str_contains($calendar, 'SET status = "confirmed"')
        && str_contains($calendar, 'is_pre_schedule = 0')
        && str_contains($calendar, 'approval_status = "approved"')
        && str_contains($calendar, 'Agendamento - '),
    'mensagem final usa somente estado confirmado persistido' => str_contains($calendar, 'calendar.appointment_confirmed_by_ai')
        && str_contains($calendar, "'appointment_status' => 'confirmed'")
        && str_contains($calendar, 'approved_message'),
    'lembrete é criado após confirmação real' => str_contains($calendar, 'scheduleAppointmentReminder(')
        && str_contains($calendar, "'calendar.appointment.confirmed'"),
    'mensagens determinísticas mantêm identidade do agente' => str_contains($calendar, 'agendaSenderDisplayName(')
        && str_contains($calendar, 'withAiWhatsappSignature(')
        && str_contains($calendar, 'sender_display_name')
        && str_contains($calendar, '"outgoing", "ai"'),
    'prompt proíbe confirmação textual sem persistência' => str_contains($aiModel, 'Nunca invente disponibilidade e nunca declare um compromisso confirmado apenas por decisão textual sua.')
        && str_contains($aiModel, 'não diga que está confirmado antes de o sistema registrar o compromisso como confirmado'),
    'versão identifica 36.27.16 sem migration nova' => str_contains($version, 'RS Connect 36.27.16')
        && str_contains($version, "REQUIRED_MIGRATION = '101_agent_scheduling_specialist_routing.sql'"),
    'cache de front-end renovado' => str_contains($layout, 'app.css?v=36.27.16')
        && str_contains($layout, 'app.js?v=36.27.16'),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . "\n";
}

if ($failures !== []) {
    fwrite(STDERR, "\nFALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "\nOK - confirmação conversacional da agenda v36.27.16 validada.\n";
