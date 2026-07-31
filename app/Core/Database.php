<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = (string) Env::get('DB_HOST', '127.0.0.1');
        $port = (string) Env::get('DB_PORT', '3306');
        $database = (string) Env::get('DB_DATABASE', 'rs_connect');
        $username = (string) Env::get('DB_USERNAME', 'root');
        $password = (string) Env::get('DB_PASSWORD', '');

        try {
            self::$connection = new PDO(
                "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
                $username,
                $password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]
            );

            // Contrato v36.10.4: toda data técnica gerada pelo banco usa UTC.
            // A conversão para o fuso da empresa acontece somente na camada
            // de apresentação, filtros e exportações.
            self::$connection->exec("SET SESSION time_zone = '+00:00'");
        } catch (PDOException $exception) {
            throw new RuntimeException(
                'Não foi possível conectar ao banco. Confira o arquivo .env.',
                0,
                $exception
            );
        }

        return self::$connection;
    }
}
