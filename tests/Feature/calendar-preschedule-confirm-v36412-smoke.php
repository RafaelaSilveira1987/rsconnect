<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$view = (string) file_get_contents($root . '/app/Views/calendar_availability/index.php');
$controller = (string) file_get_contents($root . '/app/Controllers/CalendarController.php');
$service = (string) file_get_contents($root . '/app/Services/CalendarAvailabilityService.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$manifest = json_decode((string) file_get_contents($root . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$checks = [
    'aba de pré-agendamento oferece confirmação real' => str_contains($view, 'Confirmar agendamento')
        && str_contains($view, "Router::url('/calendar/status')")
        && str_contains($view, 'name="status" value="confirmed"'),
    'confirmação só habilita com requisitos mínimos' => str_contains($view, '$canConfirmPreSchedule')
        && str_contains($view, '$requiresAvailabilityBeforeApproval')
        && str_contains($view, '$hasPreference'),
    'status validado também é reconhecido como horário pronto' => str_contains($view, 'in_array($availabilityStatus, [\'slot_selected\', \'validated\'], true)'),
    'backend continua revalidando disponibilidade antes de aprovar' => str_contains($controller, '$availabilityService->canApprove($tenantId, $appointmentBefore)')
        && str_contains($service, 'public function canApprove'),
    'aprovação converte pré-agendamento em compromisso normal' => str_contains($controller, 'SET is_pre_schedule = 0')
        && str_contains($controller, "'confirmed' => 'approved'"),
    'aprovação processa comunicação com o cliente' => str_contains($controller, 'new CalendarClientCommunicationService()')
        && str_contains($controller, '->handleStatusChange('),
    'pacote preserva o marco 36.41.2 e identifica a versão atual' => str_contains($version, 'RS Connect 36.41.2')
        && str_contains($version, 'RS Connect 36.41.6')
        && str_contains($version, 'RS Connect 36.41.3')
        && ($manifest['package_version'] ?? '') === '36.41.6',
    'migration obrigatória permanece 123' => ($manifest['database']['required_migration'] ?? '') === '123_published_slots_min_notice_policy.sql',
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

echo "OK - confirmação do pré-agendamento permanece disponível na versão atual.\n";
