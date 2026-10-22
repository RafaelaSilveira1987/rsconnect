<?php
/** Smoke de regressão visual V2 — estático, executável sem banco. */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);
$app = $read('app/Views/layouts/app.php');
$guest = $read('app/Views/layouts/guest.php');
$admin = $read('app/Views/dashboard/admin.php');
$conv = $read('app/Views/conversations/index.php');
$login = $read('app/Views/auth/login.php');
$css = $read('public/assets/css/rs-connect-next-v2.css');
$checks = [
    'CSS v2 só na identidade padrão (layout)' => str_contains($app, "if (!\$brandEnabled): ?><link") && str_contains($app, 'rs-connect-next-v2.css?v=20261022-2'),
    'CSS v2 só na identidade padrão (login)' => str_contains($guest, "if (empty(\$branding['enabled']))") && str_contains($guest, 'rs-connect-next-v2.css?v=20261022-2'),
    'CSS base mantido' => str_contains($app, '/assets/css/app.css') && str_contains($guest, '/assets/css/app.css'),
    'Estilos v2 com escopo padrão' => str_contains($css, 'body.rs-next-theme') && str_contains($css, 'body.rs-next-login') && !str_contains($css, '@import'),
    'Dashboard: atualização fora do fluxo dos botões' => str_contains($css, 'grid-template-areas: "copy actions" "updated updated"') && str_contains($admin, 'class="admin-dashboard-updated"'),
    'Dashboard: rotas e ações originais' => str_contains($admin, "Router::url('/companies')") && str_contains($admin, "Router::url('/operations')") && str_contains($admin, 'data-toggle-panel="admin-company-create-drawer"'),
    'Conversas: filtros, polling e conversa selecionada' => str_contains($conv, 'name="queue"') && str_contains($conv, 'name="tenant_id"') && str_contains($conv, 'data-conversation-realtime') && str_contains($conv, 'data-chat-thread'),
    'Conversas: quebra de layout responsiva' => str_contains($css, 'min-width:155px!important') && str_contains($css, 'grid-template-columns:1fr!important'),
    'Sidebar: rolável e eventos intactos' => str_contains($css, 'scrollbar-width:thin') && str_contains($app, 'id="sidebarToggle"') && str_contains($app, 'id="sidebarBackdrop"'),
    'Login: segurança e interações preservadas' => str_contains($login, 'Csrf::input()') && str_contains($login, 'name="email"') && str_contains($login, 'name="password"') && str_contains($login, 'data-login-demo-open'),
];
$failed = 0;
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[FALHOU] ') . $label . PHP_EOL;
    $failed += (int) !$ok;
}
exit($failed ? 1 : 0);
