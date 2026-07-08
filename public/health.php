<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;

header('Content-Type: text/plain; charset=UTF-8');

echo "app: ok\n";

try {
    $pdo = Database::connection();
    echo "database: ok\n";
    echo "database_name: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
} catch (Throwable $exception) {
    http_response_code(500);
    echo "database: error\n";
    echo $exception->getMessage() . "\n";
}
