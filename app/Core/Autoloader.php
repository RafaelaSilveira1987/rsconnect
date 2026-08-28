<?php

declare(strict_types=1);

namespace App\Core;

final class Autoloader
{
    private static bool $registered = false;

    public static function register(string $baseDirectory): void
    {
        if (self::$registered) {
            return;
        }

        $baseDirectory = rtrim($baseDirectory, DIRECTORY_SEPARATOR);

        spl_autoload_register(static function (string $class) use ($baseDirectory): void {
            $prefix = 'App\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }

            $relativeClass = substr($class, strlen($prefix));
            $file = $baseDirectory . DIRECTORY_SEPARATOR
                . str_replace('\\', DIRECTORY_SEPARATOR, $relativeClass)
                . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        });

        self::$registered = true;
    }
}
