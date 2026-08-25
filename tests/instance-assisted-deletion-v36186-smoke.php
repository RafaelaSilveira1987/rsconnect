<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$controller = file_get_contents($root . '/app/Controllers/InstanceController.php') ?: '';
$view = file_get_contents($root . '/app/Views/instances/index.php') ?: '';
$javascript = file_get_contents($root . '/public/assets/js/app.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/app.css') ?: '';
$routes = file_get_contents($root . '/routes/web.php') ?: '';
$layout = file_get_contents($root . '/app/Views/layouts/app.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

$checks = [
    'prévia autenticada da exclusão' => str_contains($routes, "'/instances/delete-preview'")
        && str_contains($controller, 'public function deletePreview()'),
    'impacto inclui relatórios e eventos' => str_contains($controller, "'scheduled_reports'")
        && str_contains($controller, "'connection_events'"),
    'migração integral de vínculos' => str_contains($controller, "updateReference(\$pdo, 'scheduled_reports'")
        && str_contains($controller, 'migrateAgentBindings(')
        && str_contains($controller, 'migrateConversations('),
    'conversas duplicadas são consolidadas' => str_contains($controller, 'replacementConflicts(')
        && str_contains($controller, 'mergeConversationFlowState(')
        && str_contains($controller, 'mergeAfterHoursPending(')
        && str_contains($controller, 'moveServiceCycles('),
    'operação usa bloqueio transacional' => str_contains($controller, 'findInstanceForUpdate(')
        && str_contains($controller, 'beginTransaction()')
        && str_contains($controller, 'FOR UPDATE'),
    'auditoria registra snapshot e estatísticas' => str_contains($controller, "'source_snapshot'")
        && str_contains($controller, "'dependency_counts'")
        && str_contains($controller, "'migration_stats'"),
    'interface mostra os vínculos' => str_contains($view, 'instance-delete-impact-grid')
        && str_contains($view, "'scheduled_reports' => 'Relatórios'")
        && str_contains($view, 'name="acknowledge_dependencies"'),
    'interface exige confirmação remota' => str_contains($view, 'name="acknowledge_remote_active"')
        && str_contains($javascript, 'connectedWithoutRemoteDelete'),
    'prévia atualiza destino e conflitos' => str_contains($javascript, 'loadDeletePreview')
        && str_contains($javascript, 'conversation_duplicates')
        && str_contains($javascript, 'agent_binding_duplicates'),
    'estilos da exclusão assistida' => str_contains($css, 'RS Connect 36.18.6')
        && str_contains($css, '.instance-delete-impact-grid'),
    'versão e cache renovados' => str_contains($version, 'RS Connect 36.18.6')
        && str_contains($layout, 'app.css?v=36.19.2')
        && str_contains($layout, 'app.js?v=36.19.2'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) $failed[] = $label;
}

exit($failed === [] ? 0 : 1);
