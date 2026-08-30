<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$migration = $read('database/migrations/092_notification_orchestration.sql');
$manifest = $read('database/migrations/manifest.php');
$orchestrator = $read('app/Services/NotificationOrchestratorService.php');
$delivery = $read('app/Services/NotificationDeliveryService.php');
$controller = $read('app/Controllers/NotificationsController.php');
$routes = $read('routes/web.php');
$view = $read('app/Views/notifications/settings.php');
$calendar = $read('app/Controllers/CalendarController.php');
$preSchedule = $read('app/Services/PreSchedulingService.php');
$commercial = $read('app/Services/CommercialRequestService.php');
$version = $read('app/Services/AppVersionService.php');

$check(str_contains($migration, 'tenant_notification_rules') && str_contains($migration, 'notification_jobs'), 'migration cria regras e fila de notificações');
$check(str_contains($migration, 'uq_notification_job_dedupe') && str_contains($migration, 'next_attempt_at'), 'fila possui deduplicação e tentativas agendadas');
$check(str_contains($manifest, "'092_notification_orchestration.sql'"), 'manifesto inclui a migration 092');
$check(str_contains($orchestrator, 'calendar.appointment.reminder') && str_contains($orchestrator, 'commercial.quote.overdue'), 'orquestrador cobre lembretes e orçamento atrasado');
$check(str_contains($orchestrator, 'scheduleAppointmentReminder') && str_contains($orchestrator, 'scheduleQuoteOverdue'), 'orquestrador agenda eventos futuros');
$check(str_contains($delivery, 'EvolutionService') && str_contains($delivery, 'status = "retry"'), 'processador entrega WhatsApp e possui retentativas');
$check(str_contains($delivery, 'shouldSkip') && str_contains($delivery, 'crm_commercial_requests'), 'processador cancela alertas que já perderam validade');
$check(str_contains($controller, 'saveRules') && str_contains($controller, 'processNow') && str_contains($controller, 'NOTIFICATION_CRON_TOKEN'), 'controller permite configurar, testar e processar por cron');
$check(str_contains($routes, '/settings/notifications') && str_contains($routes, '/notifications/rules') && str_contains($routes, '/webhooks/notifications/process'), 'rotas de configuração e cron existem');
$check(str_contains($view, 'Notificações de agenda e orçamento') && str_contains($view, 'WhatsApp para equipe'), 'tela expõe canais e regras por empresa');
$check(str_contains($calendar, 'NotificationOrchestratorService') && str_contains($calendar, 'scheduleAppointmentReminder'), 'agenda manual dispara notificações e lembretes');
$check(str_contains($preSchedule, 'calendar.appointment.created'), 'pré-agendamento conversacional dispara o mesmo motor');
$check(str_contains($commercial, 'commercial.quote.requested') && str_contains($commercial, 'scheduleQuoteOverdue'), 'orçamentos criam aviso imediato e escalonamento');
$check(str_contains($version, 'RS Connect 36.23.0') && str_contains($version, '092_notification_orchestration.sql'), 'versão preserva a migration 092 no histórico obrigatório');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - motor central de notificações de agenda e orçamento validado.\n";
