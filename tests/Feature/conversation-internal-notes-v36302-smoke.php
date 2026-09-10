<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => is_file($root . '/' . $path) ? (string) file_get_contents($root . '/' . $path) : '';
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$routes = $read('routes/web.php');
$controller = $read('app/Controllers/ConversationController.php');
$view = $read('app/Views/conversations/index.php');
$css = $read('public/assets/css/app.css');
$aiModel = $read('app/Services/AiModelService.php');
$migration = $read('database/migrations/020_queue_team_distribution.sql');
$version = $read('app/Services/AppVersionService.php');
$layout = $read('app/Views/layouts/app.php');

$check(str_contains($migration, 'CREATE TABLE IF NOT EXISTS conversation_internal_notes'), 'Estrutura histórica conversation_internal_notes não existe.');
$check(str_contains($routes, "'/conversations/internal-notes'") && str_contains($routes, 'addInternalNote'), 'Endpoint protegido para nota interna não foi registrado.');
$check(str_contains($controller, 'public function addInternalNote') && str_contains($controller, 'INSERT INTO conversation_internal_notes'), 'Controller não grava notas internas.');
$check(str_contains($controller, "'internal_note.added'") && str_contains($controller, 'Nota interna registrada pela equipe.'), 'Evento de auditoria não evita conteúdo privado.');
$check(str_contains($controller, 'internalNotesStatement') && str_contains($controller, 'u.name AS user_name'), 'Histórico não carrega autor das notas.');
$check(str_contains($view, 'Notas internas da conversa') && str_contains($view, 'Não é enviado ao WhatsApp e não entra no contexto da IA'), 'Frontend não diferencia nota privada de contexto da IA.');
$check(str_contains($view, 'Contexto do contato') && str_contains($view, 'Pode ser utilizada pela IA'), 'Campo legado do contato não foi renomeado para evitar ambiguidade.');
$check(str_contains($view, 'conversation-internal-note-list') && str_contains($view, "['created_at']"), 'Histórico visual com data não foi implementado.');
$check(str_contains($css, 'RS Connect 36.30.2 — notas internas privadas da conversa'), 'CSS dedicado das notas internas não foi adicionado.');
$check(!str_contains($aiModel, 'conversation_internal_notes'), 'AiModelService passou a consultar notas internas privadas.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.30.2 — Notas internas da conversa'"), 'Versão 36.30.2 não foi registrada.');
$check(str_contains($version, "REQUIRED_MIGRATION = '107_service_department_memberships.sql'"), 'Migration obrigatória foi alterada indevidamente.');
$check(str_contains($layout, 'app.css?v=36.30.2') && str_contains($layout, 'app.js?v=36.30.2'), 'Cache dos assets não foi atualizado.');

if ($failures !== []) {
    fwrite(STDERR, "FAIL conversation-internal-notes-v36302-smoke\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "OK conversation-internal-notes-v36302-smoke\n";
