<?php
/** Validação estática da primeira etapa de redesign visual (sem banco de dados). */
declare(strict_types=1);
$root = dirname(__DIR__, 2);
$checks = [];
$read = static fn (string $file) => (string) file_get_contents($root . '/' . $file);
$app = $read('app/Views/layouts/app.php');
$guest = $read('app/Views/layouts/guest.php');
$login = $read('app/Views/auth/login.php');
$css = $read('public/assets/css/rs-connect-next.css');
$conversation = $read('app/Views/conversations/index.php');
$checks['App carrega CSS Next apenas sem logo personalizada'] = str_contains($app, "if (!\$brandEnabled): ?><link rel=\"stylesheet\"") && str_contains($app, 'rs-connect-next.css?v=20261022-1');
$checks['Guest protege a identidade dos clientes'] = str_contains($guest, "empty(\$branding['enabled'])") && str_contains($guest, 'rs-next-login');
$checks['Login mantém CSRF e campos originais'] = str_contains($login, 'Csrf::input()') && str_contains($login, 'name="email"') && str_contains($login, 'name="password"') && str_contains($login, 'data-login-demo-open');
$checks['Layout preserva navegação e hooks JS'] = str_contains($app, 'id="sidebar"') && str_contains($app, 'id="sidebarToggle"') && str_contains($app, 'data-module-search') && str_contains($app, 'rs-next-dashboard') && str_contains($app, 'rs-next-conversations');
$checks['Conversas preservam seleção, polling, envio e detalhes'] = str_contains($conversation, 'data-conversation-realtime') && str_contains($conversation, 'data-conversation-list') && str_contains($conversation, 'data-chat-thread') && str_contains($conversation, 'data-selected-conversation-panel') && str_contains($conversation, 'data-new-conversation-form');
$checks['Estilos com escopo e responsividade'] = str_contains($css, 'body.rs-next-theme') && str_contains($css, 'body.rs-next-login') && str_contains($css, '@media (max-width: 620px)') && str_contains($css, 'prefers-reduced-motion');
$checks['Arquivos presentes'] = is_file($root . '/public/assets/img/rs-connect-next-favicon.png') && is_file($root . '/public/assets/css/rs-connect-next.css');
$checks['Nenhuma dependência nova'] = !str_contains($css, '@import') && !str_contains($css, 'url(http');
$bad = 0;
foreach ($checks as $name => $ok) { echo ($ok ? '[OK] ' : '[FALHOU] ') . $name . PHP_EOL; $bad += (int) !$ok; }
exit($bad ? 1 : 0);
