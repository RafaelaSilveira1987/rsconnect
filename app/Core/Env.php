<?php

declare(strict_types=1);

namespace App\Core;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, " \t\n\r\0\x0B\"'");

            // Variáveis reais do servidor/contêiner têm prioridade sobre o arquivo .env.
            // Em alguns ambientes Apache/EasyPanel elas chegam em $_SERVER, não em getenv().
            $serverValue = $_SERVER[$key] ?? getenv($key);
            if ($serverValue !== false && $serverValue !== null && trim((string) $serverValue) !== '') {
                self::$values[$key] = trim((string) $serverValue);
                $_ENV[$key] = trim((string) $serverValue);
                continue;
            }

            self::$values[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $candidates = [
            self::$values[$key] ?? null,
            $_ENV[$key] ?? null,
            $_SERVER[$key] ?? null,
            getenv($key),
        ];

        foreach ($candidates as $value) {
            if ($value !== false && $value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $default;
    }
}
