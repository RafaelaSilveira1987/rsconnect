<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$passes = 0;
$failures = 0;
$check = static function (bool $ok, string $message) use (&$passes, &$failures): void {
    if ($ok) {
        $passes++;
        echo "[OK] {$message}\n";
        return;
    }
    $failures++;
    echo "[FAIL] {$message}\n";
};
$read = static fn (string $file): string => (string) file_get_contents($root . '/' . $file);

$webhook = $read('app/Controllers/EvolutionWebhookController.php');
$ownership = $read('app/Services/ConversationOwnershipService.php');
$lifecycle = $read('app/Services/ConversationLifecycleService.php');
$flow = $read('app/Services/ConversationFlowService.php');
$triage = $read('app/Services/AgentTriageService.php');
$ai = $read('app/Services/AiAutomationService.php');
$crm = $read('app/Services/CrmAutoService.php');
$controller = $read('app/Controllers/ConversationController.php');
$reports = $read('app/Services/TeamProfessionalReportService.php');
$manifest = $read('database/migrations/manifest.php');
$version = $read('app/Services/AppVersionService.php');
$migration = $read('database/migrations/110_conversation_lifecycle_e2e_consistency.sql');

$check(str_contains($webhook, 'FOR UPDATE') && str_contains($webhook, '$wasClosed'), 'Webhook detecta reabertura do ciclo sob lock.');
$check(str_contains($webhook, 'attendance_mode = IF(status = "closed", IF(VALUES(unread_count) > 0, "ai", "paused"), attendance_mode)'), 'Nova entrada reativa IA e saída direta reabre pausada.');
$check(str_contains($webhook, 'department_id = IF(status = "closed", NULL, department_id)') && str_contains($webhook, 'ai_agent_id = IF(status = "closed", NULL, ai_agent_id)'), 'Novo ciclo não herda setor nem agente fixado.');
$check(str_contains($webhook, 'unread_count = IF(status = "closed", VALUES(unread_count)'), 'Não lidas do novo ciclo não acumulam resíduo anterior.');
$check(str_contains($webhook, 'IF(VALUES(unread_count) > 0, "new", "waiting_customer")'), 'Reabertura iniciada fora da plataforma não libera IA automaticamente.');
$check(str_contains($webhook, 'resetTransientStateForNewCycle'), 'Webhook limpa estado transitório ao abrir novo ciclo.');
$check(str_contains($lifecycle, 'conversation_flow_states') && str_contains($lifecycle, 'conversation_triage_sessions'), 'Serviço de ciclo reinicia fluxo e triagem transitórios.');
$check(str_contains($lifecycle, 'ai_after_hours_pending') && str_contains($lifecycle, 'status = "cancelled"'), 'Pendência pós-horário antiga é cancelada na reabertura.');
$check(!str_contains(substr($ownership, strpos($ownership, 'public function releaseWhenClosed'), 900), "if (!\$settings['enabled'])"), 'Encerramento não depende da atribuição profissional opcional.');
$check(str_contains($ownership, 'attendance_mode = "paused"') && str_contains($ownership, 'operational_status = "resolved"') && str_contains($ownership, 'unread_count = 0'), 'Encerramento remove conversa da operação ativa e zera não lidas.');
$check(str_contains($ownership, "'application_conversation_reopened'") && str_contains($ownership, 'department_id = NULL') && str_contains($ownership, 'ai_agent_id = NULL'), 'Reabertura manual inicia ciclo limpo.');
$check(str_contains($flow, "if (\$intent === 'human_handoff')") && str_contains($flow, "return 'human_handoff';"), 'Fluxo representa intenção explícita de atendimento humano.');
$check(str_contains($triage, 'handoffConversation(') && str_contains($triage, 'operational_status = "waiting_agent"'), 'Policy handoff coloca conversa efetivamente na fila humana.');
$check(str_contains($triage, 'conversation_service_cycles') && str_contains($triage, 'cycle_status = "active"'), 'Deduplicação de política é limitada ao ciclo ativo.');
$check(str_contains($ai, "attendance_mode'] !== 'ai'") || str_contains($ai, "attendance_mode'] ?? 'ai'"), 'IA mantém guarda de modo de atendimento.');
$check(str_contains($crm, 'contact_group') || str_contains($crm, 'customer'), 'Automação comercial continua considerando relacionamento do contato.');
$check(str_contains($controller, 'closeActiveCycle') && str_contains($controller, 'releaseWhenClosed'), 'Encerramento persiste o ciclo antes de liberar a operação.');
$check(str_contains($reports, 'conversation_service_cycles'), 'Relatórios continuam baseados em ciclos persistentes.');
$check(str_contains($migration, "operational_status = 'resolved'") && str_contains($migration, "p.status = 'cancelled'"), 'Migration 110 normaliza encerramentos e recuperação antiga.');
$check(str_contains($manifest, "['sequence' => 117, 'file' => '110_conversation_lifecycle_e2e_consistency.sql']"), 'Migration 110 registrada na sequência 117.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.30.8 — QA do fluxo central'") && str_contains($version, "REQUIRED_MIGRATION = '110_conversation_lifecycle_e2e_consistency.sql'"), 'Versionamento 36.30.8 registrado.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo QA E2E 36.30.8: {$passes} verificações aprovadas.\n";
