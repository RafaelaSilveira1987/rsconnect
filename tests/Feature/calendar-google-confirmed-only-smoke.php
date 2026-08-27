<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$lifecycle = file_get_contents($root . '/app/Services/CalendarGoogleLifecycleService.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/CalendarController.php') ?: '';
$genericTemplate = file_get_contents($root . '/docs/n8n_templates/template-agenda-google-calendar.json') ?: '';
$cycleTemplate = file_get_contents($root . '/docs/n8n_templates/template-agenda-google-ciclo-completo.json') ?: '';

$assert(str_contains($lifecycle, '$appointmentStatus !== \'confirmed\''), 'lifecycle deve bloquear evento para compromisso não confirmado');
$assert(str_contains($lifecycle, 'AND status = "confirmed"'), 'manutenção deve considerar somente status confirmed');
$assert(str_contains($lifecycle, 'calendar_confirmed_sync_v1'), 'ciclo direto deve enviar contrato confirmado');
$assert(str_contains($controller, 'syncConfirmedAppointment($tenantId, $appointmentId, false, true)'), 'confirmação manual deve usar transição explícita');
$assert(str_contains($cycleTemplate, 'calendar_confirmed_sync_v1'), 'template ciclo completo deve exigir contrato confirmado');
$assert(str_contains($cycleTemplate, 'Evento Google só pode ser criado/atualizado para compromisso confirmado.'), 'template ciclo completo deve bloquear evento sem confirmação');
$assert(!str_contains($cycleTemplate, "payload.title || 'Agendamento RS Connect'"), 'template ciclo completo não deve usar título fallback');
$assert(str_contains($genericTemplate, "appointmentStatus === 'confirmed'"), 'writer genérico deve exigir status confirmed');
$assert(str_contains($genericTemplate, 'pre_agendamento_sem_aprovacao'), 'writer deve bloquear pré-agendamento sem aprovação');

echo "OK - Google Agenda só sincroniza compromisso realmente confirmado.\n";
