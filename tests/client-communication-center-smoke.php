<?php

declare(strict_types=1);

$failures = [];
$assert = static function (bool $condition, string $label) use (&$failures): void {
    if (!$condition) {
        $failures[] = $label;
    }
};

$service = (string) file_get_contents(__DIR__ . '/../app/Services/ClientCommunicationService.php');
$controller = (string) file_get_contents(__DIR__ . '/../app/Controllers/CommunicationsController.php');
$layout = (string) file_get_contents(__DIR__ . '/../app/Views/layouts/app.php');
$view = (string) file_get_contents(__DIR__ . '/../app/Views/communications/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/js/app.js');
$migration = (string) file_get_contents(__DIR__ . '/../database/migrations/058_client_communication_center.sql');
$routes = (string) file_get_contents(__DIR__ . '/../routes/web.php');

$assert(str_contains($migration, 'client_communication_replies'), 'migration deve criar replies');
$assert(str_contains($migration, 'response_mode'), 'migration deve criar modo de resposta');
$assert(str_contains($migration, 'tenant_last_seen_at'), 'migration deve controlar novas respostas nao lidas');
$assert(str_contains($service, 'tenantReply'), 'servico deve aceitar resposta do cliente');
$assert(str_contains($service, 'adminReply'), 'servico deve aceitar resposta da RS');
$assert(str_contains($service, 'acknowledge'), 'servico deve aceitar confirmacao de leitura');
$assert(str_contains($service, 'notifyAdminsOfReply'), 'resposta do cliente deve alertar Super Admin');
$assert(str_contains($service, "direction = \"rs_to_tenant\""), 'inbox deve detectar nova resposta da RS como nao lida');
$assert(str_contains($controller, 'public function inbox'), 'controller deve expor inbox autenticado');
$assert(str_contains($routes, '/communications/inbox'), 'rota do inbox deve existir');
$assert(str_contains($routes, '/communications/respond'), 'rota de resposta deve existir');
$assert(str_contains($layout, 'data-rs-communication-hub'), 'layout do cliente deve conter hub global');
$assert(str_contains($layout, 'data-communication-float'), 'caixa flutuante deve existir');
$assert(str_contains($layout, 'data-communication-drawer'), 'drawer deve existir');
$assert(str_contains($js, "notification.type === 'communication'"), 'toast generico nao deve duplicar comunicado');
$assert(str_contains($js, "sessionStorage.setItem(minimizedKey"), 'minimizar nao deve marcar leitura');
$assert(str_contains($js, 'apiPost(readUrl'), 'abrir mensagem deve marcar leitura explicitamente');
$assert(str_contains($view, 'Pré-visualização'), 'admin deve ter preview');
$assert(str_contains($view, 'Resposta do cliente'), 'admin deve definir modo de resposta');
$assert(str_contains($view, 'Exibir até'), 'admin deve definir validade opcional');
$assert(!preg_match('/[\x{1F300}-\x{1FAFF}]/u', $view . $layout), 'novas telas nao devem usar emojis');

if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK - Central de comunicação in-app, leitura, confirmação, respostas e UI sem emojis validadas.\n";
