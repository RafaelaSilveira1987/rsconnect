<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failed = 0;
$check = static function (bool $condition, string $message, int &$failed): void {
    if ($condition) {
        echo "[OK] {$message}\n";
        return;
    }
    $failed++;
    echo "[FAIL] {$message}\n";
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$appCss = $read('public/assets/css/app.css');
$labView = $read('app/Views/agent_tests/index.php');
$alertsView = $read('app/Views/operations/alerts.php');
$queueView = $read('app/Views/queue/index.php');
$permissionsView = $read('app/Views/permissions/admin.php');
$privacyView = $read('app/Views/privacy/index.php');
$usersView = $read('app/Views/users/index.php');
$signupView = $read('app/Views/signup/admin.php');
$securityView = $read('app/Views/security/index.php');
$availabilityView = $read('app/Views/calendar_availability/index.php');
$n8nView = $read('app/Views/n8n_templates/index.php');
$layout = $read('app/Views/layouts/app.php');
$guestLayout = $read('app/Views/layouts/guest.php');
$restrictedLayout = $read('app/Views/layouts/restricted.php');
$version = $read('app/Services/AppVersionService.php');

$check(str_contains($appCss, 'RS Connect 36.29.10 — fechamento visual e QA responsivo'), 'camada final 36.29.10 presente no CSS compartilhado', $failed);
$check(!str_contains($labView, '<style>') && str_contains($appCss, '.agent-lab-page'), 'Laboratório usa CSS compartilhado sem bloco inline', $failed);
$check(str_contains($labView, 'data-agent-lab') && str_contains($labView, 'data-lab-send') && str_contains($labView, "Router::url('/agent-tests/simulate')"), 'hooks e endpoint funcional do Laboratório preservados', $failed);
$check(!str_contains($alertsView, '<style>') && str_contains($appCss, '.ops-monitor-summary') && str_contains($appCss, '.ops-incident-actions'), 'Avisos Operacionais usam CSS compartilhado', $failed);
$check(str_contains($alertsView, "Router::url('/operacao-alertas/save')") && str_contains($alertsView, "Router::url('/operacao-alertas/resolve')"), 'actions dos Avisos Operacionais preservadas', $failed);

$check(str_contains($appCss, '.queue-layout') && str_contains($appCss, '.queue-table tbody tr.queue-row') && str_contains($appCss, 'content:"Prioridade"'), 'Fila/Equipe possui layout desktop e cards mobile', $failed);
$check(str_contains($queueView, "Router::url('/queue/departments')") && str_contains($queueView, "Router::url('/conversations?conversation_id='"), 'rotas funcionais da Fila/Equipe preservadas', $failed);
$check(str_contains($queueView, 'name="operational_status"') && str_contains($queueView, 'name="priority"') && str_contains($queueView, 'name="department_id"'), 'filtros operacionais da fila preservados', $failed);

$check(str_contains($availabilityView, 'calendar-slot-groups') && str_contains($appCss, '.calendar-slot-row .btn'), 'Disponibilidade da Agenda incluída no acabamento mobile', $failed);
$check(str_contains($permissionsView, 'admin-permission-groups') && str_contains($appCss, '.permission-switch'), 'Permissões preservam controles e área de toque', $failed);
$check(str_contains($privacyView, 'client-privacy-layout') && str_contains($appCss, '.client-toggle-card:has(input:checked)'), 'Privacidade/LGPD preserva toggles com estado visual', $failed);
$check(str_contains($usersView, 'admin-form-drawer') && str_contains($appCss, '.client-team-actions'), 'Usuários/equipe permanecem compatíveis com drawers responsivos', $failed);
$check(str_contains($signupView, 'public-signup-settings-form') && str_contains($appCss, '.public-signup-settings-form'), 'Signup administrativo incluído no acabamento final', $failed);
$check(str_contains($securityView, 'security-validation-grid') && str_contains($appCss, '.security-validation-grid'), 'Segurança incluída no acabamento final', $failed);
$check(str_contains($n8nView, 'n8n-template-page') && str_contains($appCss, '.n8n-template-page .code-block'), 'templates n8n mantêm conteúdo técnico responsivo', $failed);

$check(str_contains($appCss, ':focus-visible') && str_contains($appCss, '@media (prefers-reduced-motion: reduce)'), 'foco por teclado e movimento reduzido contemplados', $failed);
$check(str_contains($layout, 'app.css?v=36.29.10') && str_contains($layout, 'app.js?v=36.29.10'), 'cache principal atualizado para 36.29.10', $failed);
$check(str_contains($guestLayout, 'app.css?v=36.29.10') && str_contains($restrictedLayout, 'app.css?v=36.29.10'), 'layouts guest/restricted usam o novo cache CSS', $failed);
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.29.10 — Fechamento visual e QA responsivo'"), 'pacote identificado como 36.29.10', $failed);
$check(str_contains($version, "REQUIRED_MIGRATION = '106_agent_message_grouping_context_priority.sql'"), 'migration obrigatória permanece inalterada', $failed);

exit($failed === 0 ? 0 : 1);
