<?php

declare(strict_types=1);

use App\Core\ContentSecurityPolicy;
use App\Core\Env;
use App\Core\RequestSecurity;

$composerAutoload = __DIR__ . '/vendor/autoload.php';
$projectAutoloader = __DIR__ . '/app/Core/Autoloader.php';

if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (!class_exists(App\Core\Autoloader::class, false)) {
    require_once $projectAutoloader;
}

App\Core\Autoloader::register(__DIR__ . '/app');

Env::load(__DIR__ . '/.env');

date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

$requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
$sessionlessHealthRequest = in_array($requestPath, ['/health/live', '/health/ready'], true)
    || $requestPath === '/white-label/asset';

$debug = filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

if (!$sessionlessHealthRequest && session_status() !== PHP_SESSION_ACTIVE) {
    $lifetimeSeconds = max(300, (int) Env::get('SESSION_LIFETIME', 120) * 60);
    $sameSite = ucfirst(strtolower(trim((string) Env::get('SESSION_SAMESITE', 'Lax'))));
    if (!in_array($sameSite, ['Lax', 'Strict'], true)) {
        $sameSite = 'Lax';
    }

    $secureSetting = strtolower(trim((string) Env::get('SESSION_COOKIE_SECURE', 'auto')));
    $secureCookie = match ($secureSetting) {
        '1', 'true', 'yes', 'on' => true,
        '0', 'false', 'no', 'off' => false,
        default => RequestSecurity::isHttps(),
    };

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetimeSeconds);
    session_cache_limiter('nocache');

    session_name((string) Env::get('SESSION_NAME', 'rs_connect_session'));
    session_set_cookie_params([
        'lifetime' => $lifetimeSeconds,
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => $sameSite,
    ]);
    session_start();
}

if (!headers_sent() && filter_var(Env::get('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOL)) {
    header_remove('X-Powered-By');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(), usb=()');
    header('Cross-Origin-Opener-Policy: same-origin');
    header('X-Permitted-Cross-Domain-Policies: none');

    if (filter_var(Env::get('SECURITY_CSP_ENABLED', true), FILTER_VALIDATE_BOOL)) {
        header('Content-Security-Policy: ' . ContentSecurityPolicy::headerValue(RequestSecurity::isHttps()));
    }

    if (RequestSecurity::isHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    if (!str_starts_with($requestPath, '/assets/') && !str_starts_with($requestPath, '/uploads/')) {
        header('Cache-Control: no-store, private, max-age=0');
        header('Pragma: no-cache');
    }
}

set_exception_handler(static function (Throwable $exception) use ($debug): void {
    $logDir = __DIR__ . '/storage/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0775, true);
    }

    error_log(
        '[' . date('Y-m-d H:i:s') . '] ' . $exception . PHP_EOL,
        3,
        $logDir . '/app.log'
    );

    http_response_code(500);
    if ($debug) {
        echo '<pre style="white-space:pre-wrap;font-family:monospace;padding:24px">' .
            htmlspecialchars((string) $exception, ENT_QUOTES, 'UTF-8') .
            '</pre>';
        return;
    }

    echo 'Ocorreu um erro interno. Consulte storage/logs/app.log.';
});
