<?php

declare(strict_types=1);

namespace App\Core;

final class RequestSecurity
{
    /**
     * Detecta HTTPS sem confiar cegamente em cabeçalhos enviados pelo cliente.
     * Cabeçalhos de proxy só são aceitos quando REMOTE_ADDR pertence a um proxy confiável.
     */
    public static function isHttps(): bool
    {
        $https = strtolower(trim((string) ($_SERVER['HTTPS'] ?? '')));
        if ($https !== '' && $https !== 'off' && $https !== '0') {
            return true;
        }

        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        if ($remote !== '' && self::isTrustedProxy($remote)) {
            $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
            if ($forwardedProto === 'https') {
                return true;
            }

            $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
            if (in_array($forwardedSsl, ['on', '1', 'true'], true)) {
                return true;
            }
        }

        return str_starts_with(strtolower(trim((string) Env::get('APP_URL', ''))), 'https://');
    }

    /**
     * Retorna o IP do cliente. X-Forwarded-For/CF-Connecting-IP/X-Real-IP só
     * são considerados quando a conexão veio de um proxy confiável.
     */
    public static function clientIp(): string
    {
        $remote = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $candidate = $remote;

        if ($remote !== '' && self::isTrustedProxy($remote)) {
            $forwarded = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
            if ($forwarded === '') {
                $forwarded = trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
            }
            if ($forwarded === '') {
                $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
                if ($forwarded !== '') {
                    $forwarded = trim(explode(',', $forwarded)[0] ?? '');
                }
            }
            if ($forwarded !== '' && filter_var($forwarded, FILTER_VALIDATE_IP)) {
                $candidate = $forwarded;
            }
        }

        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
            return $candidate;
        }

        return $remote !== '' ? mb_substr($remote, 0, 45) : '0.0.0.0';
    }

    public static function bearerToken(): string
    {
        $authorization = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if (preg_match('/^Bearer\s+(.+)$/i', $authorization, $matches) !== 1) {
            return '';
        }

        return trim((string) ($matches[1] ?? ''));
    }

    public static function isTrustedProxy(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        foreach (self::trustedProxyCidrs() as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function trustedProxyCidrs(): array
    {
        $configured = trim((string) Env::get('TRUSTED_PROXIES', ''));
        if ($configured !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        // Padrões seguros para proxy reverso no mesmo host/rede Docker.
        return [
            '127.0.0.0/8',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '::1/128',
            'fc00::/7',
            'fe80::/10',
        ];
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            return false;
        }

        if (!str_contains($cidr, '/')) {
            return hash_equals($cidr, $ip);
        }

        [$network, $prefixRaw] = array_pad(explode('/', $cidr, 2), 2, '');
        $ipBinary = @inet_pton($ip);
        $networkBinary = @inet_pton(trim($network));
        if ($ipBinary === false || $networkBinary === false || strlen($ipBinary) !== strlen($networkBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;
        $prefix = filter_var($prefixRaw, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => $maxBits],
        ]);
        if ($prefix === false) {
            return false;
        }

        $wholeBytes = intdiv((int) $prefix, 8);
        $remainingBits = (int) $prefix % 8;
        if ($wholeBytes > 0 && substr($ipBinary, 0, $wholeBytes) !== substr($networkBinary, 0, $wholeBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;
        return (ord($ipBinary[$wholeBytes]) & $mask) === (ord($networkBinary[$wholeBytes]) & $mask);
    }
}
