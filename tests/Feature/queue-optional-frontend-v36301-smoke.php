<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [
    'modules' => $root . '/app/Services/TenantModuleService.php',
    'company_controller' => $root . '/app/Controllers/CompanyController.php',
    'company_view' => $root . '/app/Views/companies/settings.php',
    'layout' => $root . '/app/Views/layouts/app.php',
    'queue_view' => $root . '/app/Views/queue/index.php',
    'queue_controller' => $root . '/app/Controllers/QueueController.php',
    'ownership' => $root . '/app/Services/ConversationOwnershipService.php',
    'conversation_view' => $root . '/app/Views/conversations/index.php',
    'conversation_controller' => $root . '/app/Controllers/ConversationController.php',
    'css' => $root . '/public/assets/css/app.css',
    'js' => $root . '/public/assets/js/app.js',
    'version' => $root . '/app/Services/AppVersionService.php',
];

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$content = [];
foreach ($files as $key => $path) {
    $check(is_file($path), "Arquivo ausente: {$path}");
    $content[$key] = is_file($path) ? (string) file_get_contents($path) : '';
}

$check(str_contains($content['modules'], "'queue' => ["), 'Módulo queue não foi registrado.');
$check(str_contains($content['modules'], "'label' => 'Fila e setores'") && str_contains($content['modules'], "'default_enabled' => true"), 'Queue deve preservar compatibilidade e poder ser desligada por empresa.');
$check(str_contains($content['modules'], 'saveModuleState'), 'Serviço não possui gravação dedicada do estado operacional.');
$check(str_contains($content['company_controller'], "queue_settings_submitted"), 'Configuração da fila não é salva pela empresa.');
$check(str_contains($content['company_view'], 'Usar Fila e setores nesta empresa'), 'Toggle da fila não está disponível em Minha empresa.');
$check(str_contains($content['layout'], "Auth::can('queue.view') && \$moduleVisible('queue')"), 'Menu da fila não respeita visibilidade do módulo.');
$check(str_contains($content['ownership'], 'queueEnabledForTenant'), 'Regras de ownership não ignoram setor quando a fila está desligada.');
$check(str_contains((string) file_get_contents($root . '/app/Services/AiModelService.php'), "enabled(\$tenantId, 'queue')"), 'IA não ignora setor quando a fila está desligada.');
$check(str_contains($content['queue_controller'], "enabled((int) \$conversation['tenant_id'], 'queue')"), 'Backend da distribuição não bloqueia fila desativada.');
$check(str_contains($content['conversation_controller'], "'queueEnabled' => \$queueEnabled"), 'Conversas não recebe o estado da fila.');
$check(str_contains($content['conversation_view'], 'if ($queueEnabled)'), 'Drawer de Conversas não esconde setor quando fila está desativada.');
$check(str_contains($content['queue_view'], 'queue-distribution-drawer'), 'Drawer de distribuição não foi criado.');
$check(str_contains($content['queue_view'], 'data-queue-assignment='), 'Payload de distribuição por conversa não foi criado.');
$check(!str_contains($content['queue_view'], 'queue-assign-menu'), 'Popover antigo de distribuição ainda existe.');
$check(str_contains($content['queue_view'], 'department-team-editor'), 'Equipe do setor não foi organizada em editor recolhível.');
$check(str_contains($content['css'], '.queue-table{width:100%;min-width:0!important;table-layout:fixed}'), 'Tabela da fila não possui layout fixo responsivo.');
$check(str_contains($content['css'], '@media(max-width:860px)'), 'Breakpoint de cards da fila não foi adicionado.');
$check(str_contains($content['js'], 'data-queue-assign-open'), 'JavaScript do drawer de distribuição não foi adicionado.');
$check(str_contains($content['js'], 'fillUsers'), 'Drawer não filtra responsáveis conforme o setor.');
$check(str_contains($content['version'], 'RS Connect 36.30.2 — Notas internas da conversa'), 'Versão 36.30.2 não foi registrada.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL queue-optional-frontend-v36301-smoke\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK queue-optional-frontend-v36301-smoke (" . count($files) . " arquivos validados)\n";
