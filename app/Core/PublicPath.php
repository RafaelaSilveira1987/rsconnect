<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Separates the public URL mount point from the internal application routes.
 * The same application remains usable at /login on its original subdomain.
 */
final class PublicPath
{
    public static function internalRequestUri(string $requestUri, string $appUrl): string
    {
        $basePath = parse_url($appUrl, PHP_URL_PATH);
        if (!is_string($basePath) || $basePath === '' || $basePath === '/') {
            return $requestUri;
        }

        $basePath = '/' . trim($basePath, '/');
        $requestPath = parse_url($requestUri, PHP_URL_PATH);
        if (!is_string($requestPath) || $requestPath === '') {
            return $requestUri;
        }

        if ($requestPath === $basePath) {
            $internalPath = '/';
        } elseif (str_starts_with($requestPath, $basePath . '/')) {
            $internalPath = substr($requestPath, strlen($basePath));
        } else {
            return $requestUri;
        }

        $queryPosition = strpos($requestUri, '?');
        return $internalPath . ($queryPosition === false ? '' : substr($requestUri, $queryPosition));
    }
}
