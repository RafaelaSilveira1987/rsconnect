<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if (str_starts_with($path, '/uploads/') && $path !== '/uploads/') {
    $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, ['png', 'jpg', 'jpeg', 'webp'], true)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        header('X-Content-Type-Options: nosniff');
        exit('Arquivo não encontrado.');
    }
}

if ($path !== '/' && is_file($file)) {
    return false;
}

// O servidor embutido informa a própria rota como SCRIPT_NAME. Normalize para
// que o Router não remova indevidamente o primeiro segmento da URL.
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';

require __DIR__ . '/index.php';
