<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\AppointmentReminderEligibilityService as Eligibility;
use App\Services\CalendarClientCommunicationService;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    if (!$condition) {
        throw new RuntimeException('FALHOU: ' . $message);
    }
    echo '[OK] ' . $message . PHP_EOL;
    ++$checks;
};

foreach (['pre_scheduled', 'awaiting_approval', 'scheduled', 'rejected', 'cancelled', 'rescheduled', 'completed'] as $status) {
    $assert(!Eligibility::isConfirmed(['status' => $status, 'is_pre_schedule' => 1, 'approval_status' => 'pending']),
        'Sem lembrete com status ' . $status);
}
$assert(!Eligibility::isConfirmed(['status' => 'confirmed', 'is_pre_schedule' => 1, 'approval_status' => 'pending']), 'Rejeita confirmação inconsistente com aprovação pendente');
$assert(!Eligibility::isConfirmed(['status' => 'confirmed', 'is_pre_schedule' => 1]), 'Rejeita pré-agendamento sem evidência de aprovação');
$assert(Eligibility::isConfirmed(['status' => 'confirmed', 'is_pre_schedule' => 0, 'approval_status' => 'approved']), 'Aprovado e confirmado pode receber lembrete');
$assert(Eligibility::isConfirmed(['status' => 'confirmed', 'is_pre_schedule' => 0, 'approval_status' => null]), 'Confirmação manual sem pré-agendamento mantém compatibilidade');
$assert(Eligibility::isConfirmed(['status' => 'confirmed', 'is_pre_schedule' => 1, 'approval_status' => 'approved']), 'Transição aprovada e confirmada é válida');

$service = new CalendarClientCommunicationService();
$reflection = new ReflectionMethod($service, 'shouldSkipJob');
$reflection->setAccessible(true);
$job = ['event_key' => CalendarClientCommunicationService::EVENT_REMINDER, 'expected_starts_at' => '2027-10-17 10:00:00'];
$pending = ['status' => 'awaiting_approval', 'is_pre_schedule' => 1, 'approval_status' => 'pending', 'starts_at' => '2027-10-17 10:00:00'];
$assert($reflection->invoke($service, $job, $pending) === true, 'Worker cliente descarta lembrete antigo de pré-agendamento');
$confirmed = ['status' => 'confirmed', 'is_pre_schedule' => 0, 'approval_status' => 'approved', 'starts_at' => '2027-10-17 10:00:00'];
$assert($reflection->invoke($service, $job, $confirmed) === false, 'Worker cliente preserva lembrete da consulta aprovada');
$job['event_key'] = CalendarClientCommunicationService::EVENT_PRESENCE_REQUEST;
$assert($reflection->invoke($service, $job, $pending) === true, 'Worker cliente descarta pedido de presença de pré-agendamento');

$root = dirname(__DIR__, 2);
$orchestrator = file_get_contents($root . '/app/Services/NotificationOrchestratorService.php');
$delivery = file_get_contents($root . '/app/Services/NotificationDeliveryService.php');
$controller = file_get_contents($root . '/app/Controllers/CalendarController.php');
$conversation = file_get_contents($root . '/app/Services/CalendarConversationService.php');
$assert(str_contains($orchestrator, 'AppointmentReminderEligibilityService::isConfirmed($persisted)'), 'Orquestrador valida registro do banco antes de agendar');
$assert(str_contains($delivery, 'AppointmentReminderEligibilityService::isConfirmed($appointment)'), 'Worker de notificações revalida ao enviar');
$start = strpos($controller, '$appointmentId = (int) Database::connection()->lastInsertId();');
$end = strpos($controller, '// 36.39.0: comunicação com o cliente', $start);
$assert(!str_contains(substr($controller, $start, $end - $start), 'scheduleAppointmentReminder('), 'Criação de compromisso não agenda lembrete');
$start = strpos($conversation, 'private function notifyProfessional(');
$end = strpos($conversation, 'private function notifyFailure(', $start);
$assert(!str_contains(substr($conversation, $start, $end - $start), 'scheduleAppointmentReminder('), 'Notificação de pré-reserva à equipe não agenda lembrete');

echo 'OK - ' . $checks . " verificações aprovadas.\n";
