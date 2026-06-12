<?php

declare(strict_types=1);

namespace App\Repositories;

use PDOException;
use Throwable;

final class MatchRepository extends BaseRepository
{
    private LeagueRepository $leagueRepository;
    private TeamRepository $teamRepository;

    public function __construct()
    {
        parent::__construct();
        $this->leagueRepository = new LeagueRepository($this->db);
        $this->teamRepository = new TeamRepository($this->db);
    }

    public function syncFixture(array $fixture): array
    {
        $leagueId = $this->leagueRepository->upsert($fixture['league']);
        $homeTeamId = $this->teamRepository->upsert($fixture['home_team']);
        $awayTeamId = $this->teamRepository->upsert($fixture['away_team']);

        $existing = $this->fetchOne('SELECT id FROM matches WHERE external_fixture_id = :external_fixture_id LIMIT 1', [
            'external_fixture_id' => $fixture['fixture_id'],
        ]);

        $payload = [
            'external_fixture_id' => $fixture['fixture_id'],
            'league_id' => $leagueId,
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
            'date' => $this->normalizeDatabaseDate((string) ($fixture['date'] ?? '')),
            'status' => $fixture['status'],
            'home_score' => $fixture['home_score'],
            'away_score' => $fixture['away_score'],
            'raw_data_json' => $this->encodeJson($fixture['raw'] ?? $fixture),
        ];

        if ($existing !== null) {
            $payload['id'] = $existing['id'];
            try {
                $this->execute(
                    'UPDATE matches
                     SET league_id = :league_id,
                         home_team_id = :home_team_id,
                         away_team_id = :away_team_id,
                         date = :date,
                         status = :status,
                         home_score = :home_score,
                         away_score = :away_score,
                         raw_data_json = :raw_data_json
                     WHERE id = :id',
                    $this->onlyParams($payload, [
                        'id',
                        'league_id',
                        'home_team_id',
                        'away_team_id',
                        'date',
                        'status',
                        'home_score',
                        'away_score',
                        'raw_data_json',
                    ])
                );
            } catch (PDOException $exception) {
                if (!$this->isLegacySchemaMismatch($exception)) {
                    throw $exception;
                }

                $this->logSchemaFallback('syncFixture.update', $exception);
                $this->persistLegacyFixtureUpdate($payload);
            }

            return (array) $this->findById((int) $existing['id']);
        }

        try {
            $this->execute(
                'INSERT INTO matches
                 (external_fixture_id, league_id, home_team_id, away_team_id, date, status, home_score, away_score, raw_data_json)
                 VALUES
                 (:external_fixture_id, :league_id, :home_team_id, :away_team_id, :date, :status, :home_score, :away_score, :raw_data_json)',
                $this->onlyParams($payload, [
                    'external_fixture_id',
                    'league_id',
                    'home_team_id',
                    'away_team_id',
                    'date',
                    'status',
                    'home_score',
                    'away_score',
                    'raw_data_json',
                ])
            );
        } catch (PDOException $exception) {
            if (!$this->isLegacySchemaMismatch($exception)) {
                throw $exception;
            }

            $this->logSchemaFallback('syncFixture.insert', $exception);
            $this->persistLegacyFixtureInsert($payload);
        }

        return (array) $this->findById($this->lastInsertId());
    }

    public function syncFixtures(array $fixtures): array
    {
        $synced = [];
        foreach ($fixtures as $fixture) {
            try {
                $synced[] = $this->syncFixture($fixture);
            } catch (Throwable $exception) {
                if (function_exists('app_log')) {
                    app_log('error', 'Falha ao sincronizar fixture individual.', [
                        'fixture_id' => (int) ($fixture['fixture_id'] ?? 0),
                        'date' => (string) ($fixture['date'] ?? ''),
                        'status' => (string) ($fixture['status'] ?? ''),
                        'league' => (string) (($fixture['league']['name'] ?? '')),
                        'home_team' => (string) (($fixture['home_team']['name'] ?? '')),
                        'away_team' => (string) (($fixture['away_team']['name'] ?? '')),
                        'exception' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if ($fixtures !== [] && $synced === [] && function_exists('app_log')) {
            app_log('error', 'Nenhum fixture foi sincronizado com sucesso.', [
                'received_fixtures' => count($fixtures),
            ]);
        }

        return $synced;
    }

    public function findById(int $id): ?array
    {
        try {
            return $this->fetchOne(
                'SELECT m.*,
                        l.name AS league_name,
                        l.country AS league_country,
                        l.logo AS league_logo,
                        l.enabled AS league_enabled,
                        ht.name AS home_team_name,
                        ht.logo AS home_team_logo,
                        at.name AS away_team_name,
                        at.logo AS away_team_logo
                 FROM matches m
                 INNER JOIN leagues l ON l.id = m.league_id
                 INNER JOIN teams ht ON ht.id = m.home_team_id
                 INNER JOIN teams at ON at.id = m.away_team_id
                 WHERE m.id = :id
                 LIMIT 1',
                ['id' => $id]
            );
        } catch (PDOException $exception) {
            if (!$this->isLegacySchemaMismatch($exception)) {
                throw $exception;
            }

            $this->logSchemaFallback('findById', $exception);

            return $this->fetchOne(
                "SELECT m.*,
                        l.name AS league_name,
                        '' AS league_country,
                        NULL AS league_logo,
                        1 AS league_enabled,
                        ht.name AS home_team_name,
                        NULL AS home_team_logo,
                        at.name AS away_team_name,
                        NULL AS away_team_logo
                 FROM matches m
                 INNER JOIN leagues l ON l.id = m.league_id
                 INNER JOIN teams ht ON ht.id = m.home_team_id
                 INNER JOIN teams at ON at.id = m.away_team_id
                 WHERE m.id = :id
                 LIMIT 1",
                ['id' => $id]
            );
        }
    }

    public function findByFixtureId(int $fixtureId): ?array
    {
        $row = $this->fetchOne('SELECT id FROM matches WHERE external_fixture_id = :fixture_id LIMIT 1', [
            'fixture_id' => $fixtureId,
        ]);

        return $row !== null ? $this->findById((int) $row['id']) : null;
    }

    public function fixturesByDate(string $date, array $filters = []): array
    {
        $sql = 'SELECT m.*,
                       l.name AS league_name,
                       l.country AS league_country,
                       l.logo AS league_logo,
                       l.enabled AS league_enabled,
                       ht.name AS home_team_name,
                       ht.logo AS home_team_logo,
                       at.name AS away_team_name,
                       at.logo AS away_team_logo,
                       ma.main_tendency,
                       ma.over_1_5_probability,
                       ma.over_2_5_probability,
                       ma.btts_probability,
                       ma.confidence_score,
                       ma.risk_level,
                       ma.summary
                FROM matches m
                INNER JOIN leagues l ON l.id = m.league_id
                INNER JOIN teams ht ON ht.id = m.home_team_id
                INNER JOIN teams at ON at.id = m.away_team_id
                LEFT JOIN match_analyses ma ON ma.match_id = m.id
                WHERE DATE(m.date) = :match_date';

        $params = ['match_date' => $date];

        if (!empty($filters['league_id'])) {
            $sql .= ' AND m.league_id = :league_id';
            $params['league_id'] = (int) $filters['league_id'];
        }

        if (!empty($filters['country'])) {
            $sql .= ' AND l.country = :country';
            $params['country'] = $filters['country'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND m.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['enabled_only'])) {
            $sql .= ' AND l.enabled = 1';
        }

        $sql .= ' ORDER BY m.date ASC, l.country ASC, l.name ASC';

        try {
            return $this->fetchAll($sql, $params);
        } catch (PDOException $exception) {
            if (!$this->isLegacySchemaMismatch($exception)) {
                throw $exception;
            }

            $this->logSchemaFallback('fixturesByDate', $exception);

            $legacySql = "SELECT m.*,
                                 l.name AS league_name,
                                 '' AS league_country,
                                 NULL AS league_logo,
                                 1 AS league_enabled,
                                 ht.name AS home_team_name,
                                 NULL AS home_team_logo,
                                 at.name AS away_team_name,
                                 NULL AS away_team_logo,
                                 NULL AS main_tendency,
                                 NULL AS over_1_5_probability,
                                 NULL AS over_2_5_probability,
                                 NULL AS btts_probability,
                                 NULL AS confidence_score,
                                 'medium' AS risk_level,
                                 NULL AS summary
                          FROM matches m
                          INNER JOIN leagues l ON l.id = m.league_id
                          INNER JOIN teams ht ON ht.id = m.home_team_id
                          INNER JOIN teams at ON at.id = m.away_team_id
                          WHERE DATE(m.date) = :match_date";

            $legacyParams = ['match_date' => $date];

            if (!empty($filters['league_id'])) {
                $legacySql .= ' AND m.league_id = :league_id';
                $legacyParams['league_id'] = (int) $filters['league_id'];
            }

            if (!empty($filters['status'])) {
                $legacySql .= ' AND m.status = :status';
                $legacyParams['status'] = $filters['status'];
            }

            $legacySql .= ' ORDER BY m.date ASC, l.name ASC';

            return $this->fetchAll($legacySql, $legacyParams);
        }
    }

    public function recentImported(int $limit = 20): array
    {
        return $this->fetchAll(
            'SELECT m.id, m.external_fixture_id, m.date, m.status, l.name AS league_name,
                    ht.name AS home_team_name, at.name AS away_team_name,
                    ma.id AS analysis_id,
                    ma.updated_at AS analysis_updated_at,
                    ma.confidence_score,
                    ma.risk_level
             FROM matches m
             INNER JOIN leagues l ON l.id = m.league_id
             INNER JOIN teams ht ON ht.id = m.home_team_id
             INNER JOIN teams at ON at.id = m.away_team_id
             LEFT JOIN match_analyses ma ON ma.match_id = m.id
             ORDER BY m.updated_at DESC
             LIMIT ' . (int) $limit
        );
    }

    public function listLeaguesForDate(string $date): array
    {
        try {
            return $this->fetchAll(
                'SELECT DISTINCT l.id, l.name, l.country
                 FROM matches m
                 INNER JOIN leagues l ON l.id = m.league_id
                 WHERE DATE(m.date) = :match_date
                 ORDER BY l.country ASC, l.name ASC',
                ['match_date' => $date]
            );
        } catch (PDOException $exception) {
            if (!$this->isLegacySchemaMismatch($exception)) {
                throw $exception;
            }

            $this->logSchemaFallback('listLeaguesForDate', $exception);

            return $this->fetchAll(
                "SELECT DISTINCT l.id, l.name, '' AS country
                 FROM matches m
                 INNER JOIN leagues l ON l.id = m.league_id
                 WHERE DATE(m.date) = :match_date
                 ORDER BY l.name ASC",
                ['match_date' => $date]
            );
        }
    }

    public function adminSummary(string $focusDate): array
    {
        return $this->fetchOne(
            'SELECT COUNT(*) AS total_matches,
                    SUM(CASE WHEN DATE(m.date) = :focus_date_matches THEN 1 ELSE 0 END) AS matches_on_focus_date,
                    SUM(CASE WHEN m.status IN ("LIVE", "1H", "HT", "2H", "ET", "BT", "INT") THEN 1 ELSE 0 END) AS live_matches,
                    SUM(CASE WHEN ma.id IS NOT NULL THEN 1 ELSE 0 END) AS analyzed_matches,
                    SUM(CASE WHEN ma.id IS NULL THEN 1 ELSE 0 END) AS pending_analyses,
                    SUM(CASE WHEN DATE(m.date) = :focus_date_pending AND ma.id IS NULL THEN 1 ELSE 0 END) AS pending_on_focus_date,
                    SUM(CASE WHEN DATE(m.date) = :focus_date_analyzed AND ma.id IS NOT NULL THEN 1 ELSE 0 END) AS analyzed_on_focus_date
             FROM matches m
             LEFT JOIN match_analyses ma ON ma.match_id = m.id',
            [
                'focus_date_matches' => $focusDate,
                'focus_date_pending' => $focusDate,
                'focus_date_analyzed' => $focusDate,
            ]
        ) ?? [];
    }

    public function pendingAnalysisQueue(string $date, int $limit = 12, bool $enabledOnly = false): array
    {
        $sql = 'SELECT m.id, m.external_fixture_id, m.date, m.status,
                       l.name AS league_name, l.country AS league_country, l.enabled AS league_enabled,
                       ht.name AS home_team_name, at.name AS away_team_name
                FROM matches m
                INNER JOIN leagues l ON l.id = m.league_id
                INNER JOIN teams ht ON ht.id = m.home_team_id
                INNER JOIN teams at ON at.id = m.away_team_id
                LEFT JOIN match_analyses ma ON ma.match_id = m.id
                WHERE DATE(m.date) = :match_date
                AND ma.id IS NULL';

        if ($enabledOnly) {
            $sql .= ' AND l.enabled = 1';
        }

        $sql .= ' ORDER BY m.date ASC
                  LIMIT ' . (int) $limit;

        return $this->fetchAll($sql, ['match_date' => $date]);
    }

    private function isLegacySchemaMismatch(PDOException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unknown column')
            || str_contains($message, '42s22')
            || str_contains($message, '1054')
            || str_contains($message, 'doesn\'t have a default value')
            || str_contains($message, 'does not have a default value')
            || str_contains($message, '1364')
            || str_contains($message, 'incorrect datetime value')
            || str_contains($message, '1292')
            || str_contains($message, 'invalid json text')
            || str_contains($message, '3140');
    }

    private function logSchemaFallback(string $operation, PDOException $exception): void
    {
        if (!function_exists('app_log')) {
            return;
        }

        app_log('warning', 'Usando query legada por schema desatualizado no MySQL.', [
            'operation' => $operation,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function persistLegacyFixtureUpdate(array $payload): void
    {
        try {
            $this->execute(
                'UPDATE matches
                 SET league_id = :league_id,
                     home_team_id = :home_team_id,
                     away_team_id = :away_team_id,
                     date = :date,
                     status = :status,
                     home_score = :home_score,
                     away_score = :away_score
                 WHERE id = :id',
                $this->onlyParams($payload, [
                    'id',
                    'league_id',
                    'home_team_id',
                    'away_team_id',
                    'date',
                    'status',
                    'home_score',
                    'away_score',
                ])
            );
        } catch (PDOException $exception) {
            if (!$this->isLegacySchemaMismatch($exception)) {
                throw $exception;
            }

            $this->logSchemaFallback('syncFixture.update_scores_only', $exception);

            $this->execute(
                'UPDATE matches
                 SET league_id = :league_id,
                     home_team_id = :home_team_id,
                     away_team_id = :away_team_id,
                     date = :date,
                     status = :status
                 WHERE id = :id',
                [
                    'id' => $payload['id'],
                    'league_id' => $payload['league_id'],
                    'home_team_id' => $payload['home_team_id'],
                    'away_team_id' => $payload['away_team_id'],
                    'date' => $payload['date'],
                    'status' => $payload['status'],
                ]
            );
        }
    }

    private function persistLegacyFixtureInsert(array $payload): void
    {
        try {
            $this->execute(
                'INSERT INTO matches
                 (external_fixture_id, league_id, home_team_id, away_team_id, date, status, home_score, away_score)
                 VALUES
                 (:external_fixture_id, :league_id, :home_team_id, :away_team_id, :date, :status, :home_score, :away_score)',
                $this->onlyParams($payload, [
                    'external_fixture_id',
                    'league_id',
                    'home_team_id',
                    'away_team_id',
                    'date',
                    'status',
                    'home_score',
                    'away_score',
                ])
            );
        } catch (PDOException $exception) {
            if (!$this->isLegacySchemaMismatch($exception)) {
                throw $exception;
            }

            $this->logSchemaFallback('syncFixture.insert_scores_only', $exception);

            $this->execute(
                'INSERT INTO matches
                 (external_fixture_id, league_id, home_team_id, away_team_id, date, status)
                 VALUES
                 (:external_fixture_id, :league_id, :home_team_id, :away_team_id, :date, :status)',
                [
                    'external_fixture_id' => $payload['external_fixture_id'],
                    'league_id' => $payload['league_id'],
                    'home_team_id' => $payload['home_team_id'],
                    'away_team_id' => $payload['away_team_id'],
                    'date' => $payload['date'],
                    'status' => $payload['status'],
                ]
            );
        }
    }

    private function normalizeDatabaseDate(string $value): string
    {
        $timestamp = strtotime($value);

        return $timestamp !== false
            ? date('Y-m-d H:i:s', $timestamp)
            : date('Y-m-d H:i:s');
    }

    private function onlyParams(array $payload, array $keys): array
    {
        $filtered = [];

        foreach ($keys as $key) {
            $filtered[$key] = $payload[$key] ?? null;
        }

        return $filtered;
    }
}
