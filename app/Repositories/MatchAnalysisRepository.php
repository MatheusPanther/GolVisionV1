<?php

declare(strict_types=1);

namespace App\Repositories;

final class MatchAnalysisRepository extends BaseRepository
{
    public function findByMatchId(int $matchId): ?array
    {
        $row = $this->fetchOne('SELECT * FROM match_analyses WHERE match_id = :match_id LIMIT 1', [
            'match_id' => $matchId,
        ]);

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function findFreshByMatchId(int $matchId, int $maxAgeMinutes = 480): ?array
    {
        $row = $this->fetchOne(
            'SELECT * FROM match_analyses
             WHERE match_id = :match_id
             AND updated_at >= DATE_SUB(NOW(), INTERVAL ' . (int) $maxAgeMinutes . ' MINUTE)
             LIMIT 1',
            [
                'match_id' => $matchId,
            ]
        );

        return $row !== null ? $this->hydrate($row) : null;
    }

    public function save(int $matchId, array $analysis, string $model): array
    {
        $payload = [
            'match_id' => $matchId,
            'main_tendency' => $analysis['main_tendency'],
            'over_1_5_probability' => $analysis['over_1_5_probability'],
            'over_2_5_probability' => $analysis['over_2_5_probability'],
            'btts_probability' => $analysis['btts_probability'],
            'confidence_score' => $analysis['confidence_score'],
            'risk_level' => $analysis['risk_level'],
            'summary' => $analysis['summary'],
            'key_factors_json' => $this->encodeJson($analysis['key_factors']),
            'red_flags_json' => $this->encodeJson($analysis['red_flags']),
            'conservative_scenario_json' => $this->encodeJson($analysis['conservative_scenario']),
            'balanced_scenario_json' => $this->encodeJson($analysis['balanced_scenario']),
            'bold_scenario_json' => $this->encodeJson($analysis['bold_scenario']),
            'disclaimer' => $analysis['disclaimer'],
            'model_used' => $model,
            'raw_ai_response_json' => $this->encodeJson($analysis),
        ];

        $existing = $this->fetchOne('SELECT id FROM match_analyses WHERE match_id = :match_id LIMIT 1', [
            'match_id' => $matchId,
        ]);

        if ($existing !== null) {
            $payload['id'] = $existing['id'];
            $this->execute(
                'UPDATE match_analyses
                 SET main_tendency = :main_tendency,
                     over_1_5_probability = :over_1_5_probability,
                     over_2_5_probability = :over_2_5_probability,
                     btts_probability = :btts_probability,
                     confidence_score = :confidence_score,
                     risk_level = :risk_level,
                     summary = :summary,
                     key_factors_json = :key_factors_json,
                     red_flags_json = :red_flags_json,
                     conservative_scenario_json = :conservative_scenario_json,
                     balanced_scenario_json = :balanced_scenario_json,
                     bold_scenario_json = :bold_scenario_json,
                     disclaimer = :disclaimer,
                     model_used = :model_used,
                     raw_ai_response_json = :raw_ai_response_json
                 WHERE id = :id',
                $payload
            );
        } else {
            $this->execute(
                'INSERT INTO match_analyses
                 (match_id, main_tendency, over_1_5_probability, over_2_5_probability, btts_probability, confidence_score, risk_level, summary, key_factors_json, red_flags_json, conservative_scenario_json, balanced_scenario_json, bold_scenario_json, disclaimer, model_used, raw_ai_response_json)
                 VALUES
                 (:match_id, :main_tendency, :over_1_5_probability, :over_2_5_probability, :btts_probability, :confidence_score, :risk_level, :summary, :key_factors_json, :red_flags_json, :conservative_scenario_json, :balanced_scenario_json, :bold_scenario_json, :disclaimer, :model_used, :raw_ai_response_json)',
                $payload
            );
        }

        return (array) $this->findByMatchId($matchId);
    }

    public function history(int $limit = 50): array
    {
        $rows = $this->fetchAll(
            'SELECT ma.*,
                    m.date,
                    m.status AS match_status,
                    m.home_score,
                    m.away_score,
                    l.name AS league_name,
                    ht.name AS home_team_name,
                    at.name AS away_team_name,
                    ar.final_score,
                    ar.over_1_5_hit,
                    ar.over_2_5_hit,
                    ar.btts_hit,
                    ar.result_status
             FROM match_analyses ma
             INNER JOIN matches m ON m.id = ma.match_id
             INNER JOIN leagues l ON l.id = m.league_id
             INNER JOIN teams ht ON ht.id = m.home_team_id
             INNER JOIN teams at ON at.id = m.away_team_id
             LEFT JOIN analysis_results ar ON ar.match_analysis_id = ma.id
             ORDER BY ma.created_at DESC
             LIMIT ' . (int) $limit
        );

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    public function upsertResult(int $analysisId, array $result): void
    {
        $this->execute(
            'INSERT INTO analysis_results
             (match_analysis_id, final_score, over_1_5_hit, over_2_5_hit, btts_hit, result_status, settled_at)
             VALUES
             (:match_analysis_id, :final_score, :over_1_5_hit, :over_2_5_hit, :btts_hit, :result_status, :settled_at)
             ON DUPLICATE KEY UPDATE
             final_score = VALUES(final_score),
             over_1_5_hit = VALUES(over_1_5_hit),
             over_2_5_hit = VALUES(over_2_5_hit),
             btts_hit = VALUES(btts_hit),
             result_status = VALUES(result_status),
             settled_at = VALUES(settled_at)',
            [
                'match_analysis_id' => $analysisId,
                'final_score' => $result['final_score'],
                'over_1_5_hit' => $result['over_1_5_hit'],
                'over_2_5_hit' => $result['over_2_5_hit'],
                'btts_hit' => $result['btts_hit'],
                'result_status' => $result['result_status'],
                'settled_at' => $result['settled_at'],
            ]
        );
    }

    public function recentGenerated(int $limit = 12): array
    {
        $rows = $this->fetchAll(
            'SELECT ma.*,
                    m.date,
                    m.status AS match_status,
                    l.name AS league_name,
                    ht.name AS home_team_name,
                    at.name AS away_team_name
             FROM match_analyses ma
             INNER JOIN matches m ON m.id = ma.match_id
             INNER JOIN leagues l ON l.id = m.league_id
             INNER JOIN teams ht ON ht.id = m.home_team_id
             INNER JOIN teams at ON at.id = m.away_team_id
             ORDER BY ma.updated_at DESC
             LIMIT ' . (int) $limit
        );

        return array_map(fn (array $row): array => $this->hydrate($row), $rows);
    }

    private function hydrate(array $row): array
    {
        $row['key_factors'] = $this->decodeJson($row['key_factors_json'] ?? null, []);
        $row['red_flags'] = $this->decodeJson($row['red_flags_json'] ?? null, []);
        $row['conservative_scenario'] = $this->decodeJson($row['conservative_scenario_json'] ?? null, []);
        $row['balanced_scenario'] = $this->decodeJson($row['balanced_scenario_json'] ?? null, []);
        $row['bold_scenario'] = $this->decodeJson($row['bold_scenario_json'] ?? null, []);
        $row['raw_ai_response'] = $this->decodeJson($row['raw_ai_response_json'] ?? null, []);

        return $row;
    }
}
