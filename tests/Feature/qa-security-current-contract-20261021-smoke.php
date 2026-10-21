<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap.php';

use App\Services\WebhookSecurityService;

$root = dirname(__DIR__, 2);
$routes = (string) file_get_contents($root . '/routes/web.php');
$mobile = (string) file_get_contents($root . '/app/Controllers/MobileApiController.php');
$env = (string) file_get_contents($root . '/.env.vps.example');
$bootstrap = (string) file_get_contents($root . '/bootstrap.php');
$errors = [];
$check = static function (bool $condition, string $message) use (&$errors): void {
    echo ($condition ? '[OK] ' : '[FALHA] '), $message, "\n";
    if (!$condition) $errors[] = $message;
};

$check(str_contains($routes, "'/login', [AuthController::class, 'login'], ['guest', 'csrf']"),
    'login de navegador permanece protegido por CSRF');
$check(str_contains($bootstrap, "RequestSecurity::isHttps()")
    && str_contains($bootstrap, "'secure' => \$secureCookie"),
    'cookie seguro no HTTPS verificado pela origem/proxy');
$check(preg_match('/^SESSION_COOKIE_SECURE=(?:auto|true)$/m', $env) === 1
    && str_contains($env, 'SECURITY_WEBHOOK_STRICT=true')
    && str_contains($env, 'SECURITY_WEBHOOK_ALLOW_INSECURE_LOCAL=false'),
    'modelo VPS exige autenticação de webhooks e cookie seguro no HTTPS');

preg_match_all('/\$router->post\(\'([^\']+)\'[^;]+\);/', $routes, $matches);
$unexpected = [];
$mobileEndpoints = [];
foreach (($matches[0] ?? []) as $index => $declaration) {
    $path = (string) ($matches[1][$index] ?? '');
    if (str_starts_with($path, '/webhooks/')) continue;
    if (str_starts_with($path, '/mobile/') || $path === '/auth/login') {
        $mobileEndpoints[] = $path;
        if (!str_contains($declaration, 'MobileApiController::class')) $unexpected[] = $path;
        continue;
    }
    if (!str_contains($declaration, "'csrf'")) $unexpected[] = $path;
}
$check($unexpected === [], 'POST de navegador exige CSRF e exceções mobile têm controller próprio');
$check(count($mobileEndpoints) >= 8, 'exceções mobile explicitamente identificadas e auditadas');

$methods = ['logout','markConversationRead','sendConversation','conversationMode', 'conversationAssignment','conversationDepartment'];
foreach ($methods as $methodName) {
    $method = new ReflectionMethod(App\Controllers\MobileApiController::class, $methodName);
    $lines = file($method->getFileName());
    $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    $check(str_contains($body, '$this->authenticate('), 'ação mobile ' . $methodName . ' exige autenticação Bearer');
}
$check(str_contains($mobile, "hash('sha256', \$token)")
    && str_contains($mobile, 'tkn.expires_at > NOW()')
    && str_contains($mobile, 'tkn.revoked_at IS NULL')
    && str_contains($mobile, '$tokenTenantId !== $userTenantId'),
    'tokens revogados/vencidos e tenant divergente são negados');

$webhook = new WebhookSecurityService();
$secret = str_repeat('K', 32);
$rejected = false;
try {
    $webhook->verifyStaticToken('qa-check', $secret, ['X-Webhook-Token' => 'invalido'], ['x-webhook-token']);
} catch (RuntimeException $exception) {
    $rejected = $exception->getCode() === 401;
}
$check($rejected, 'webhook com token incorreto é rejeitado');
$webhook->verifyStaticToken('qa-check', $secret, ['X-Webhook-Token' => $secret], ['x-webhook-token']);
$check(true, 'webhook com token válido é aceito');

if ($errors) {
    fwrite(STDERR, "FALHAS:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}
echo "OK - segurança atual: CSRF navegador, autenticação mobile e webhooks.\n";
