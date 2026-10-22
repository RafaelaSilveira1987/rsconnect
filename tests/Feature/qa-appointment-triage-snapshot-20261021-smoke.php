<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/app/Core/Autoloader.php';
\App\Core\Autoloader::register(dirname(__DIR__, 2) . '/app');

use App\Services\AppointmentTriagePresentationService;

$failed = [];
$checks = 0;
$expect = static function (bool $condition, string $label) use (&$failed, &$checks): void {
    ++$checks;
    if (!$condition) {
        $failed[] = $label;
        echo "[FALHA] {$label}\n";
    } else {
        echo "[OK] {$label}\n";
    }
};
$view = new AppointmentTriagePresentationService();
$live = [
    'is_pre_schedule' => 0,
    'status' => 'confirmed',
    'current_triage_collected_json' => '{"brief_demand":"Demanda da conversa nova","first_visit":"Não"}',
    'current_demand_summary' => 'Demanda da conversa nova',
    'current_contact_group' => 'patient',
];
$old = $view->resolve($live, ['first_visit' => 'Primeira consulta?']);
$expect($old['collected'] === [] && $old['demand_summary'] === '', 'compromisso antigo sem snapshot não herda triagem atual');
$expect($old['group'] === '' && $old['historical_missing'], 'grupo atual não vira histórico de agendamento antigo');
$expect(!$old['has_snapshot'], 'registro sem snapshot é indicado como não capturado');

$pending = $live;
$pending['is_pre_schedule'] = 1;
$pending['status'] = 'awaiting_approval';
$open = $view->resolve($pending, ['first_visit' => 'Primeira consulta?']);
$expect($open['collected']['first_visit'] === 'Não', 'pré-agendamento pendente pode exibir triagem provisória');
$expect($open['demand_summary'] === 'Demanda da conversa nova', 'demanda provisória identificada como informação viva');
$expect(!$open['historical_missing'], 'pré-agendamento em andamento não é apresentado como histórico ausente');
$pendingWithSnapshot = $pending + [
    'context_snapshot_present' => true,
    'context_snapshot_collected_json' => '{"first_visit":"Sim"}',
    'context_snapshot_demand_summary' => 'Demanda provisória anterior',
];
$latestPending = $view->resolve($pendingWithSnapshot, ['first_visit' => 'Primeira consulta?']);
$expect($latestPending['collected']['first_visit'] === 'Não', 'pré-agendamento ainda pendente exibe a resposta mais recente');
$expect($latestPending['demand_summary'] === 'Demanda da conversa nova', 'demanda atual aparece apenas enquanto reserva ainda está pendente');

$confirmed = $live + [
    'context_snapshot_present' => true,
    'context_snapshot_collected_json' => '{"first_visit":"Sim","brief_demand":"Consulta inicial"}',
    'context_snapshot_labels_json' => '{"first_visit":"É a primeira consulta?"}',
    'context_snapshot_invalid_keys_json' => '["contact_phone"]',
    'context_snapshot_demand_summary' => 'Consulta inicial',
    'context_snapshot_demand_status' => 'collected',
    'context_snapshot_contact_group' => 'lead',
    'context_snapshot_locked_at' => '2026-10-21 10:00:00',
];
$stored = $view->resolve($confirmed, ['first_visit' => 'Pergunta renomeada depois']);
$expect($stored['collected']['first_visit'] === 'Sim', 'snapshot mantém resposta no momento da consulta anterior');
$expect($stored['demand_summary'] === 'Consulta inicial' && $stored['group'] === 'lead', 'demanda e classificação vêm do agendamento, não do contato atual');
$expect($stored['labels']['first_visit'] === 'É a primeira consulta?', 'rótulo antigo é mantido mesmo após reconfiguração');
$expect($stored['invalid'] === ['contact_phone'], 'respostas incompatíveis ficam auditáveis no próprio snapshot');
$expect(!$stored['historical_missing'] && $stored['has_snapshot'], 'histórico preservado é diferenciado do registro antigo');
$lockedStillFlagged = $confirmed;
$lockedStillFlagged['is_pre_schedule'] = 1;
$lockedRow = $view->resolve($lockedStillFlagged, ['first_visit' => 'Pergunta modificada']);
$expect($lockedRow['collected']['first_visit'] === 'Sim', 'snapshot congelado prevalece mesmo se flag antiga de pré-agendamento permanecer');

$root = dirname(__DIR__, 2);
$sql = file_get_contents($root . '/database/migrations/126_appointment_triage_snapshot.sql') ?: '';
$source = file_get_contents($root . '/app/Services/AppointmentTriageSnapshotService.php') ?: '';
$controller = file_get_contents($root . '/app/Controllers/CalendarController.php') ?: '';
$automatic = file_get_contents($root . '/app/Services/CalendarConversationService.php') ?: '';
$pre = file_get_contents($root . '/app/Services/PreSchedulingService.php') ?: '';
$expect(str_contains($sql, 'UNIQUE KEY uq_calendar_triage_snapshot_tenant_appointment'), 'migration impede snapshots duplicados por tenant e agendamento');
$expect(str_contains($sql, 'ON DELETE CASCADE') && str_contains($sql, 'locked_at DATETIME NULL'), 'snapshot vinculado ao compromisso e com bloqueio definitivo');
$expect(str_contains($source, 'IF(locked_at IS NULL, VALUES(collected_json), collected_json)'), 'respostas congeladas não podem ser sobrescritas por nova mensagem');
$expect(str_contains($source, 'WHERE tenant_id = ? AND appointment_id IN ('), 'leitura da agenda exige tenant_id e IDs autorizados');
$expect(str_contains($controller, 'lockIfConfirmed') && str_contains($automatic, 'lockIfConfirmed'), 'confirmação humana e automática congelam o histórico');
$expect(substr_count($pre, '(new AppointmentTriageSnapshotService())->capture(') >= 2, 'criação e atualização de pré-agendamento registram contexto');
$expect(str_contains($controller, 'context_snapshot_collected_json') && str_contains($controller, 'context_snapshot_locked_at'), 'painel consulta o snapshot próprio do agendamento');
$expect(str_contains(file_get_contents($root . '/app/Views/calendar/index.php') ?: '', 'histórico não disponível para este compromisso') || str_contains(file_get_contents($root . '/app/Views/calendar/index.php') ?: '', 'Histórico de triagem não disponível'), 'interface informa histórico ausente sem inventar respostas');
if ($failed !== []) {
    fwrite(STDERR, count($failed) . ' falha(s): ' . implode('; ', $failed) . PHP_EOL);
    exit(1);
}
echo "OK: {$checks} verificações de histórico de triagem.\n";
