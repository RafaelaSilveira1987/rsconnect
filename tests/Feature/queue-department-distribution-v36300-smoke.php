<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failed = 0;
$check = static function (bool $condition, string $message, int &$failed): void {
    echo ($condition ? '[OK] ' : '[FAIL] ') . $message . "\n";
    if (!$condition) {
        $failed++;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$routes = $read('routes/web.php');
$queueController = $read('app/Controllers/QueueController.php');
$ownership = $read('app/Services/ConversationOwnershipService.php');
$conversationController = $read('app/Controllers/ConversationController.php');
$queueView = $read('app/Views/queue/index.php');
$conversationView = $read('app/Views/conversations/index.php');
$layout = $read('app/Views/layouts/app.php');
$aiAutomation = $read('app/Services/AiAutomationService.php');
$aiModel = $read('app/Services/AiModelService.php');
$migration = $read('database/migrations/107_service_department_memberships.sql');
$manifest = $read('database/migrations/manifest.php');
$version = $read('app/Services/AppVersionService.php');
$appCss = $read('public/assets/css/app.css');
$appJs = $read('public/assets/js/app.js');

$check(str_contains($routes, 'use App\\Controllers\\QueueController;'), 'QueueController importado nas rotas', $failed);
$check(str_contains($routes, "'/queue'") && str_contains($routes, "'/queue/departments/members'") && str_contains($routes, "'/queue/assign'"), 'rotas de fila, equipe e distribuição ativadas', $failed);
$check(str_contains($routes, "'/conversations/department'") && str_contains($conversationController, 'function assignDepartment'), 'transferência de conversa para setor possui endpoint próprio', $failed);

$check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS service_department_members'), 'migration cria vínculo usuário ↔ setor', $failed);
$check(str_contains($migration, 'UNIQUE KEY uq_service_department_member'), 'migration impede vínculo duplicado', $failed);
$check(str_contains($manifest, "'sequence' => 114") && str_contains($manifest, "107_service_department_memberships.sql"), 'manifest registra migration 107 na sequência 114', $failed);

$check(str_contains($queueController, 'function syncDepartmentMembers') && str_contains($queueController, 'userBelongsToDepartment'), 'Fila gerencia e valida membros dos setores', $failed);
$check(str_contains($queueController, "'department_queue'") && str_contains($queueController, "'waiting_agent'"), 'distribuição para setor sem usuário retorna conversa à espera', $failed);
$check(str_contains($queueController, 'ct.tenant_id = c.tenant_id') && str_contains($queueController, 'd.tenant_id = c.tenant_id'), 'joins operacionais da Fila respeitam tenant', $failed);
$check(str_contains($queueView, "Router::url('/queue/departments/members')") && str_contains($queueView, 'name="member_ids[]"'), 'tela permite configurar equipe por setor', $failed);
$check(str_contains($queueView, "Router::url('/queue/assign')") && str_contains($queueView, 'Salvar distribuição'), 'tela permite distribuir conversa diretamente pela Fila', $failed);

$check(str_contains($ownership, 'function changeDepartment') && str_contains($ownership, 'Esta conversa está na fila de outro setor'), 'ownership aplica regra de pertencimento ao setor', $failed);
$check(str_contains($ownership, 'service_department_members') && str_contains($ownership, 'actor_can_serve_department'), 'snapshot de ownership considera setor do usuário', $failed);
$check(str_contains($ownership, 'departmentHasActiveMembers') && str_contains($queueController, 'departmentHasActiveMembers'), 'setor sem equipe ativa não recebe conversa por engano', $failed);
$check(str_contains($conversationView, 'Setor e fila') && str_contains($conversationView, "Router::url('/conversations/department')"), 'drawer de Conversas expõe setor e transferência', $failed);
$check(str_contains($conversationView, 'data-conversation-department') && str_contains($appJs, 'department_name'), 'lista de conversas exibe/atualiza setor no polling', $failed);

$check(str_contains($aiAutomation, 'd.name AS department_name') && str_contains($aiAutomation, 'd.tenant_id = c.tenant_id'), 'automação carrega setor com isolamento por tenant', $failed);
$check(str_contains($aiModel, 'Setor operacional atual:') && str_contains($aiModel, 'Considere esse setor como informação confirmada'), 'IA recebe setor como contexto operacional confirmado', $failed);
$check(str_contains($layout, 'Fila e setores') && str_contains($layout, "Auth::can('queue.view')"), 'menu exibe Fila e setores conforme permissão', $failed);
$check(str_contains($appCss, 'RS Connect 36.30.0 — fila, setores e equipe operacional integrada'), 'CSS contém acabamento da distribuição operacional', $failed);

$check(str_contains($layout, 'app.css?v=36.30.0') && str_contains($layout, 'app.js?v=36.30.0'), 'cache principal atualizado para 36.30.0', $failed);
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.30.0 — Fila e setores integrados ao atendimento'"), 'versão identificada como 36.30.0', $failed);
$check(str_contains($version, "REQUIRED_MIGRATION = '107_service_department_memberships.sql'"), 'migration obrigatória atualizada para 107', $failed);

exit($failed === 0 ? 0 : 1);
