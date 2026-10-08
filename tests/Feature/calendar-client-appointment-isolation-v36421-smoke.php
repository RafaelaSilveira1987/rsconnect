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
use App\Services\PreSchedulingService;

$root = dirname(__DIR__, 2);
$communicationSource = (string) file_get_contents($root . '/app/Services/CalendarClientCommunicationService.php');
$preSchedulingSource = (string) file_get_contents($root . '/app/Services/PreSchedulingService.php');
$controllerSource = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$appVersion = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failures[] = $label;
    }
};

$communication = new CalendarClientCommunicationService();
$skipMethod = new ReflectionMethod($communication, 'shouldSkipJob');
$skipMethod->setAccessible(true);

$timezone = new DateTimeZone('America/Sao_Paulo');
$past = (new DateTimeImmutable('now', $timezone))->modify('-2 days')->format('Y-m-d H:i:s');
$future = (new DateTimeImmutable('now', $timezone))->modify('+2 days')->format('Y-m-d H:i:s');

$check(
    $skipMethod->invoke(
        $communication,
        ['event_key' => CalendarClientCommunicationService::EVENT_REMINDER, 'expected_starts_at' => $past],
        ['status' => 'confirmed', 'starts_at' => $past, 'timezone' => 'America/Sao_Paulo', 'client_confirmation_status' => 'not_requested']
    ) === true,
    'lembrete vencido é descartado depois que o compromisso já começou'
);

$check(
    $skipMethod->invoke(
        $communication,
        ['event_key' => CalendarClientCommunicationService::EVENT_PRESENCE_REQUEST, 'expected_starts_at' => $past],
        ['status' => 'confirmed', 'starts_at' => $past, 'timezone' => 'America/Sao_Paulo', 'client_confirmation_status' => 'not_requested']
    ) === true,
    'pedido de presença vencido também é descartado'
);

$check(
    $skipMethod->invoke(
        $communication,
        ['event_key' => CalendarClientCommunicationService::EVENT_REMINDER, 'expected_starts_at' => $future],
        ['status' => 'confirmed', 'starts_at' => $future, 'timezone' => 'America/Sao_Paulo', 'client_confirmation_status' => 'not_requested']
    ) === false,
    'lembrete futuro continua elegível'
);

$check(
    str_contains($communicationSource, 'processDueJobs(30, $tenantId, $appointmentId)')
    && str_contains($communicationSource, 'processDueJobs(20, $tenantId, $appointmentId)')
    && str_contains($communicationSource, "AND appointment_id = :appointment_id")
    && str_contains($communicationSource, 'recovery_appointment_id'),
    'envio transacional e recuperação de lock ficam limitados ao appointment_id atual'
);

$renderer = new PreSchedulingService();
$withLink = $renderer->renderMessage(
    'Tudo certo! Seu agendamento foi confirmado para {{data}} às {{hora}}. {{local}}',
    [
        'starts_at' => '2026-10-08 10:00:00',
        'location_type' => 'online',
        'appointment_modality' => 'online',
        'location' => 'Online',
        'meeting_url' => 'https://meet.google.com/novo-link',
    ]
);
$withoutLink = $renderer->renderMessage(
    'Tudo certo! Seu agendamento foi confirmado para {{data}} às {{hora}}. {{local}}',
    [
        'starts_at' => '2026-10-08 10:00:00',
        'location_type' => 'online',
        'appointment_modality' => 'online',
        'location' => 'Online',
        'meeting_url' => '',
    ]
);

$check(
    str_contains($withLink, '08/10/2026 às 10:00')
    && str_contains($withLink, "Atendimento: Online\nLink: https://meet.google.com/novo-link")
    && !str_contains($withLink, 'Local/link: Online'),
    'confirmação online usa somente o link salvo no próprio compromisso'
);
$check(
    str_contains($withoutLink, 'Atendimento: Online')
    && !str_contains($withoutLink, 'Local/link: Online')
    && !str_contains($withoutLink, 'meet.google.com'),
    'sem meeting_url não é inventado nem reaproveitado link de outro agendamento'
);

$check(
    str_contains($controllerSource, "SET meeting_url = :meeting_url")
    && strpos($controllerSource, "SET meeting_url = :meeting_url") < strpos($controllerSource, '->handleStatusChange('),
    'link informado na confirmação é persistido antes da comunicação ao cliente'
);

$check(
    str_contains($preSchedulingSource, 'Atendimento: Online')
    && str_contains($preSchedulingSource, "Link: "),
    'template diferencia modalidade de URL real'
);

$check(
    str_contains($appVersion, 'RS Connect 36.42.1')
    && str_contains($appVersion, 'RS Connect 36.42.2')
    && str_contains($appVersion, 'RS Connect 36.42.3')
    && ($manifest['package_version'] ?? '') === '36.42.4'
    && ($manifest['database']['required_migration'] ?? '') === '124_calendar_slot_capacity_mode.sql',
    'versão 36.42.4 preserva 36.42.1–36.42.3 e mantém a migration 124'
);

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - confirmação, link e fila automática permanecem isolados pelo agendamento correto.\n";
