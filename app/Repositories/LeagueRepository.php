<?php

declare(strict_types=1);

namespace App\Repositories;

use PDOException;

final class LeagueRepository extends BaseRepository
{
    public function all(bool $onlyEnabled = false): array
    {
        $sql = 'SELECT * FROM leagues';
        if ($onlyEnabled) {
            $sql .= ' WHERE enabled = 1';
        }

        $sql .= ' ORDER BY country ASC, name ASC';

        return $this->fetchAll($sql);
    }

    public function findById(int $id): ?array
    {
        return $this->fetchOne('SELECT * FROM leagues WHERE id = :id LIMIT 1', ['id' => $id]);
    }

    public function upsert(array $league): int
    {
        $existing = $this->fetchOne('SELECT id FROM leagues WHERE external_id = :external_id LIMIT 1', [
            'external_id' => $league['external_id'],
        ]);

        if ($existing !== null) {
            try {
                $this->execute(
                    'UPDATE leagues
                     SET name = :name, country = :country, logo = :logo, season = :season
                     WHERE id = :id',
                    [
                        'id' => $existing['id'],
                        'name' => $league['name'],
                        'country' => $league['country'],
                        'logo' => $league['logo'] ?? null,
                        'season' => $league['season'],
                    ]
                );
            } catch (PDOException $exception) {
                if (!$this->isLegacySchemaMismatch($exception)) {
                    throw $exception;
                }

                $this->logSchemaFallback('upsert.update', $exception);

                try {
                    $this->execute(
                        'UPDATE leagues
                         SET name = :name, country = :country
                         WHERE id = :id',
                        [
                            'id' => $existing['id'],
                            'name' => $league['name'],
                            'country' => $league['country'],
                        ]
                    );
                } catch (PDOException $fallbackException) {
                    if (!$this->isLegacySchemaMismatch($fallbackException)) {
                        throw $fallbackException;
                    }

                    $this->logSchemaFallback('upsert.update_minimal', $fallbackException);

                    $this->execute(
                        'UPDATE leagues
                         SET name = :name
                         WHERE id = :id',
                        [
                            'id' => $existing['id'],
                            'name' => $league['name'],
                        ]
                    );
                }
            }

            return (int) $existing['id'];
        }

        try {
            $this->execute(
                'INSERT INTO leagues (external_id, name, country, logo, season, enabled)
                 VALUES (:external_id, :name, :country, :logo, :season, :enabled)',
                [
                    'external_id' => $league['external_id'],
                    'name' => $league['name'],
                    'country' => $league['country'],
                    'logo' => $league['logo'] ?? null,
                    'season' => $league['season'],
                    'enabled' => $league['enabled'] ?? 1,
                ]
            );
        } catch (PDOException $exception) {
            if (!$this->isLegacySchemaMismatch($exception)) {
                throw $exception;
            }

            $this->logSchemaFallback('upsert.insert', $exception);

            try {
                $this->execute(
                    'INSERT INTO leagues (external_id, name, country)
                     VALUES (:external_id, :name, :country)',
                    [
                        'external_id' => $league['external_id'],
                        'name' => $league['name'],
                        'country' => $league['country'],
                    ]
                );
            } catch (PDOException $fallbackException) {
                if (!$this->isLegacySchemaMismatch($fallbackException)) {
                    throw $fallbackException;
                }

                $this->logSchemaFallback('upsert.insert_minimal', $fallbackException);

                $this->execute(
                    'INSERT INTO leagues (external_id, name)
                     VALUES (:external_id, :name)',
                    [
                        'external_id' => $league['external_id'],
                        'name' => $league['name'],
                    ]
                );
            }
        }

        return $this->lastInsertId();
    }

    public function toggle(int $id): void
    {
        $this->execute('UPDATE leagues SET enabled = CASE WHEN enabled = 1 THEN 0 ELSE 1 END WHERE id = :id', [
            'id' => $id,
        ]);
    }

    private function isLegacySchemaMismatch(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unknown column')
            || str_contains($message, '42s22')
            || str_contains($message, '1054')
            || str_contains($message, 'doesn\'t have a default value')
            || str_contains($message, 'does not have a default value')
            || str_contains($message, '1364');
    }

    private function logSchemaFallback(string $operation, PDOException $exception): void
    {
        if (!function_exists('app_log')) {
            return;
        }

        app_log('warning', 'Usando persistencia legada para leagues por schema desatualizado.', [
            'operation' => $operation,
            'exception' => $exception->getMessage(),
        ]);
    }
}
