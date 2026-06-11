<?php

declare(strict_types=1);

namespace App\Repositories;

final class TeamStatsRepository extends BaseRepository
{
    public function upsert(array $stats): void
    {
        $this->execute(
            'INSERT INTO team_stats
             (team_id, league_id, season, matches_played, goals_for_avg, goals_against_avg, over_1_5_rate, over_2_5_rate, btts_rate, clean_sheet_rate, failed_to_score_rate, raw_data_json)
             VALUES
             (:team_id, :league_id, :season, :matches_played, :goals_for_avg, :goals_against_avg, :over_1_5_rate, :over_2_5_rate, :btts_rate, :clean_sheet_rate, :failed_to_score_rate, :raw_data_json)
             ON DUPLICATE KEY UPDATE
             matches_played = VALUES(matches_played),
             goals_for_avg = VALUES(goals_for_avg),
             goals_against_avg = VALUES(goals_against_avg),
             over_1_5_rate = VALUES(over_1_5_rate),
             over_2_5_rate = VALUES(over_2_5_rate),
             btts_rate = VALUES(btts_rate),
             clean_sheet_rate = VALUES(clean_sheet_rate),
             failed_to_score_rate = VALUES(failed_to_score_rate),
             raw_data_json = VALUES(raw_data_json)',
            [
                'team_id' => $stats['team_id'],
                'league_id' => $stats['league_id'],
                'season' => $stats['season'],
                'matches_played' => $stats['matches_played'],
                'goals_for_avg' => $stats['goals_for_avg'],
                'goals_against_avg' => $stats['goals_against_avg'],
                'over_1_5_rate' => $stats['over_1_5_rate'],
                'over_2_5_rate' => $stats['over_2_5_rate'],
                'btts_rate' => $stats['btts_rate'],
                'clean_sheet_rate' => $stats['clean_sheet_rate'],
                'failed_to_score_rate' => $stats['failed_to_score_rate'],
                'raw_data_json' => $this->encodeJson($stats['raw'] ?? []),
            ]
        );
    }

    public function findForTeamLeagueSeason(int $teamId, int $leagueId, int $season): ?array
    {
        $row = $this->fetchOne(
            'SELECT * FROM team_stats
             WHERE team_id = :team_id AND league_id = :league_id AND season = :season
             LIMIT 1',
            [
                'team_id' => $teamId,
                'league_id' => $leagueId,
                'season' => $season,
            ]
        );

        if ($row === null) {
            return null;
        }

        $row['raw'] = $this->decodeJson($row['raw_data_json'] ?? null, []);

        return $row;
    }
}
