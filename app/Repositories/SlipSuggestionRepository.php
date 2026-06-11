<?php

declare(strict_types=1);

namespace App\Repositories;

final class SlipSuggestionRepository extends BaseRepository
{
    public function create(int $userId, array $slip): array
    {
        $this->execute(
            'INSERT INTO slip_suggestions
             (user_id, risk_profile, market_focus, selections_json, global_confidence, global_risk, explanation)
             VALUES
             (:user_id, :risk_profile, :market_focus, :selections_json, :global_confidence, :global_risk, :explanation)',
            [
                'user_id' => $userId,
                'risk_profile' => $slip['risk_profile'],
                'market_focus' => $slip['market_focus'],
                'selections_json' => $this->encodeJson($slip['selections']),
                'global_confidence' => $slip['global_confidence'],
                'global_risk' => $slip['global_risk'],
                'explanation' => $slip['explanation'],
            ]
        );

        return (array) $this->findById($this->lastInsertId());
    }

    public function findById(int $id): ?array
    {
        $row = $this->fetchOne('SELECT * FROM slip_suggestions WHERE id = :id LIMIT 1', ['id' => $id]);

        if ($row === null) {
            return null;
        }

        $row['selections'] = $this->decodeJson($row['selections_json'] ?? null, []);

        return $row;
    }

    public function latestByUser(int $userId, int $limit = 10): array
    {
        $rows = $this->fetchAll(
            'SELECT * FROM slip_suggestions
             WHERE user_id = :user_id
             ORDER BY created_at DESC
             LIMIT ' . (int) $limit,
            ['user_id' => $userId]
        );

        return array_map(function (array $row): array {
            $row['selections'] = $this->decodeJson($row['selections_json'] ?? null, []);
            return $row;
        }, $rows);
    }

    public function adminSummary(): array
    {
        return $this->fetchOne(
            'SELECT COUNT(*) AS total_slips,
                    SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS slips_today
             FROM slip_suggestions'
        ) ?? [];
    }
}
