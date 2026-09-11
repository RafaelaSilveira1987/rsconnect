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

$view = $read('app/Views/conversations/index.php');
$css = $read('public/assets/css/app.css');
$layout = $read('app/Views/layouts/app.php');
$guest = $read('app/Views/layouts/guest.php');
$restricted = $read('app/Views/layouts/restricted.php');
$version = $read('app/Services/AppVersionService.php');
$manifest = $read('manifest.json');

$check(str_contains($view, '>Dados da conversa</button>') && !str_contains($view, '>Dados do lead</button>'), 'Ação usa nomenclatura neutra para lead, cliente e paciente.');
$check(str_contains($view, 'conversation-data-header') && str_contains($view, 'conversation-drawer-person') && str_contains($view, 'data-contact-avatar'), 'Cabeçalho do drawer exibe identidade e preserva atualização do avatar.');
$check(str_contains($view, 'conversation-overview-panel') && str_contains($view, 'conversation-overview-grid'), 'Visão rápida do atendimento foi adicionada.');
$check(str_contains($view, 'Situação') && str_contains($view, 'Atendimento') && str_contains($view, 'Responsável') && str_contains($view, 'Assistente'), 'Resumo operacional contém os quatro estados principais.');
$check(str_contains($view, 'conversation-relationship-summary') && str_contains($view, '$drawerContactStatusLabel') && str_contains($view, '$drawerGroupLabel'), 'Relacionamento do contato é destacado no resumo.');
$check(str_contains($view, 'conversation-drawer-nav') && str_contains($view, '#conversation-internal-notes') && str_contains($view, '#conversation-contact-data') && str_contains($view, '#conversation-crm'), 'Navegação interna cobre as principais seções.');
$check(str_contains($view, 'conversation-rule-grid') && str_contains($view, 'conversation-rule-item'), 'Validação efetiva usa cartões de leitura em vez de campos genéricos.');
$check(str_contains($view, "'conversation' => 'Conversa geral'") && str_contains($view, "'schedule' => 'Agendamento'"), 'Intenções técnicas conhecidas são apresentadas em linguagem amigável.');
$check(str_contains($view, 'conversation-rule-tags') && str_contains($view, 'conversation-rule-context'), 'Tags e contexto da IA receberam apresentação dedicada.');

// Functional contracts stay intact.
$check(str_contains($view, "Router::url('/conversations/contact')") && str_contains($view, 'name="contact_status"') && str_contains($view, 'name="contact_group"'), 'Formulário de classificação do contato foi preservado.');
$check(str_contains($view, "Router::url('/conversations/internal-notes')") && str_contains($view, 'name="note"'), 'Notas internas continuam com endpoint e campo originais.');
$check(str_contains($view, "Router::url('/conversations/agent')") && str_contains($view, 'name="agent_id"'), 'Roteamento manual do assistente foi preservado.');
$check(str_contains($view, "Router::url('/conversations/status')") && str_contains($view, 'name="status"'), 'Controle de status da conversa foi preservado.');

$check(str_contains($css, 'RS Connect 36.30.9 — Dados da conversa') && str_contains($css, '.conversation-overview-grid') && str_contains($css, '.conversation-rule-grid'), 'CSS compartilhado contém a nova hierarquia visual.');
$check(str_contains($css, '@media (max-width: 680px)') && str_contains($css, '.conversation-overview-grid { grid-template-columns: repeat(2'), 'Resumo mantém adaptação compacta no mobile.');
$check(str_contains($layout, 'app.css?v=36.30.9') && str_contains($layout, 'app.js?v=36.30.9'), 'Layout autenticado invalida cache para 36.30.9.');
$check(str_contains($guest, 'app.css?v=36.30.9') && str_contains($restricted, 'app.css?v=36.30.9'), 'Layouts guest e restricted invalidam cache CSS.');
$check(str_contains($version, "PACKAGE_LABEL = 'RS Connect 36.30.9 — Dados da conversa aprimorados'"), 'Versão 36.30.9 registrada.');
$check(str_contains($version, "REQUIRED_MIGRATION = '110_conversation_lifecycle_e2e_consistency.sql'"), 'Nenhuma migration nova foi introduzida.');
$check(str_contains($manifest, '"package_version": "36.30.9"') && str_contains($manifest, '"required_migration": "110_conversation_lifecycle_e2e_consistency.sql"'), 'Manifesto do pacote está coerente.');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} falha(s); {$passes} verificação(ões) aprovada(s).\n");
    exit(1);
}

echo "\nResumo UI Dados da conversa 36.30.9: {$passes} verificações aprovadas.\n";
