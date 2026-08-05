<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tenant = (string) file_get_contents($root . '/app/Services/TenantExecutiveReportService.php');
$pdf = (string) file_get_contents($root . '/app/Services/ExecutiveReportPdfService.php');
$scheduled = (string) file_get_contents($root . '/app/Services/ScheduledReportService.php');
$view = (string) file_get_contents($root . '/app/Views/reports/index.php');
$history = (string) file_get_contents($root . '/app/Views/reports/automatic.php');
$version = (string) file_get_contents($root . '/app/Services/AppVersionService.php');

$checks = [
    'conversas atendidas usam movimento real do período' => str_contains($tenant, "'active_conversations'")
        && str_contains($tenant, 'COUNT(DISTINCT conversation_id)'),
    'atenção usa a mesma regra da lista exibida' => str_contains($tenant, "'attention_conversations'")
        && str_contains($tenant, 'unread_count > 0 OR attendance_mode = "human"')
        && str_contains($tenant, 'c.unread_count > 0 OR c.attendance_mode = "human"'),
    'agenda classifica pendências e agendados' => str_contains($tenant, "'appointments_pending'")
        && str_contains($tenant, '"pre_scheduled","awaiting_approval","rescheduled"')
        && str_contains($tenant, "['label' => 'Agendados'"),
    'pdf usa os campos reais da consulta de equipe' => str_contains($pdf, "\$row['label'] ?? 'Profissional'")
        && str_contains($pdf, "\$row['total'] ?? 0")
        && str_contains($pdf, "['Profissional', 'Conversas', 'Respostas']"),
    'pdf diferencia mensagens de atendimentos' => str_contains($pdf, 'Mensagens ao longo do tempo')
        && str_contains($pdf, 'Conversas atendidas'),
    'histórico informa destino e ação correta' => str_contains($scheduled, 'delivery_destinations')
        && str_contains($scheduled, 'delivery_summary')
        && str_contains($history, "'Reenviar' : 'Enviar'"),
    'painel usa nomenclatura consistente' => str_contains($view, 'Conversas atendidas')
        && str_contains($view, 'Mensagens ao longo do tempo')
        && str_contains($view, 'Exportações detalhadas'),
    'pacote de correção identificado' => str_contains($version, 'RS Connect 36.15.1-r4')
        && str_contains($version, '075_scheduled_reports_and_deliveries.sql'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - consistência do painel, PDF, agenda e histórico validada na v36.15.1-r4.\n";
