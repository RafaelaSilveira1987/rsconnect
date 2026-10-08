<?php

declare(strict_types=1);

if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\CalendarClientCommunicationService;

$root = dirname(__DIR__, 2);
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$settingsView = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$serviceSource = (string) file_get_contents($root . '/app/Services/CalendarClientCommunicationService.php');
$dockerfile = (string) file_get_contents($root . '/Dockerfile');
$worker = (string) file_get_contents($root . '/bin/rs-connect-start.sh');
$reconcileCli = (string) file_get_contents($root . '/bin/calendar-client-automation-reconcile.php');
$appVersion = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures[] = $label;
    }
};

$service = new CalendarClientCommunicationService();
$leadTime = new ReflectionMethod($service, 'leadTimeMinutes');
$leadTime->setAccessible(true);

$check(
    $leadTime->invoke($service, [
        'client_reminder_lead_value' => 4,
        'client_reminder_lead_unit' => 'hours',
    ], 'client_reminder', 120, 5, 10080) === 240,
    'antecedência de quatro horas é lida da configuração e convertida para 240 minutos'
);

$check(
    str_contains($serviceSource, "!empty(\$settings['reminder_enabled'])")
    && str_contains($serviceSource, "(int) (\$settings['reminder_minutes'] ?? 120)")
    && str_contains($serviceSource, "(string) (\$settings['reminder_message'] ?? '')"),
    'ativação, antecedência e texto do lembrete continuam vindo da configuração da empresa'
);

$check(
    str_contains($dockerfile, 'CMD ["/var/www/html/bin/rs-connect-start.sh"]')
    && str_contains($worker, 'php /var/www/html/bin/process-notifications.php 100')
    && str_contains($worker, 'RS_NOTIFICATION_WORKER_ENABLED')
    && str_contains($worker, 'RS_NOTIFICATION_WORKER_INTERVAL_SECONDS')
    && str_contains($worker, 'calendar-client-automation-reconcile.php')
    && str_contains($reconcileCli, 'rescheduleAllConfiguredUpcomingJobs'),
    'container inicia processador interno genérico e reconcilia automações existentes no startup'
);

$check(
    str_contains($serviceSource, 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 14 HOUR)')
    && str_contains($serviceSource, 'rescheduleAllConfiguredUpcomingJobs'),
    'reconciliação de compromissos futuros não compara horário local diretamente com NOW UTC'
);

$check(
    !str_contains($worker, 'reminder_minutes=240')
    && !str_contains($worker, '4 horas')
    && !str_contains($worker, 'Lembrete: seu atendimento'),
    'worker não possui regra de negócio fixa de horário ou mensagem'
);

$confirmedBranch = strpos($calendarView, '<button class="btn btn-small btn-primary" type="submit">Concluir</button>');
$rescheduleAfterConfirmed = $confirmedBranch !== false
    ? strpos($calendarView, '<button class="btn btn-small btn-secondary" type="submit">Remarcar</button>', $confirmedBranch)
    : false;
$cancelAfterConfirmed = $confirmedBranch !== false
    ? strpos($calendarView, '<button class="btn btn-small btn-quiet" type="submit">Cancelar</button>', $confirmedBranch)
    : false;
$check(
    $rescheduleAfterConfirmed !== false
    && $cancelAfterConfirmed !== false
    && $rescheduleAfterConfirmed < $cancelAfterConfirmed,
    'compromisso ativo/confirmado volta a oferecer Remarcar na Agenda'
);

$check(
    str_contains($settingsView, 'O horário e o texto são lidos desta configuração da empresa')
    && str_contains($settingsView, 'rotina interna apenas processa a fila automaticamente'),
    'interface explica que a regra pertence à empresa e o worker apenas executa a fila'
);

$check(
    str_contains($appVersion, 'RS Connect 36.42.3')
    && ($manifest['package_version'] ?? '') === '36.42.4'
    && ($manifest['database']['required_migration'] ?? '') === '124_calendar_slot_capacity_mode.sql',
    'versão 36.42.4 preserva a 36.42.3 e mantém a migration 124'
);

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - lembretes automáticos possuem runtime interno sem regra fixa e Remarcar foi restaurado.\n";
