<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $instance = null;

    public static function connection(): PDO
    {
        if (self::$instance instanceof PDO) {
            return self::$instance;
        }

        $host = env('DB_HOST', '127.0.0.1');
        $port = env('DB_PORT', '3306');
        $name = env('DB_NAME', 'goalvision_ai');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');
        $charset = env('DB_CHARSET', 'utf8mb4');

        $hosts = array_values(array_unique(array_filter([
            $host,
            'localhost',
            '127.0.0.1',
        ], static fn (?string $value): bool => is_string($value) && trim($value) !== '')));

        $lastException = null;
        $attempts = [];

        foreach ($hosts as $candidateHost) {
            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $candidateHost, $port, $name, $charset);

            try {
                self::$instance = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);

                if (function_exists('app_log')) {
                    app_log('info', 'Conexao MySQL estabelecida.', [
                        'db_host' => $candidateHost,
                        'db_port' => $port,
                        'db_name' => $name,
                        'db_user' => $user,
                    ]);
                }

                return self::$instance;
            } catch (PDOException $exception) {
                $lastException = $exception;
                $attempts[] = [
                    'db_host' => $candidateHost,
                    'exception' => $exception->getMessage(),
                ];
            }
        }

        if ($lastException instanceof PDOException) {
            if (function_exists('app_log')) {
                app_log('error', 'Falha na conexao MySQL.', [
                    'configured_db_host' => $host,
                    'db_port' => $port,
                    'db_name' => $name,
                    'db_user' => $user,
                    'env_exists' => is_file(BASE_PATH . '/.env'),
                    'env_local_exists' => is_file(BASE_PATH . '/.env.local'),
                    'attempts' => $attempts,
                ]);
            }

            throw new PDOException('MySQL connection failed: ' . $lastException->getMessage(), (int) $lastException->getCode());
        }

        throw new PDOException('MySQL connection failed: unknown error.');
    }

    public static function reconnect(): PDO
    {
        self::$instance = null;

        return self::connection();
    }

    public static function isConfigured(): bool
    {
        return env('DB_HOST') !== null && env('DB_NAME') !== null && env('DB_USER') !== null;
    }
}
