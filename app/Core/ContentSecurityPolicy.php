<?php

declare(strict_types=1);

namespace App\Core;

final class ContentSecurityPolicy
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        if (self::$nonce === null) {
            self::$nonce = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        }

        return self::$nonce;
    }

    public static function headerValue(bool $https = false): string
    {
        $nonce = self::nonce();
        $directives = [
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "form-action 'self'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "style-src 'self'",
            "style-src-elem 'self' 'nonce-{$nonce}'",
            "style-src-attr 'unsafe-inline'",
            "script-src 'self' 'nonce-{$nonce}'",
            "script-src-elem 'self' 'nonce-{$nonce}'",
            "script-src-attr 'none'",
            "connect-src 'self'",
            "media-src 'self' blob:",
            "worker-src 'self' blob:",
            "manifest-src 'self'",
        ];

        if ($https) {
            $directives[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $directives) . ';';
    }

    public static function addNonceToMarkup(string $html): string
    {
        if ($html === '') {
            return $html;
        }

        $nonce = htmlspecialchars(self::nonce(), ENT_QUOTES, 'UTF-8');
        $updated = preg_replace(
            '/<(script|style)\b(?![^>]*\bnonce\s*=)/i',
            '<$1 nonce="' . $nonce . '"',
            $html
        );

        return is_string($updated) ? $updated : $html;
    }
}
