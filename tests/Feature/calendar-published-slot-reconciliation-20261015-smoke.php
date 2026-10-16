<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'service' => file_get_contents($root . '/app/Services/PublishedSlotReconciliationService.php'),
    'route' => file_get_contents($root . '/routes/web.php'),
    'controller' => file_get_contents($root . '/app/Controllers/CalendarAvailabilityController.php'),
    'mapping' => file_get_contents($root . '/app/Core/PublicId.php'),
    'view' => file_get_contents($root . '/app/Views/calendar_availability/index.php'),
];
$checks = [
    'rota protegida' => str_contains($files['route'], "'/calendar/availability/internal-slots/reconcile'") && str_contains($files['route'], "'permission:calendar.manage'"),
    'uuid da vaga interna' => str_contains($files['mapping'], "'/calendar/availability/internal-slots/reconcile'"),
    'csrf e post' => str_contains($files['controller'], "Csrf::validate(\$_POST['_token']"),
    'conferência explícita na interface' => str_contains($files['view'], 'Conferir e vincular') && str_contains($files['view'], 'appointment_ids[]'),
    'locks e transação' => str_contains($files['service'], 'beginTransaction()') && str_contains($files['service'], 'FOR UPDATE'),
    'vínculo idempotente por validação' => str_contains($files['service'], 'currentIds !== $expectedIds'),
    'rollback em falhas' => str_contains($files['service'], 'rollBack()'),
    'não muda status do compromisso' => !str_contains($files['service'], 'UPDATE calendar_appointments'),
    'consulta conflitos restantes' => str_contains($files['service'], 'PublishedSlotAppointmentConflictService'),
];
foreach ($checks as $title => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FALHA: {$title}\n");
        exit(1);
    }
    echo "OK: {$title}\n";
}
