<?php

declare(strict_types=1);

namespace App\Repositories;

use PDOException;

final class TeamRepository extends BaseRepository
{
    public function upsert(array $team): int
    {
        $existing = $this->fetchOne('SELECT id FROM teams WHERE external_id = :external_id LIMIT 1', [
            'external_id' => $team['external_id'],
        ]);

        if ($existing !== null) {
            try {
                $this->execute(
                    'UPDATE teams
                     SET name = :name, logo = :logo, country = :country
                     WHERE id = :id',
                    [
                        'id' => $existing['id'],
                        'name' => $team['name'],
                        'logo' => $team['logo'] ?? null,
                        'country' => $team['country'] ?? 'N/A',
                    ]
                );
            } catch (PDOException $exception) {
                if (!$this->isLegacySchemaMismatch($exception)) {
                    throw $exception;
                }

                $this->logSchemaFallback('upsert.update', $exception);

                try {
                    $this->execute(
                        'UPDATE teams
                         SET name = :name, country = :country
                         WHERE id = :id',
                        [
                            'id' => $existing['id'],
                            'name' => $team['name'],
                            'country' => $team['country'] ?? 'N/A',
                        ]
                    );
                } catch (PDOException $fallbackException) {
                    if (!$this->isLegacySchemaMismatch($fallbackException)) {
                        throw $fallbackException;
                    }

                    $this->logSchemaFallback('upsert.update_minimal', $fallbackException);

                    $this->execute(
                        'UPDATE teams
                         SET name = :name
                         WHERE id = :id',
                        [
                            'id' => $existing['id'],
                            'name' => $team['name'],
                        ]
                    );
                }
            }

            return (int) $existing['id'];
        }

        try {
            $this->execute(
                'INSERT INTO teams (external_id, name, logo, country)
                 VALUES (:external_id, :name, :logo, :country)',
                [
                    'external_id' => $team['external_id'],
                    'name' => $team['name'],
                    'logo' => $team['logo'] ?? null,
                    'country' => $team['country'] ?? 'N/A',
                ]
            );
        } catch (PDOException $exception) {
            if (!$this->isLegacySchemaMismatch($exception)) {
                throw $exception;
            }

            $this->logSchemaFallback('upsert.insert', $exception);

            try {
                $this->execute(
                    'INSERT INTO teams (external_id, name, country)
                     VALUES (:external_id, :name, :country)',
                    [
                        'external_id' => $team['external_id'],
                        'name' => $team['name'],
                        'country' => $team['country'] ?? 'N/A',
                    ]
                );
            } catch (PDOException $fallbackException) {
                if (!$this->isLegacySchemaMismatch($fallbackException)) {
                    throw $fallbackException;
                }

                $this->logSchemaFallback('upsert.insert_minimal', $fallbackException);

                $this->execute(
                    'INSERT INTO teams (external_id, name)
                     VALUES (:external_id, :name)',
                    [
                        'external_id' => $team['external_id'],
                        'name' => $team['name'],
                    ]
                );
            }
        }

        return $this->lastInsertId();
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

        app_log('warning', 'Usando persistencia legada para teams por schema desatualizado.', [
            'operation' => $operation,
            'exception' => $exception->getMessage(),
        ]);
    }
}
