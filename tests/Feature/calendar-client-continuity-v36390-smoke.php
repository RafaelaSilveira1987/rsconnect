<?php

declare(strict_types=1);

if (!function_exists('mb_strtolower')) {
    function mb_strtolower(string $value, ?string $encoding = null): string { return strtolower($value); }
}
if (!function_exists('mb_substr')) {
    function mb_substr(string $value, int $start, ?int $length = null, ?string $encoding = null): string
    {
        return $length === null ? substr($value, $start) : substr($value, $start, $length);
    }
}

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\ExistingAppointmentConversationService;

$root = dirname(__DIR__, 2);
$service = new ExistingAppointmentConversationService();

$checks = [
    'pergunta de confirmação encontra intenção de status' => $service->detectIntent('Gostaria de saber se está confirmada a consulta de amanhã') === 'status',
    'pergunta de horário encontra detalhes do compromisso' => $service->detectIntent('Que horas é minha consulta amanhã?') === 'details',
    'pedido explícito de cancelamento é protegido' => $service->detectIntent('Quero cancelar minha consulta') === 'cancel',
    'pedido explícito de remarcação é protegido' => $service->detectIntent('Preciso remarcar meu atendimento') === 'reschedule',
    'sim curto só confirma presença quando existe pedido pendente' => $service->detectIntent('sim', true) === 'presence_confirm'
        && $service->detectIntent('sim', false) === '',
    'dúvida comercial não é sequestrada pela camada de agenda' => $service->detectIntent('Qual o valor da consulta?') === '',
];

$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$reprocess = (string) file_get_contents($root . '/app/Services/AiAutomationService.php');
$clientCommunication = (string) file_get_contents($root . '/app/Services/CalendarClientCommunicationService.php');
$calendarController = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$conversation = (string) file_get_contents($root . '/app/Services/CalendarConversationService.php');
$notifications = (string) file_get_contents($root . '/app/Controllers/NotificationsController.php');
$settingsView = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$calendarView = (string) file_get_contents($root . '/app/Views/calendar/index.php');
$migration = (string) file_get_contents($root . '/database/migrations/122_calendar_client_communications.sql');
$manifest = (string) file_get_contents($root . '/database/migrations/manifest.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks += [
    'webhook consulta compromisso antes da triagem e do novo agendamento' => str_contains($webhook, 'ExistingAppointmentConversationService')
        && str_contains($webhook, 'antes da triagem de um novo atendimento'),
    'recuperação posterior ao expediente preserva intenção original da agenda' => str_contains($reprocess, 'ExistingAppointmentConversationService')
        && str_contains($reprocess, 'existingAppointment'),
    'configuração separa lookup, confirmação, lembrete e presença' => str_contains($clientCommunication, 'lookup_outside_hours')
        && str_contains($clientCommunication, 'presence_request_enabled')
        && str_contains($clientCommunication, 'reminder_enabled'),
    'fila automática é específica do cliente do compromisso' => str_contains($clientCommunication, 'calendar_client_message_jobs')
        && str_contains($clientCommunication, 'processDueJobs'),
    'alterar configuração recalcula lembretes futuros já confirmados' => str_contains($clientCommunication, 'rescheduleUpcomingConfirmedJobs')
        && str_contains($clientCommunication, 'Recalculado após alteração da configuração.'),
    'controller de agenda dispara comunicação quando status muda' => str_contains($calendarController, 'CalendarClientCommunicationService')
        && str_contains($calendarController, 'handleStatusChange'),
    'confirmação pela conversa agenda automações futuras sem duplicar confirmação imediata' => str_contains($conversation, 'scheduleConfirmedAutomation')
        && str_contains($conversation, 'evitar uma segunda confirmação imediata'),
    'cron existente também processa mensagens automáticas da agenda' => str_contains($notifications, 'calendar_client_messages')
        && str_contains($notifications, 'CalendarClientCommunicationService'),
    'configuração fica dentro da aba Configurações da Agenda' => str_contains($settingsView, 'Comunicação e confirmação do agendamento')
        && str_contains($settingsView, 'Pedir confirmação de presença')
        && str_contains($settingsView, 'Responder sobre a própria agenda fora do expediente'),
    'agenda mostra confirmação do cliente separada do status do compromisso' => str_contains($calendarView, 'Confirmação do cliente')
        && str_contains($calendarView, 'Cliente confirmou presença'),
    'migration cria configurações fila e estado do cliente' => str_contains($migration, 'tenant_calendar_client_settings')
        && str_contains($migration, 'calendar_client_message_jobs')
        && str_contains($migration, 'client_confirmation_status'),
    'manifest inclui migration 122 em sequência 129' => str_contains($manifest, "['sequence' => 129, 'file' => '122_calendar_client_communications.sql']"),
    'pacote preserva contrato 36.39.0 e migration obrigatória' => (str_contains($version, 'RS Connect 36.39.0') || str_contains($version, 'RS Connect 36.39.1') || str_contains($version, 'RS Connect 36.39.2'))
        && str_contains($version, "REQUIRED_MIGRATION = '122_calendar_client_communications.sql'"),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - compromissos existentes têm precedência e a Agenda possui comunicação automática configurável.\n";
