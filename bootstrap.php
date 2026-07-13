<?php

declare(strict_types=1);

use App\Core\Env;

require_once __DIR__ . '/app/Core/Autoloader.php';
App\Core\Autoloader::register(__DIR__ . '/app');

Env::load(__DIR__ . '/.env');

date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'America/Sao_Paulo'));

$debug = filter_var(Env::get('APP_DEBUG', false), FILTER_VALIDATE_BOOL);
ini_set('display_errors', $debug ? '1' : '0');
error_reporting(E_ALL);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name((string) Env::get('SESSION_NAME', 'rs_connect_session'));
    session_set_cookie_params([
        'lifetime' => (int) Env::get('SESSION_LIFETIME', 120) * 60,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}


if (!headers_sent() && filter_var(Env::get('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOL)) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || str_starts_with((string) Env::get('APP_URL', ''), 'https://')) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
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
