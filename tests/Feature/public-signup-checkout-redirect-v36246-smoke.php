<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$controller = file_get_contents($root . '/app/Controllers/PublicSignupController.php') ?: '';
$service = file_get_contents($root . '/app/Services/PublicSignupService.php') ?: '';
$routes = file_get_contents($root . '/routes/web.php') ?: '';
$view = file_get_contents($root . '/app/Views/signup/checkout.php') ?: '';
$csp = file_get_contents($root . '/app/Core/ContentSecurityPolicy.php') ?: '';
$version = file_get_contents($root . '/app/Services/AppVersionService.php') ?: '';

require_once $root . '/app/Core/Autoloader.php';
App\Core\Autoloader::register($root . '/app');
$serviceObject = new App\Services\PublicSignupService();
$urlGuard = new ReflectionMethod($serviceObject, 'isSafeAsaasCheckoutUrl');
$urlGuard->setAccessible(true);
$acceptsOfficial = $urlGuard->invoke($serviceObject, 'https://sandbox.asaas.com/checkoutSession/show/abc-123') === true
    && $urlGuard->invoke($serviceObject, 'https://asaas.com/checkoutSession/show?id=abc-123') === true;
$rejectsForeign = $urlGuard->invoke($serviceObject, 'https://asaas.com.evil.example/checkoutSession/show?id=abc') === false
    && $urlGuard->invoke($serviceObject, 'http://asaas.com/checkoutSession/show?id=abc') === false
    && $urlGuard->invoke($serviceObject, 'https://asaas.com/outro-caminho') === false;

$checks = [
    'POST usa redirecionamento interno 303' => str_contains($controller, "Router::url('/signup/checkout?token='")
        && str_contains($controller, 'true,')
        && str_contains($controller, '303'),
    'POST não redireciona mais diretamente ao domínio externo' => !str_contains($controller, "header('Location: ' . \$result['checkout_url'])"),
    'rota interna da ponte registrada' => str_contains($routes, "\$router->get('/signup/checkout'"),
    'ponte valida o link no banco' => str_contains($service, 'public function checkoutBridge')
        && str_contains($service, 'isSafeAsaasCheckoutUrl'),
    'allowlist exige HTTPS e domínio Asaas' => str_contains($service, "strtolower((string) (\$parts['scheme'] ?? '')) !== 'https'")
        && str_contains($service, "str_ends_with(\$host, '.asaas.com')")
        && str_contains($service, "str_starts_with(\$path, '/checkoutSession/show')")
        && $acceptsOfficial && $rejectsForeign,
    'página possui redirecionamento e fallback manual' => str_contains($view, 'window.location.replace(target)')
        && str_contains($view, 'Continuar para o Asaas'),
    'CSP estrita permanece preservada' => str_contains($csp, "form-action 'self'"),
    'versão atualizada' => str_contains($service, "public const VERSION = '36.24.6';")
        && str_contains($version, 'RS Connect 36.24.6'),
];

$failed = [];
foreach ($checks as $label => $ok) {
    if (!$ok) {
        $failed[] = $label;
    }
}
if ($failed !== []) {
    fwrite(STDERR, 'Falhas: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "OK - ponte segura do Checkout Asaas v36.24.6 validada.\n";
