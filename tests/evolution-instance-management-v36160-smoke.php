<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$service = $read('app/Services/EvolutionService.php');
$controller = $read('app/Controllers/InstanceController.php');
$webhook = $read('app/Controllers/EvolutionWebhookController.php');
$view = $read('app/Views/instances/index.php');
$javascript = $read('public/assets/js/app.js');
$routes = $read('routes/web.php');
$migration = $read('database/migrations/076_evolution_instance_management.sql');
$diagnostic = $read('database/diagnostics/evolution_instance_management_v36.16.0.sql');
$version = $read('app/Services/AppVersionService.php');
$instructions = $read('INSTRUCOES-v36.16.0.md');

$checks = [
    'serviço cria instância e conecta QR Code' => str_contains($service, 'function createInstance')
        && str_contains($service, "'/instance/create'")
        && str_contains($service, 'function connectQrCode'),
    'serviço administra webhook e settings' => str_contains($service, 'function setWebhook')
        && str_contains($service, "'/webhook/set/'")
        && str_contains($service, 'function setSettings')
        && str_contains($service, "'/settings/set/'"),
    'serviço reinicia desconecta e exclui' => str_contains($service, 'function restartInstance')
        && str_contains($service, 'function logoutInstance')
        && str_contains($service, 'function deleteInstance'),
    'controller cria remotamente e possui modo externo' => str_contains($controller, "'managed'")
        && str_contains($controller, "'external'")
        && str_contains($controller, 'createInstance($integration'),
    'controller aplica configurações e webhook' => str_contains($controller, 'saveSettings')
        && str_contains($controller, 'evolutionSettingsPayload')
        && str_contains($controller, 'configureRealtimeWebhook'),
    'ações remotas foram expostas com segurança' => str_contains($controller, 'remoteAction')
        && str_contains($routes, "'/instances/settings'")
        && str_contains($routes, "'/instances/action'")
        && substr_count($routes, "['auth', 'super_admin', 'csrf']") >= 6,
    'tela possui criação e configuração nativas' => str_contains($view, 'Criar automaticamente na Evolution')
        && str_contains($view, 'Configurar Evolution')
        && str_contains($view, 'Ignorar grupos')
        && str_contains($view, 'Excluir também na Evolution API'),
    'javascript preenche configurações e preserva instância gerenciada' => str_contains($javascript, 'data-instance-settings-field')
        && str_contains($javascript, "button.dataset.managementMode === 'managed'")
        && str_contains($javascript, 'deleteRemote.checked = managed'),
    'webhook aplica segunda camada de filtros' => str_contains($webhook, 'message_reception_disabled')
        && str_contains($webhook, 'ignoredRemoteJidReason')
        && str_contains($webhook, 'group_ignored')
        && str_contains($webhook, 'own_message'),
    'migration adiciona estrutura de gerenciamento' => substr_count($migration, "TABLE_NAME='evolution_instances'") >= 20
        && str_contains($migration, 'management_mode')
        && str_contains($migration, 'webhook_events')
        && str_contains($migration, 'idx_instances_management'),
    'diagnóstico confere colunas e índice' => str_contains($diagnostic, 'colunas_encontradas')
        && str_contains($diagnostic, 'idx_instances_management'),
    'versão exige migration 076' => str_contains($version, 'RS Connect 36.16.0')
        && str_contains($version, '076_evolution_instance_management.sql'),
    'implantação está documentada' => str_contains($instructions, 'EVOLUTION_DEFAULT_API_KEY')
        && str_contains($instructions, 'Gerar QR Code')
        && str_contains($instructions, 'Homologação recomendada'),
];

$failed = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failed !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failed) . "\n");
    exit(1);
}

echo "OK - gerenciamento nativo da Evolution API v36.16.0 validado.\n";
