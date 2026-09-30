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
$settingsView = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$serviceSource = (string) file_get_contents($root . '/app/Services/CalendarClientCommunicationService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$service = new CalendarClientCommunicationService();
$method = new ReflectionMethod($service, 'leadTimeMinutes');
$method->setAccessible(true);
$skipMethod = new ReflectionMethod($service, 'shouldSkipJob');
$skipMethod->setAccessible(true);

$checks = [
    'pré-agendamento não exibe mais texto técnico original' => !str_contains($calendarView, 'Ver texto original para auditoria'),
    'configuração destaca confirmação imediata do agendamento' => str_contains($settingsView, 'Confirmação do agendamento')
        && str_contains($settingsView, 'client_send_confirmed_enabled'),
    'lembrete usa valor e unidade amigáveis' => str_contains($settingsView, 'client_reminder_lead_value')
        && str_contains($settingsView, 'client_reminder_lead_unit'),
    'confirmação de presença usa valor e unidade amigáveis' => str_contains($settingsView, 'client_presence_request_lead_value')
        && str_contains($settingsView, 'client_presence_request_lead_unit'),
    'duas horas viram 120 minutos' => $method->invoke($service, [
        'client_reminder_lead_value' => 2,
        'client_reminder_lead_unit' => 'hours',
    ], 'client_reminder', 30, 5, 10080) === 120,
    'um dia vira 1440 minutos' => $method->invoke($service, [
        'client_presence_request_lead_value' => 1,
        'client_presence_request_lead_unit' => 'days',
    ], 'client_presence_request', 30, 15, 20160) === 1440,
    'tempo informado é limitado ao máximo operacional' => $method->invoke($service, [
        'client_reminder_lead_value' => 10,
        'client_reminder_lead_unit' => 'days',
    ], 'client_reminder', 30, 5, 10080) === 10080,
    'pedido de presença prevalece quando coincide com lembrete' => str_contains($serviceSource, '$presenceSupersedesReminder')
        && str_contains($serviceSource, 'Sem mensagens duplicadas') === false,
    'pedido de presença vencido não é enviado depois que o cliente já respondeu' => $skipMethod->invoke($service,
        ['event_key' => CalendarClientCommunicationService::EVENT_PRESENCE_REQUEST, 'expected_starts_at' => '2026-10-10 10:00:00'],
        ['status' => 'confirmed', 'starts_at' => '2026-10-10 10:00:00', 'client_confirmation_status' => 'confirmed']
    ) === true,
    'lembrete é bloqueado se cliente já informou que não comparecerá' => $skipMethod->invoke($service,
        ['event_key' => CalendarClientCommunicationService::EVENT_REMINDER, 'expected_starts_at' => '2026-10-10 10:00:00'],
        ['status' => 'confirmed', 'starts_at' => '2026-10-10 10:00:00', 'client_confirmation_status' => 'declined']
    ) === true,
    'lembrete continua válido depois de presença confirmada' => $skipMethod->invoke($service,
        ['event_key' => CalendarClientCommunicationService::EVENT_REMINDER, 'expected_starts_at' => '2026-10-10 10:00:00'],
        ['status' => 'confirmed', 'starts_at' => '2026-10-10 10:00:00', 'client_confirmation_status' => 'confirmed']
    ) === false,
    'tela explica proteção contra disparo duplicado' => str_contains($settingsView, 'Sem mensagens duplicadas no mesmo instante'),
    'pacote mantém histórico da versão 36.41.1 e identifica a atual' => str_contains($version, 'RS Connect 36.41.1')
        && str_contains($version, 'RS Connect 36.41.2')
        && str_contains($version, 'RS Connect 36.41.3')
        && str_contains($version, 'RS Connect 36.41.4')
        && ($manifest['package_version'] ?? '') === '36.41.4',
    'migration obrigatória permanece 123' => ($manifest['database']['required_migration'] ?? '') === '123_published_slots_min_notice_policy.sql',
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - a automação da agenda e a confirmação operacional permanecem ativas na versão atual.\n";
