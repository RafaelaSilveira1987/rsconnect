<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;

$user = Auth::user();
$flashes = Flash::all();
$currentPath = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
$isActive = static function (string $path) use ($currentPath): string {
    if ($path === '/') {
        $appPath = rtrim(parse_url(Router::url('/'), PHP_URL_PATH) ?: '/', '/');
        return $currentPath === $appPath ? ' is-active' : '';
    }
    return str_ends_with($currentPath, rtrim($path, '/')) ? ' is-active' : '';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f9fc">
    <title><?= View::e($title ?? 'RS Connect') ?> — RS Connect</title>
    <link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/app.css?v=11.0')) ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= View::e(Router::url('/')) ?>">
            <span class="brand-mark">RS</span>
            <span><strong>RS Connect</strong><small>Atendimento e CRM</small></span>
        </a>

        <nav class="sidebar-nav" aria-label="Navegação principal">
            <a class="nav-link<?= $isActive('/') ?>" href="<?= View::e(Router::url('/')) ?>"><span class="nav-icon">⌂</span><span>Dashboard</span></a>

            <?php if (!Auth::isSuperAdmin() && Auth::can('onboarding.manage')): ?>
                <a class="nav-link<?= $isActive('/onboarding') ?>" href="<?= View::e(Router::url('/onboarding')) ?>"><span class="nav-icon">✓</span><span>Configuração inicial</span></a>
            <?php endif; ?>

            <?php if (Auth::can('conversations.view') || Auth::can('contacts.view') || Auth::can('crm.view') || Auth::can('tasks.view') || Auth::can('calendar.view')): ?>
                <span class="nav-caption">Relacionamento</span>
            <?php endif; ?>
            <?php if (Auth::can('conversations.view')): ?>
                <a class="nav-link<?= $isActive('/conversations') ?>" href="<?= View::e(Router::url('/conversations')) ?>"><span class="nav-icon">✉</span><span>Conversas</span></a>
            <?php endif; ?>
            <?php if (Auth::can('contacts.view')): ?>
                <a class="nav-link<?= $isActive('/contacts') ?>" href="<?= View::e(Router::url('/contacts')) ?>"><span class="nav-icon">◎</span><span>Contatos</span></a>
            <?php endif; ?>
            <?php if (Auth::can('crm.view')): ?>
                <a class="nav-link<?= $isActive('/crm') ?>" href="<?= View::e(Router::url('/crm')) ?>"><span class="nav-icon">▥</span><span>CRM</span></a>
            <?php endif; ?>
            <?php if (Auth::can('tasks.view')): ?>
                <a class="nav-link<?= $isActive('/tasks') ?>" href="<?= View::e(Router::url('/tasks')) ?>"><span class="nav-icon">◷</span><span>Tarefas</span></a>
            <?php endif; ?>
            <?php if (Auth::can('calendar.view')): ?>
                <a class="nav-link<?= $isActive('/calendar') ?>" href="<?= View::e(Router::url('/calendar')) ?>"><span class="nav-icon">☷</span><span>Agenda</span></a>
            <?php endif; ?>

            <?php if (Auth::can('instances.view') || (!Auth::isSuperAdmin() && (Auth::can('agents.view') || Auth::can('automations.view')))): ?>
                <span class="nav-caption">Automação</span>
            <?php endif; ?>
            <?php if (Auth::can('instances.view')): ?>
                <a class="nav-link<?= $isActive('/instances') ?>" href="<?= View::e(Router::url('/instances')) ?>"><span class="nav-icon">◉</span><span>Instâncias</span></a>
            <?php endif; ?>
            <?php if (Auth::isSuperAdmin()): ?>
                <a class="nav-link<?= $isActive('/ai-credentials') ?>" href="<?= View::e(Router::url('/ai-credentials')) ?>"><span class="nav-icon">🔐</span><span>Credenciais de IA</span></a>
                <a class="nav-link<?= $isActive('/n8n-flows') ?>" href="<?= View::e(Router::url('/n8n-flows')) ?>"><span class="nav-icon">↔</span><span>Fluxos n8n</span></a>
                <a class="nav-link<?= $isActive('/n8n-templates') ?>" href="<?= View::e(Router::url('/n8n-templates')) ?>"><span class="nav-icon">▣</span><span>Templates n8n</span></a>
                <a class="nav-link<?= $isActive('/billing') ?>" href="<?= View::e(Router::url('/billing')) ?>"><span class="nav-icon">$</span><span>Planos e cobrança</span></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin() && Auth::can('agents.view')): ?>
                <a class="nav-link<?= $isActive('/agents') ?>" href="<?= View::e(Router::url('/agents')) ?>"><span class="nav-icon">✦</span><span>Agentes de IA</span></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin() && Auth::can('automations.view')): ?>
                <a class="nav-link<?= $isActive('/automations') ?>" href="<?= View::e(Router::url('/automations')) ?>"><span class="nav-icon">⚙</span><span>Automações</span></a>
            <?php endif; ?>

            <span class="nav-caption"><?= Auth::isSuperAdmin() ? 'Administração RS' : 'Administração' ?></span>
            <?php if (Auth::isSuperAdmin()): ?>
                <a class="nav-link<?= $isActive('/companies') ?>" href="<?= View::e(Router::url('/companies')) ?>"><span class="nav-icon">◇</span><span>Empresas</span></a>
            <?php elseif (Auth::can('company.view')): ?>
                <a class="nav-link<?= $isActive('/company-settings') ?>" href="<?= View::e(Router::url('/company-settings')) ?>"><span class="nav-icon">◇</span><span>Minha empresa</span></a>
            <?php endif; ?>
            <?php if (Auth::can('users.view')): ?>
                <a class="nav-link<?= $isActive('/users') ?>" href="<?= View::e(Router::url('/users')) ?>"><span class="nav-icon">○</span><span>Usuários</span></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin() && Auth::can('billing.view')): ?>
                <a class="nav-link<?= $isActive('/subscription') ?>" href="<?= View::e(Router::url('/subscription')) ?>"><span class="nav-icon">$</span><span>Minha assinatura</span></a>
            <?php endif; ?>
            <?php if (Auth::can('permissions.view')): ?>
                <a class="nav-link<?= $isActive('/permissions') ?>" href="<?= View::e(Router::url('/permissions')) ?>"><span class="nav-icon">◆</span><span>Permissões</span></a>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="user-mini">
                <span class="avatar"><?= View::e(mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 1))) ?></span>
                <span><strong><?= View::e($user['name'] ?? '') ?></strong><small><?= View::e($user['tenant_name'] ?? 'Equipe RS') ?></small></span>
            </div>
            <form method="post" action="<?= View::e(Router::url('/logout')) ?>">
                <?= Csrf::input() ?>
                <button class="btn btn-quiet btn-block" type="submit">Sair da conta</button>
            </form>
        </div>
    </aside>
    <button class="sidebar-backdrop" id="sidebarBackdrop" type="button" aria-label="Fechar menu"></button>

    <main class="main-content">
        <header class="topbar">
            <button class="icon-button" id="sidebarToggle" type="button" aria-label="Abrir menu">☰</button>
            <div>
                <span class="eyebrow"><?= Auth::isSuperAdmin() ? 'Operação RS' : View::e($user['tenant_name'] ?? 'Cliente') ?></span>
                <h1><?= View::e($title ?? 'RS Connect') ?></h1>
            </div>
            <span class="status-pill"><i></i><?= View::e(ucfirst((string) \App\Core\Env::get('APP_ENV', 'local'))) ?></span>
        </header>

        <?php if ($flashes): ?>
            <section class="flash-stack" aria-live="polite">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash flash-<?= View::e($flash['type']) ?>">
                        <span><?= View::e($flash['message']) ?></span>
                        <button type="button" data-dismiss-flash aria-label="Fechar">×</button>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

        <section class="page-content"><?= $content ?></section>
    </main>
</div>
<script src="<?= View::e(Router::url('/assets/js/app.js?v=11.0')) ?>" defer></script>
</body>
</html>
