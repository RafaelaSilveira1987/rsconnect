<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failures[] = $label;
};

$orchestrator = $read('app/Services/NotificationOrchestratorService.php');
$calendar = $read('app/Controllers/CalendarController.php');
$controller = $read('app/Controllers/NotificationsController.php');
$routes = $read('routes/web.php');
$index = $read('app/Views/notifications/index.php');
$settings = $read('app/Views/notifications/settings.php');
$layout = $read('app/Views/layouts/app.php');
$modules = $read('app/Services/TenantModuleService.php');

$check(str_contains($orchestrator, 'formatAppointmentLocal') && str_contains($orchestrator, 'Horários da agenda são persistidos no horário local'), 'notificação preserva o horário local da agenda');
$check(str_contains($calendar, "'timezone' => \$timezone") && str_contains($calendar, "appointmentBefore['timezone']"), 'agenda envia o fuso do compromisso ao motor');
$check(str_contains($orchestrator, 'new NotificationDeliveryService())->process(20, $tenantId)'), 'eventos imediatos processam a fila automaticamente');
$check(str_contains($orchestrator, 'setTimezone(new DateTimeZone(\'UTC\'))'), 'lembretes futuros convertem o horário local para UTC');
$check(str_contains($controller, 'public function settings()') && str_contains($routes, "'/settings/notifications'"), 'configurações possuem controller e rota próprios');
$check(!str_contains($index, 'notification-automation-card') && str_contains($index, 'Configurar notificações'), 'histórico não carrega mais o formulário extenso');
$check(str_contains($settings, 'Notificações de agenda e orçamento') && str_contains($settings, 'Quais avisos devem aparecer?'), 'tela separada reúne automações e preferências');
$check(str_contains($layout, 'Config. notificações') && str_contains($modules, "'/settings/notifications'"), 'menu e controle de módulos reconhecem a nova tela');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - horário local, processamento imediato e tela separada validados.\n";
