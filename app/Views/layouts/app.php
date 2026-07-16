<?php

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Router;
use App\Core\View;
use App\Services\NotificationService;
use App\Services\TenantModuleService;

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
$isAnyActive = static function (array $paths) use ($isActive): string {
    foreach ($paths as $path) {
        if ($isActive((string) $path) !== '') {
            return ' is-active';
        }
    }

    return '';
};

$notificationUnread = 0;
if (!Auth::isSuperAdmin() && Auth::tenantId()) {
    $notificationUnread = (new NotificationService())->unreadCount((int) Auth::tenantId());
}
$notificationBadge = static fn (int $count): string => $count > 0 ? '<span class="nav-badge">' . min(99, $count) . '</span>' : '';
$notificationLiveBadge = static fn (int $count): string => '<span class="nav-badge" data-notification-badge' . ($count > 0 ? '' : ' hidden') . '>' . ($count > 0 ? min(99, $count) : 0) . '</span>';

$moduleService = new TenantModuleService();
$moduleVisible = static function (string $moduleKey) use ($moduleService): bool {
    if (Auth::isSuperAdmin()) {
        return true;
    }
    $tenantId = Auth::tenantId();
    return $tenantId ? $moduleService->visible((int) $tenantId, $moduleKey) : true;
};
$conversationUnread = 0;
if (Auth::check() && Auth::can('conversations.view')) {
    try {
        if (Auth::isSuperAdmin()) {
            $activeTenantId = (int) ($_GET['tenant_id'] ?? 0);
            if ($activeTenantId > 0) {
                $statement = Database::connection()->prepare('SELECT COALESCE(SUM(unread_count), 0) FROM conversations WHERE tenant_id = :tenant_id');
                $statement->execute(['tenant_id' => $activeTenantId]);
                $conversationUnread = (int) $statement->fetchColumn();
            } else {
                $conversationUnread = 0;
            }
        } elseif (Auth::tenantId()) {
            $statement = Database::connection()->prepare('SELECT COALESCE(SUM(unread_count), 0) FROM conversations WHERE tenant_id = :tenant_id');
            $statement->execute(['tenant_id' => Auth::tenantId()]);
            $conversationUnread = (int) $statement->fetchColumn();
        }
    } catch (Throwable) {
        $conversationUnread = 0;
    }
}
$svgIcon = static function (string $name): string {
    $icons = [
        'dashboard' => '<path d="M4 11.5 12 5l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1v-8.5Z"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'chat' => '<path d="M5 6h14v9H8l-3 3V6Z"/>',
        'contacts' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/>',
        'crm' => '<path d="M4 6h16M4 12h16M4 18h16"/><path d="M8 6v12M16 6v12"/>',
        'tasks' => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="m4 6 1 1 2-2M4 12l1 1 2-2M4 18l1 1 2-2"/>',
        'reports' => '<path d="M4 19V5"/><path d="M8 17V9"/><path d="M12 17V7"/><path d="M16 17v-5"/><path d="M20 19H4"/>',
        'calendar' => '<path d="M7 3v4M17 3v4M4 9h16M5 5h14v16H5z"/>',
        'instance' => '<rect x="5" y="5" width="14" height="14" rx="3"/><path d="M9 9h6v6H9z"/>',
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        'flow' => '<path d="M6 7h4v4H6zM14 13h4v4h-4zM10 9h2a2 2 0 0 1 2 2v2"/>',
        'template' => '<path d="M4 5h16v14H4z"/><path d="M8 9h8M8 13h5"/>',
        'billing' => '<path d="M12 2v20M17 7.5c0-2-2-3-5-3s-5 1-5 3 2 3 5 3 5 1 5 3-2 3-5 3-5-1-5-3"/>',
        'card' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'agent' => '<path d="M12 3l2.4 5 5.6.8-4 3.9.9 5.5L12 15.6 7.1 18.2l.9-5.5-4-3.9 5.6-.8L12 3Z"/>',
        'automation' => '<path d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/>',
        'company' => '<path d="M4 21V5h10v16M14 9h6v12M8 9h2M8 13h2M8 17h2M17 13h1M17 17h1"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-8 0v2"/><circle cx="12" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
        'permissions' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'security' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
        'implementation' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'operations' => '<path d="M4 19h16"/><path d="M6 16l3-4 3 2 4-7 2 4"/><circle cx="6" cy="16" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="12" cy="14" r="1"/><circle cx="16" cy="7" r="1"/>',
        'privacy' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="M9 12h6M12 9v6"/>',
        'help' => '<circle cx="12" cy="12" r="10"/><path d="M9.1 9a3 3 0 1 1 5.8 1c-.7 1-1.9 1.4-2.4 2.5"/><path d="M12 17h.01"/>',
        'rocket' => '<path d="M5 14c-1 1-2 4-2 4s3-1 4-2"/><path d="M14 5l5-2-2 5-8 8-3-3 8-8Z"/><path d="M15 9l-4-4"/>',
        'status' => '<path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h7"/><path d="M17 14l2 2 4-5"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    ];
    $path = $icons[$name] ?? $icons['dashboard'];
    return '<svg class="nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $path . '</svg>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f9fc">
    <title><?= View::e($title ?? 'RS Connect') ?> — RS Connect</title>
    <link rel="stylesheet" href="<?= View::e(Router::url('/assets/css/app.css?v=32.2')) ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar" id="sidebar">
        <a class="brand" href="<?= View::e(Router::url('/')) ?>">
            <span class="brand-mark">RS</span>
            <span><strong>RS Connect</strong><small>Atendimento e CRM</small></span>
        </a>

        <nav class="sidebar-nav" aria-label="Navegação principal">
            <a class="nav-link<?= $isActive('/') ?>" href="<?= View::e(Router::url('/')) ?>"><?= $svgIcon('dashboard') ?><span>Dashboard</span></a>

            <?php if (!Auth::isSuperAdmin() && Auth::can('onboarding.manage')): ?>
                <a class="nav-link<?= $isActive('/onboarding') ?>" href="<?= View::e(Router::url('/onboarding')) ?>"><?= $svgIcon('check') ?><span>Primeiros passos</span></a>
            <?php endif; ?>

            <?php if ((Auth::can('conversations.view') && $moduleVisible('conversations')) || (Auth::can('contacts.view') && $moduleVisible('contacts')) || (Auth::can('crm.view') && $moduleVisible('crm')) || (Auth::can('tasks.view') && $moduleVisible('tasks')) || (Auth::can('calendar.view') && $moduleVisible('calendar'))): ?>
                <span class="nav-caption">Relacionamento</span>
            <?php endif; ?>
            <?php if (Auth::can('conversations.view') && $moduleVisible('conversations')): ?>
                <a class="nav-link<?= $isActive('/conversations') ?>" href="<?= View::e(Router::url('/conversations')) ?>"><?= $svgIcon('chat') ?><span>Conversas</span><?= $notificationBadge($conversationUnread) ?></a>
            <?php endif; ?>
            <?php if (Auth::can('contacts.view') && $moduleVisible('contacts')): ?>
                <a class="nav-link<?= $isActive('/contacts') ?>" href="<?= View::e(Router::url('/contacts')) ?>"><?= $svgIcon('contacts') ?><span>Contatos</span></a>
            <?php endif; ?>
            <?php if (Auth::can('crm.view') && $moduleVisible('crm')): ?>
                <a class="nav-link<?= $isActive('/crm') ?>" href="<?= View::e(Router::url('/crm')) ?>"><?= $svgIcon('crm') ?><span>CRM</span></a>
            <?php endif; ?>
            <?php if (Auth::can('tasks.view') && $moduleVisible('tasks')): ?>
                <a class="nav-link<?= $isActive('/tasks') ?>" href="<?= View::e(Router::url('/tasks')) ?>"><?= $svgIcon('tasks') ?><span>Atividades</span></a>
            <?php endif; ?>
            <?php if (Auth::can('calendar.view') && $moduleVisible('calendar')): ?>
                <a class="nav-link<?= $isAnyActive(['/calendar', '/calendar/availability', '/agenda-inteligente', '/agenda-disponibilidade']) ?>" href="<?= View::e(Router::url('/calendar')) ?>"><?= $svgIcon('calendar') ?><span>Agenda</span></a>
            <?php endif; ?>
            <?php if (Auth::can('reports.view') && $moduleVisible('reports')): ?>
                <a class="nav-link<?= $isActive('/reports') ?>" href="<?= View::e(Router::url('/reports')) ?>"><?= $svgIcon('reports') ?><span>Relatórios</span></a>
            <?php endif; ?>

            <?php if ((Auth::can('instances.view') && $moduleVisible('instances')) || (!Auth::isSuperAdmin() && ((Auth::can('agents.view') && $moduleVisible('agents')) || (Auth::can('automations.view') && $moduleVisible('automations'))))): ?>
                <span class="nav-caption">Automação</span>
            <?php endif; ?>
            <?php if (Auth::can('instances.view') && $moduleVisible('instances')): ?>
                <a class="nav-link<?= $isActive('/instances') ?>" href="<?= View::e(Router::url('/instances')) ?>"><?= $svgIcon('instance') ?><span>WhatsApp</span></a>
            <?php endif; ?>
            <?php if (Auth::isSuperAdmin()): ?>
                <a class="nav-link<?= $isActive('/ai-credentials') ?>" href="<?= View::e(Router::url('/ai-credentials')) ?>"><?= $svgIcon('lock') ?><span>Credenciais de IA</span></a>
                <a class="nav-link<?= $isActive('/n8n-flows') ?>" href="<?= View::e(Router::url('/n8n-flows')) ?>"><?= $svgIcon('flow') ?><span>Fluxos n8n</span></a>
                <a class="nav-link<?= $isActive('/n8n-templates') ?>" href="<?= View::e(Router::url('/n8n-templates')) ?>"><?= $svgIcon('template') ?><span>Templates n8n</span></a>
                <a class="nav-link<?= $isActive('/billing') ?>" href="<?= View::e(Router::url('/billing')) ?>"><?= $svgIcon('billing') ?><span>Planos e cobrança</span></a>
                <a class="nav-link<?= $isActive('/payment-gateways') ?>" href="<?= View::e(Router::url('/payment-gateways')) ?>"><?= $svgIcon('card') ?><span>Gateways de pagamento</span></a>
                <a class="nav-link<?= $isActive('/billing-reminders') ?>" href="<?= View::e(Router::url('/billing-reminders')) ?>"><?= $svgIcon('bell') ?><span>Régua de cobrança</span></a>
                <a class="nav-link<?= $isActive('/implementation') ?>" href="<?= View::e(Router::url('/implementation')) ?>"><?= $svgIcon('implementation') ?><span>Implantação</span></a>
                <a class="nav-link<?= $isActive('/security') ?>" href="<?= View::e(Router::url('/security')) ?>"><?= $svgIcon('security') ?><span>Segurança</span></a>
                <a class="nav-link<?= $isActive('/operations') ?>" href="<?= View::e(Router::url('/operations')) ?>"><?= $svgIcon('operations') ?><span>Monitoramento</span></a>
                <a class="nav-link<?= $isAnyActive(['/backup-automatico', '/operations/backups/automation']) ?>" href="<?= View::e(Router::url('/backup-automatico')) ?>"><?= $svgIcon('operations') ?><span>Backup automático</span></a>
                <a class="nav-link<?= $isActive('/beta-comercial') ?>" href="<?= View::e(Router::url('/beta-comercial')) ?>"><?= $svgIcon('rocket') ?><span>Beta 1.0</span></a>
                <a class="nav-link<?= $isActive('/status-sistema') ?>" href="<?= View::e(Router::url('/status-sistema')) ?>"><?= $svgIcon('status') ?><span>Status do sistema</span></a>
                <a class="nav-link<?= $isActive('/ajuda') ?>" href="<?= View::e(Router::url('/ajuda')) ?>"><?= $svgIcon('help') ?><span>Central de ajuda</span></a>
                <a class="nav-link<?= $isActive('/privacy') ?>" href="<?= View::e(Router::url('/privacy')) ?>"><?= $svgIcon('privacy') ?><span>Privacidade/LGPD</span></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin() && Auth::can('agents.view') && $moduleVisible('agents')): ?>
                <a class="nav-link<?= $isActive('/agents') ?>" href="<?= View::e(Router::url('/agents')) ?>"><?= $svgIcon('agent') ?><span>Assistentes de IA</span></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin() && Auth::can('automations.view') && $moduleVisible('automations')): ?>
                <a class="nav-link<?= $isActive('/automations') ?>" href="<?= View::e(Router::url('/automations')) ?>"><?= $svgIcon('automation') ?><span>Automações</span></a>
            <?php endif; ?>

            <span class="nav-caption"><?= Auth::isSuperAdmin() ? 'Administração RS' : 'Administração' ?></span>
            <?php if (Auth::isSuperAdmin()): ?>
                <a class="nav-link<?= $isAnyActive(['/companies', '/companies/overview', '/company-settings']) ?>" href="<?= View::e(Router::url('/companies')) ?>"><?= $svgIcon('company') ?><span>Empresas</span></a>
            <?php elseif (Auth::can('company.view') && $moduleVisible('company_settings')): ?>
                <a class="nav-link<?= $isActive('/company-settings') ?>" href="<?= View::e(Router::url('/company-settings')) ?>"><?= $svgIcon('company') ?><span>Minha empresa</span></a>
            <?php endif; ?>
            <?php if (Auth::can('users.view') && $moduleVisible('users')): ?>
                <a class="nav-link<?= $isActive('/users') ?>" href="<?= View::e(Router::url('/users')) ?>"><?= $svgIcon('users') ?><span>Usuários</span></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin() && Auth::can('notifications.view') && $moduleVisible('notifications')): ?>
                <a class="nav-link<?= $isActive('/notifications') ?>" href="<?= View::e(Router::url('/notifications')) ?>" data-notification-link data-count-url="<?= View::e(Router::url('/notifications/count')) ?>"><?= $svgIcon('bell') ?><span>Notificações</span><?= $notificationLiveBadge($notificationUnread) ?></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin()): ?>
                <a class="nav-link<?= $isActive('/ajuda') ?>" href="<?= View::e(Router::url('/ajuda')) ?>"><?= $svgIcon('help') ?><span>Central de ajuda</span></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin() && Auth::can('privacy.view') && $moduleVisible('privacy')): ?>
                <a class="nav-link<?= $isActive('/privacy') ?>" href="<?= View::e(Router::url('/privacy')) ?>"><?= $svgIcon('privacy') ?><span>Privacidade e dados</span></a>
            <?php endif; ?>
            <?php if (!Auth::isSuperAdmin() && Auth::can('billing.view') && $moduleVisible('subscription')): ?>
                <a class="nav-link<?= $isActive('/subscription') ?>" href="<?= View::e(Router::url('/subscription')) ?>"><?= $svgIcon('billing') ?><span>Minha assinatura</span></a>
            <?php endif; ?>
            <?php if (Auth::can('permissions.view') && $moduleVisible('permissions')): ?>
                <a class="nav-link<?= $isActive('/permissions') ?>" href="<?= View::e(Router::url('/permissions')) ?>"><?= $svgIcon('permissions') ?><span><?= Auth::isSuperAdmin() ? 'Permissões' : 'Acessos da equipe' ?></span></a>
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
            <button class="icon-button" id="sidebarToggle" type="button" aria-label="Abrir menu"><?= $svgIcon('menu') ?></button>
            <div>
                <span class="eyebrow"><?= Auth::isSuperAdmin() ? 'Operação RS' : View::e($user['tenant_name'] ?? 'Cliente') ?></span>
                <h1><?= View::e($title ?? 'RS Connect') ?></h1>
            </div>
            <?php if (!Auth::isSuperAdmin() && Auth::can('notifications.view') && $moduleVisible('notifications')): ?>
                <a class="topbar-notification" href="<?= View::e(Router::url('/notifications')) ?>" aria-label="Notificações" data-notification-link data-count-url="<?= View::e(Router::url('/notifications/count')) ?>">
                    <?= $svgIcon('bell') ?>
                    <?= $notificationLiveBadge($notificationUnread) ?>
                </a>
            <?php endif; ?>
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
<script src="<?= View::e(Router::url('/assets/js/app.js?v=32.2')) ?>" defer></script>
</body>
</html>
