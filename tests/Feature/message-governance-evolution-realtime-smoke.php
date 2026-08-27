<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$root = dirname(__DIR__, 2);
$migration = (string) file_get_contents($root . '/database/migrations/063_message_governance_evolution_realtime.sql');
$governance = (string) file_get_contents($root . '/app/Services/MessageGovernanceService.php');
$conversation = (string) file_get_contents($root . '/app/Controllers/ConversationController.php');
$webhook = (string) file_get_contents($root . '/app/Controllers/EvolutionWebhookController.php');
$instances = (string) file_get_contents($root . '/app/Controllers/InstanceController.php');
$evolution = (string) file_get_contents($root . '/app/Services/EvolutionService.php');
$routes = (string) file_get_contents($root . '/routes/web.php');
$appVersion = (string) file_get_contents($root . '/app/Services/AppVersionService.php');
$templateFile = $root . '/docs/n8n_templates/template-message-retention.json';
$template = json_decode((string) file_get_contents($templateFile), true);

$assert(str_contains($migration, 'whatsapp_display_name'), 'Migration deve criar nome público do usuário.');
$assert(str_contains($migration, 'message_retention_mode'), 'Migration deve criar política de retenção.');
$assert(str_contains($migration, 'delivered_content'), 'Migration deve separar conteúdo original do conteúdo entregue.');
$assert(str_contains($migration, 'evolution_connection_events'), 'Migration deve criar histórico de conexão Evolution.');
$assert(str_contains($migration, 'message_retention_runs'), 'Migration deve registrar execuções da retenção.');
$assert(str_contains($governance, 'prepareHumanMessage'), 'Serviço deve preparar assinatura humana.');
$assert(str_contains($governance, "\$mode === 'reduced'") && str_contains($governance, "\$mode === 'ephemeral'") && str_contains($governance, "'message_retention_mode' => 'reduced'"), 'Serviço deve reconhecer os três modos de retenção.');
$assert(str_contains($conversation, "'delivered_content' => \$preparedMessage['delivered']"), 'Envio manual deve persistir o conteúdo entregue.');
$assert(str_contains($webhook, 'applyQrCodeUpdate'), 'Webhook deve processar atualização do QR Code.');
$assert(str_contains($webhook, 'applyConnectionUpdate'), 'Webhook deve processar atualização da conexão.');
$assert(str_contains($instances, 'statusFeed'), 'Instâncias devem expor feed de status autenticado.');
$assert(str_contains($instances, 'configureRealtimeWebhook'), 'Criação/reconexão deve configurar webhook em tempo real.');
$assert(str_contains($evolution, "'/webhook/set/'"), 'EvolutionService deve configurar webhook por instância.');
$assert(str_contains($routes, '/instances/status-feed'), 'Rota do feed de status deve existir.');
$assert(str_contains($routes, '/webhooks/messages/retention/run'), 'Rota automática da retenção deve existir.');
$assert(is_array($template), 'Template de retenção deve ser JSON válido.');
$assert(str_contains((string) file_get_contents($templateFile), 'X-RS-Message-Retention-Token'), 'Template deve autenticar pelo header dedicado.');
$assert(str_contains($appVersion, '063_message_governance_evolution_realtime.sql'), 'Painel deve exigir a migration 063.');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - assinatura humana, retenção e Evolution em tempo real validados.\n";
