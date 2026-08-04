<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/Services/OperationalLanguageService.php';

use App\Services\OperationalLanguageService;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$whatsapp = OperationalLanguageService::incident([
    'event' => 'operations.alert.evolution',
    'title' => 'Evolution disconnected',
    'message' => '0/1 instância conectada',
    'severity' => 'critical',
]);
$assistant = OperationalLanguageService::incident([
    'event' => 'operations.alert.openai',
    'message' => 'OpenAI quota exceeded',
    'severity' => 'error',
], true);
$automation = OperationalLanguageService::incident([
    'event' => 'operations.alert.n8n',
    'message' => '3 falha(s) consecutiva(s) no n8n',
]);

$assert($whatsapp['title'] === 'WhatsApp desconectado', 'Evolution deve aparecer como WhatsApp desconectado.');
$assert(str_contains($whatsapp['impact'], 'Mensagens'), 'Aviso do WhatsApp deve explicar o impacto.');
$assert($whatsapp['severity_label'] === 'Ação imediata', 'Criticidade deve usar linguagem de ação.');
$assert(str_contains(strtolower($assistant['title']), 'assistente virtual'), 'OpenAI deve aparecer como assistente virtual para o cliente.');
$assert(str_contains(strtolower($automation['title']), 'automação'), 'n8n deve aparecer como automação.');
$assert(!str_contains(OperationalLanguageService::replaceTechnicalTerms('Webhook n8n falhou no callback'), 'n8n'), 'Texto simplificado não deve expor n8n.');
$assert(str_contains(OperationalLanguageService::alertMessage($whatsapp, 'Empresa Teste'), 'O que fazer agora:'), 'WhatsApp administrativo deve conter orientação.');

$alerts = $read('app/Views/operations/alerts.php');
$operations = $read('app/Views/operations/index.php');
$companyHealth = $read('app/Views/companies/health.php');
$notifications = $read('app/Views/notifications/index.php');
$clientDashboard = $read('app/Views/dashboard/client.php');
$adminDashboard = $read('app/Views/dashboard/admin.php');
$alertService = $read('app/Services/OperationalAlertService.php');
$layout = $read('app/Views/layouts/app.php');
$version = $read('app/Services/AppVersionService.php');

foreach ([$alerts, $operations, $companyHealth] as $source) {
    $assert(str_contains($source, 'OperationalLanguageService'), 'Telas operacionais devem usar o tradutor central.');
}
$assert(str_contains($alerts, 'O que aconteceu'), 'Central de avisos deve explicar o que aconteceu.');
$assert(str_contains($alerts, 'O que pode ser afetado'), 'Central de avisos deve explicar o impacto.');
$assert(str_contains($alerts, 'O que fazer agora'), 'Central de avisos deve orientar a ação.');
$assert(str_contains($alerts, 'Ver detalhes técnicos'), 'Super Admin deve manter os detalhes técnicos expansíveis.');
$assert(str_contains($alerts, 'Situações em aberto'), 'Incidentes devem aparecer como situações em aberto.');
$assert(str_contains($notifications, 'Auth::isSuperAdmin') && str_contains($notifications, 'OperationalLanguageService::notification'), 'Notificações devem diferenciar cliente e Super Admin.');
$assert(str_contains($clientDashboard, 'OperationalLanguageService::notification'), 'Dashboard do cliente deve simplificar os últimos avisos.');
$assert(str_contains($clientDashboard, 'Assistentes virtuais'), 'Dashboard do cliente deve evitar Agentes de IA no resumo.');
$assert(str_contains($adminDashboard, 'OperationalLanguageService'), 'Dashboard do Super Admin deve traduzir a saúde operacional.');
$assert(str_contains($alertService, 'OperationalLanguageService::alertMessage'), 'WhatsApp administrativo deve usar a mensagem estruturada.');
$assert(str_contains($alertService, 't.name AS tenant_name'), 'Aviso administrativo deve incluir o nome da empresa afetada.');
$assert(str_contains($layout, 'Avisos do sistema'), 'Menu deve usar o nome Avisos do sistema.');
$assert(str_contains($layout, 'OperationalLanguageService::replaceTechnicalTerms'), 'Mensagens rápidas do sistema devem ser simplificadas no layout global.');
$assert(str_contains($layout, 'app.css?v=36.14.0') && str_contains($layout, 'app.js?v=36.14.0'), 'Cache dos assets deve estar em 36.14.0.');
$assert(str_contains($version, 'RS Connect 36.14.0') && str_contains($version, '074_conversation_message_attachments.sql'), 'Versão 36.14.0 deve exigir a migration 074.');

if ($failures !== []) {
    fwrite(STDERR, "Falhas:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - linguagem clara e diagnóstico simplificado validados na v36.14.0.\n";
