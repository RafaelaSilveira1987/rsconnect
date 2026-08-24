<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class View
{
    public static function render(string $view, array $variables = [], string $layout = 'app'): void
    {
        $base = dirname(__DIR__) . '/Views';
        $viewFile = $base . '/' . str_replace('.', '/', $view) . '.php';
        $layoutFile = $base . '/layouts/' . $layout . '.php';

        if (!is_file($viewFile) || !is_file($layoutFile)) {
            throw new RuntimeException('View não encontrada: ' . $view);
        }

        extract($variables, EXTR_SKIP);
        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();
        require $layoutFile;
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
