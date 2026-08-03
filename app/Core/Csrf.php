<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    private const TOKEN_KEY = '_csrf';
    private const ISSUED_AT_KEY = '_csrf_issued_at';

    public static function token(): string
    {
        $issuedAt = (int) ($_SESSION[self::ISSUED_AT_KEY] ?? 0);
        $ttlSeconds = max(300, (int) Env::get('SECURITY_CSRF_TTL_MINUTES', 120) * 60);
        if (empty($_SESSION[self::TOKEN_KEY]) || $issuedAt <= 0 || (time() - $issuedAt) > $ttlSeconds) {
            self::regenerate();
        }

        return (string) $_SESSION[self::TOKEN_KEY];
    }

    public static function regenerate(): string
    {
        $_SESSION[self::TOKEN_KEY] = bin2hex(random_bytes(32));
        $_SESSION[self::ISSUED_AT_KEY] = time();
        return (string) $_SESSION[self::TOKEN_KEY];
    }

    public static function forget(): void
    {
        unset($_SESSION[self::TOKEN_KEY], $_SESSION[self::ISSUED_AT_KEY]);
    }

    public static function input(): string
    {
        return '<input type="hidden" name="_token" value="' .
            htmlspecialchars(self::token(), ENT_QUOTES, 'UTF-8') . '">';
    }

    public static function requestToken(): ?string
    {
        $token = $_POST['_token']
            ?? $_SERVER['HTTP_X_CSRF_TOKEN']
            ?? $_SERVER['HTTP_X_XSRF_TOKEN']
            ?? null;

        return is_string($token) ? trim($token) : null;
    }

    public static function validateRequest(): bool
    {
        if (!self::validate(self::requestToken())) {
            return false;
        }

        if (!filter_var(Env::get('SECURITY_CSRF_ORIGIN_CHECK', true), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        return self::originAllowed();
    }

    public static function validate(?string $token): bool
    {
        if (!is_string($token) || $token === '' || !isset($_SESSION[self::TOKEN_KEY])) {
            return false;
        }

        $issuedAt = (int) ($_SESSION[self::ISSUED_AT_KEY] ?? 0);
        if ($issuedAt <= 0) {
            // Compatibilidade com sessões abertas antes da v36.11.1.
            $issuedAt = time();
            $_SESSION[self::ISSUED_AT_KEY] = $issuedAt;
        }
        $ttlSeconds = max(300, (int) Env::get('SECURITY_CSRF_TTL_MINUTES', 120) * 60);
        if ((time() - $issuedAt) > $ttlSeconds) {
            self::forget();
            return false;
        }

        return hash_equals((string) $_SESSION[self::TOKEN_KEY], $token);
    }

    private static function originAllowed(): bool
    {
        $fetchSite = strtolower(trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
        if ($fetchSite === 'cross-site') {
            return false;
        }

        $appUrl = trim((string) Env::get('APP_URL', ''));
        $appParts = parse_url($appUrl);
        $expectedHost = strtolower((string) ($appParts['host'] ?? ''));
        $expectedScheme = strtolower((string) ($appParts['scheme'] ?? ''));
        $expectedPort = self::normalizedPort($expectedScheme, isset($appParts['port']) ? (int) $appParts['port'] : null);
        if ($expectedHost === '') {
            return true;
        }

        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        $referer = trim((string) ($_SERVER['HTTP_REFERER'] ?? ''));
        $source = $origin !== '' ? $origin : $referer;
        if ($source === '') {
            // Compatibilidade com clientes/ambientes que omitem Origin e Referer.
            return true;
        }

        $parts = parse_url($source);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port = self::normalizedPort($scheme, isset($parts['port']) ? (int) $parts['port'] : null);

        if ($host === '' || !hash_equals($expectedHost, $host)) {
            return false;
        }
        if ($expectedScheme !== '' && $scheme !== '' && !hash_equals($expectedScheme, $scheme)) {
            return false;
        }

        return $expectedPort === $port;
    }

    private static function normalizedPort(string $scheme, ?int $port): ?int
    {
        if ($port !== null) {
            return $port;
        }
        return match ($scheme) {
            'https' => 443,
            'http' => 80,
            default => null,
        };
    }
}
