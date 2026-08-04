<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$bootstrap = $read('bootstrap.php');
$requestSecurity = $read('app/Core/RequestSecurity.php');
$csrf = $read('app/Core/Csrf.php');
$auth = $read('app/Core/Auth.php');
$security = $read('app/Services/SecurityService.php');
$router = $read('app/Core/Router.php');
$authController = $read('app/Controllers/AuthController.php');
$evolution = $read('app/Controllers/EvolutionWebhookController.php');
$n8n = $read('app/Controllers/N8nTemplateController.php');
$payments = $read('app/Services/PaymentGatewayService.php');
$routes = $read('routes/web.php');
$migration = $read('database/migrations/072_security_session_webhook_hardening.sql');
$diagnostic = $read('database/diagnostics/security_hardening_v36.11.1.sql');
$version = $read('app/Services/AppVersionService.php');
$layout = $read('app/Views/layouts/app.php');
$envVps = $read('.env.vps.example');

$checks = [
    'sessão PHP usa modo estrito' => str_contains($bootstrap, "ini_set('session.use_strict_mode', '1')"),
    'sessão aceita somente cookie' => str_contains($bootstrap, "ini_set('session.use_only_cookies', '1')")
        && str_contains($bootstrap, "ini_set('session.use_trans_sid', '0')"),
    'cookie detecta HTTPS atrás do proxy' => str_contains($bootstrap, 'RequestSecurity::isHttps()')
        && str_contains($requestSecurity, 'HTTP_X_FORWARDED_PROTO')
        && str_contains($requestSecurity, 'isTrustedProxy'),
    'IP encaminhado só é aceito de proxy confiável' => str_contains($requestSecurity, 'TRUSTED_PROXIES')
        && str_contains($requestSecurity, 'HTTP_X_FORWARDED_FOR')
        && str_contains($security, 'RequestSecurity::clientIp()'),
    'headers adicionais e CSP estão ativos' => str_contains($bootstrap, 'Content-Security-Policy')
        && str_contains($bootstrap, 'Cross-Origin-Opener-Policy')
        && str_contains($bootstrap, 'X-Permitted-Cross-Domain-Policies'),
    'CSRF aceita campo e header customizado' => str_contains($csrf, "HTTP_X_CSRF_TOKEN")
        && str_contains($csrf, "HTTP_X_XSRF_TOKEN")
        && str_contains($csrf, 'validateRequest'),
    'CSRF possui renovação e validade' => str_contains($csrf, 'SECURITY_CSRF_TTL_MINUTES')
        && str_contains($csrf, 'public static function regenerate')
        && str_contains($csrf, 'originAllowed'),
    'Router usa validação CSRF completa' => str_contains($router, "!Csrf::validateRequest()"),
    'login reduz enumeração por tempo' => str_contains($auth, 'DUMMY_PASSWORD_HASH')
        && str_contains($auth, 'password_verify($password, self::DUMMY_PASSWORD_HASH)'),
    'login possui limite global por IP' => str_contains($security, 'tooManyFailedLoginAttemptsFromIp')
        && str_contains($authController, 'auth.login_blocked_ip_rate_limit'),
    'mensagem pública do login é genérica' => str_contains($authController, 'Não foi possível entrar. Confira os dados ou aguarde alguns minutos'),
    'usuário ou tenant desativado encerra a sessão' => str_contains($auth, 'tenant_status')
        && str_contains($security, 'auth.session_principal_disabled'),
    'sessão possui limite ocioso, absoluto e rotação' => str_contains($security, 'SECURITY_SESSION_IDLE_MINUTES')
        && str_contains($security, 'SECURITY_SESSION_ABSOLUTE_MINUTES')
        && str_contains($security, 'SECURITY_SESSION_ROTATE_MINUTES')
        && str_contains($security, 'auth.session_rotated'),
    'sessão pode ser vinculada ao navegador' => str_contains($security, 'SECURITY_SESSION_BIND_USER_AGENT')
        && str_contains($security, 'auth.session_user_agent_mismatch'),
    'webhooks possuem limite de payload' => str_contains($security, 'SECURITY_WEBHOOK_MAX_BYTES')
        && str_contains($security, 'webhook.payload_too_large')
        && str_contains($router, 'guardWebhookRequest'),
    'webhooks possuem rate limit com 429' => str_contains($security, 'SECURITY_WEBHOOK_RATE_LIMIT_PER_MINUTE')
        && str_contains($security, 'security_rate_limits')
        && str_contains($router, "header('Retry-After: '")
        && str_contains($security, "'status' => 429"),
    'Evolution usa verificação central de token' => str_contains($evolution, "verifyWebhookToken('evolution'")
        && str_contains($evolution, 'RequestSecurity::bearerToken()'),
    'callback n8n usa verificação central de token' => str_contains($n8n, "verifyWebhookToken('n8n.callback'")
        && str_contains($n8n, 'RequestSecurity::bearerToken()'),
    'gateways exigem segredo no modo estrito' => str_contains($payments, "Env::get('SECURITY_WEBHOOK_STRICT'")
        && str_contains($payments, 'return !$strict;'),
    'migration cria buckets de rate limit' => str_contains($migration, 'CREATE TABLE IF NOT EXISTS `security_rate_limits`')
        && str_contains($migration, 'PRIMARY KEY (`bucket_key`)'),
    'diagnóstico cobre novos eventos' => str_contains($diagnostic, 'webhook.rate_limited')
        && str_contains($diagnostic, 'auth.session_absolute_expired'),
    'produção usa cookie seguro e ativação progressiva do modo estrito' => str_contains($envVps, 'SESSION_COOKIE_SECURE=true')
        && str_contains($envVps, 'SECURITY_WEBHOOK_STRICT=false')
        && str_contains($envVps, 'Após confirmar os tokens'),
    'versão e migration foram atualizadas' => str_contains($version, 'RS Connect 36.14.0')
        && str_contains($version, '074_conversation_message_attachments.sql'),
    'cache de assets foi renovado' => str_contains($layout, 'app.css?v=36.14.0')
        && str_contains($layout, 'app.js?v=36.14.0'),
];

preg_match_all('/\$router->post\(\'([^\']+)\'[^;]+\);/', $routes, $postMatches);
$missingCsrf = [];
foreach ($postMatches[0] ?? [] as $index => $routeLine) {
    $path = (string) ($postMatches[1][$index] ?? '');
    if (!str_starts_with($path, '/webhooks/') && !str_contains($routeLine, "'csrf'")) {
        $missingCsrf[] = $path;
    }
}
$checks['todo POST autenticado continua com CSRF'] = $missingCsrf === [];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $failures) . "\n");
    if ($missingCsrf !== []) {
        fwrite(STDERR, 'POST sem CSRF: ' . implode(', ', $missingCsrf) . "\n");
    }
    exit(1);
}

echo "OK - sessão, CSRF, login e webhooks reforçados na v36.11.1.\n";
