<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$manifest = require $root . '/database/migrations/manifest.php';
$latest = end($manifest['migrations']);
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';
$config = json_decode(file_get_contents($root . '/manifest.json') ?: '', true);
$service = file_get_contents($root . '/app/Services/AppointmentTriageSnapshotService.php') ?: '';
$pre = file_get_contents($root . '/app/Services/PreSchedulingService.php') ?: '';
$human = file_get_contents($root . '/app/Controllers/CalendarController.php') ?: '';
$auto = file_get_contents($root . '/app/Services/CalendarConversationService.php') ?: '';
$checks = [
    'migration 126 incluída no manifesto ordenado' => ($latest['sequence'] ?? null) === 133 && ($latest['file'] ?? '') === '126_appointment_triage_snapshot.sql',
    'migration exigida pelo manifesto do pacote' => ($config['database']['required_migration'] ?? '') === '126_appointment_triage_snapshot.sql',
    'migration exigida pelo runtime' => str_contains($version, "REQUIRED_MIGRATION = '126_appointment_triage_snapshot.sql'"),
    'versão da entrega avançada sem apagar histórico' => ($config['package_version'] ?? '') === '36.44.5',
    'estado confirmado impede captura retroativa' => str_contains($service, 'if (!$freeze && (int) ($row[\'is_pre_schedule\'] ?? 0) !== 1)'),
    'captura valida vínculo com conversa do compromisso' => str_contains($service, '(int) ($row[\'conversation_id\'] ?? 0) !== $conversationId'),
    'atualização nunca modifica snapshot congelado' => str_contains($service, 'IF(locked_at IS NULL, VALUES(demand_summary), demand_summary)'),
    'confirmação pelo gestor captura triagem antes da aprovação' => strpos($human, '->capture(') < strpos($human, 'SET status = :status,'),
    'confirmação automática captura antes da confirmação externa' => strpos($auto, '->capture(') < strpos($auto, 'confirmMarkedAppointment($tenantId, $appointmentId)'),
    'criação e atualização do pré-agendamento capturam triagem' => substr_count($pre, 'AppointmentTriageSnapshotService())->capture(') >= 2,
];
$fail = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHA] ') . $label . PHP_EOL;
    if (!$ok) ++$fail;
}
if ($fail > 0) exit(1);
echo 'OK: ' . count($checks) . " contratos de migração/captura.\n";
