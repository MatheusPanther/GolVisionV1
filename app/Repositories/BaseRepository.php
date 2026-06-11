<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;
use PDOException;
use PDOStatement;

abstract class BaseRepository
{
    protected PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connection();
    }

    protected function fetchOne(string $sql, array $params = []): ?array
    {
        $statement = $this->query($sql, $params);
        $result = $statement->fetch();

        return is_array($result) ? $result : null;
    }

    protected function fetchAll(string $sql, array $params = []): array
    {
        $statement = $this->query($sql, $params);
        $results = $statement->fetchAll();

        return is_array($results) ? $results : [];
    }

    protected function execute(string $sql, array $params = []): bool
    {
        return $this->query($sql, $params)->rowCount() >= 0;
    }

    protected function query(string $sql, array $params = []): PDOStatement
    {
        try {
            $statement = $this->db->prepare($sql);
            $statement->execute($params);

            return $statement;
        } catch (PDOException $exception) {
            if (!$this->shouldReconnect($exception)) {
                throw $exception;
            }

            $this->db = Database::reconnect();

            if (function_exists('app_log')) {
                app_log('warning', 'Reconectando ao MySQL apos perda de conexao.', [
                    'exception' => $exception->getMessage(),
                ]);
            }

            $statement = $this->db->prepare($sql);
            $statement->execute($params);

            return $statement;
        }
    }

    protected function lastInsertId(): int
    {
        return (int) $this->db->lastInsertId();
    }

    protected function encodeJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function decodeJson(mixed $value, mixed $default = []): mixed
    {
        if (!is_string($value) || trim($value) === '') {
            return $default;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $default;
    }

    private function shouldReconnect(PDOException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = strtolower($exception->getMessage());

        return in_array($code, ['2006', '2013'], true)
            || str_contains($message, 'server has gone away')
            || str_contains($message, 'lost connection');
    }
}
