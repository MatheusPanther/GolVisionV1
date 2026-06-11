<?php

declare(strict_types=1);

namespace App\Services;

final class DemoDataService
{
    public function fixturesByDate(string $date): array
    {
        $season = (int) date('Y');

        return [
            [
                'fixture' => [
                    'id' => 910001,
                    'date' => $date . 'T19:00:00-03:00',
                    'status' => ['short' => 'NS', 'long' => 'Not Started'],
                ],
                'league' => [
                    'id' => 71,
                    'name' => 'Brasileirao Serie A',
                    'country' => 'Brazil',
                    'logo' => null,
                    'season' => $season,
                ],
                'teams' => [
                    'home' => ['id' => 101, 'name' => 'Palmeiras', 'logo' => null, 'country' => 'Brazil'],
                    'away' => ['id' => 102, 'name' => 'Flamengo', 'logo' => null, 'country' => 'Brazil'],
                ],
                'goals' => ['home' => null, 'away' => null],
                'round' => [
                    'id' => 71012,
                    'name' => '12',
                    'starting_at' => $date,
                    'ending_at' => $date,
                    'is_current' => true,
                ],
            ],
            [
                'fixture' => [
                    'id' => 910002,
                    'date' => $date . 'T21:30:00-03:00',
                    'status' => ['short' => 'NS', 'long' => 'Not Started'],
                ],
                'league' => [
                    'id' => 39,
                    'name' => 'Premier League',
                    'country' => 'England',
                    'logo' => null,
                    'season' => $season,
                ],
                'teams' => [
                    'home' => ['id' => 201, 'name' => 'Liverpool', 'logo' => null, 'country' => 'England'],
                    'away' => ['id' => 202, 'name' => 'Tottenham', 'logo' => null, 'country' => 'England'],
                ],
                'goals' => ['home' => null, 'away' => null],
                'round' => [
                    'id' => 39034,
                    'name' => '34',
                    'starting_at' => $date,
                    'ending_at' => $date,
                    'is_current' => true,
                ],
            ],
            [
                'fixture' => [
                    'id' => 910003,
                    'date' => $date . 'T16:00:00-03:00',
                    'status' => ['short' => 'NS', 'long' => 'Not Started'],
                ],
                'league' => [
                    'id' => 140,
                    'name' => 'La Liga',
                    'country' => 'Spain',
                    'logo' => null,
                    'season' => $season,
                ],
                'teams' => [
                    'home' => ['id' => 301, 'name' => 'Real Sociedad', 'logo' => null, 'country' => 'Spain'],
                    'away' => ['id' => 302, 'name' => 'Villarreal', 'logo' => null, 'country' => 'Spain'],
                ],
                'goals' => ['home' => null, 'away' => null],
                'round' => [
                    'id' => 14029,
                    'name' => '29',
                    'starting_at' => $date,
                    'ending_at' => $date,
                    'is_current' => true,
                ],
            ],
        ];
    }

    public function liveFixtures(string $date): array
    {
        $fixtures = $this->fixturesByDate($date);
        $fixtures[0]['fixture']['status'] = ['short' => '2H', 'long' => 'Second Half'];
        $fixtures[0]['goals'] = ['home' => 1, 'away' => 1];
        $fixtures[0]['participants'] = [
            ['id' => 101, 'name' => 'Palmeiras', 'meta' => ['location' => 'home']],
            ['id' => 102, 'name' => 'Flamengo', 'meta' => ['location' => 'away']],
        ];
        $fixtures[0]['periods'] = [
            [
                'id' => 1,
                'sort_order' => 2,
                'description' => '2nd-half',
                'minutes' => 67,
                'seconds' => 14,
                'time_added' => 4,
                'ticking' => true,
                'has_timer' => true,
            ],
        ];
        $fixtures[0]['events'] = [
            [
                'participant_id' => 101,
                'minute' => 59,
                'extra_minute' => null,
                'player_name' => 'Raphael Veiga',
                'result' => '1-1',
                'addition' => 'Equalizer',
                'type' => ['name' => 'Goal'],
                'sort_order' => 2,
            ],
        ];

        return [$fixtures[0]];
    }

    public function fixtureById(int $fixtureId, ?string $date = null): ?array
    {
        $date = $date ?? date('Y-m-d');
        foreach ($this->fixturesByDate($date) as $fixture) {
            if ((int) $fixture['fixture']['id'] === $fixtureId) {
                return $fixture;
            }
        }

        foreach ($this->fixturesByDate(date('Y-m-d', strtotime('+1 day'))) as $fixture) {
            if ((int) $fixture['fixture']['id'] === $fixtureId) {
                return $fixture;
            }
        }

        return null;
    }

    public function teamStatistics(int $teamId, int $leagueId, int $season): array
    {
        $dataset = [
            101 => [15, 1.8, 0.9, 78, 56, 52, 44, 19],
            102 => [15, 1.9, 1.1, 82, 61, 63, 32, 18],
            201 => [15, 2.2, 1.0, 84, 68, 59, 38, 14],
            202 => [15, 1.7, 1.4, 76, 58, 64, 27, 22],
            301 => [15, 1.5, 1.1, 69, 49, 47, 35, 26],
            302 => [15, 1.6, 1.5, 74, 55, 58, 24, 25],
        ];

        [$played, $for, $against, $over15, $over25, $btts, $clean, $fts] = $dataset[$teamId] ?? [10, 1.3, 1.2, 65, 45, 46, 28, 29];

        return [
            'team' => ['id' => $teamId],
            'league' => ['id' => $leagueId, 'season' => $season],
            'fixtures' => ['played' => ['total' => $played]],
            'goals' => [
                'for' => ['total' => ['total' => $played * $for], 'average' => ['total' => number_format($for, 2, '.', '')]],
                'against' => ['total' => ['total' => $played * $against], 'average' => ['total' => number_format($against, 2, '.', '')]],
            ],
            'derived' => [
                'over_1_5_rate' => $over15,
                'over_2_5_rate' => $over25,
                'btts_rate' => $btts,
                'clean_sheet_rate' => $clean,
                'failed_to_score_rate' => $fts,
            ],
            'form' => ['W', 'W', 'D', 'L', 'W'],
            'last_results' => [
                ['score' => '2-1', 'market' => 'over_1_5'],
                ['score' => '1-1', 'market' => 'btts'],
                ['score' => '3-0', 'market' => 'over_2_5'],
                ['score' => '0-1', 'market' => 'under_2_5'],
                ['score' => '2-0', 'market' => 'over_1_5'],
            ],
        ];
    }

    public function fixtureStatistics(int $fixtureId): array
    {
        return [
            ['team' => ['id' => $fixtureId % 1000], 'statistics' => [['type' => 'Shots on Goal', 'value' => 5], ['type' => 'Ball Possession', 'value' => '54%']]],
            ['team' => ['id' => ($fixtureId % 1000) + 1], 'statistics' => [['type' => 'Shots on Goal', 'value' => 4], ['type' => 'Ball Possession', 'value' => '46%']]],
        ];
    }

    public function fixtureEvents(int $fixtureId): array
    {
        return [
            ['time' => ['elapsed' => 12], 'team' => ['name' => 'Time da casa'], 'type' => 'Goal', 'detail' => 'Open Play'],
            ['time' => ['elapsed' => 33], 'team' => ['name' => 'Time visitante'], 'type' => 'Card', 'detail' => 'Yellow Card'],
        ];
    }

    public function fixtureLineups(int $fixtureId): array
    {
        return [
            ['team' => ['name' => 'Time da casa'], 'formation' => '4-3-3'],
            ['team' => ['name' => 'Time visitante'], 'formation' => '4-2-3-1'],
        ];
    }

    public function fixtureContext(int $fixtureId): array
    {
        $fixture = $this->fixtureById($fixtureId);
        if ($fixture === null) {
            return [];
        }

        $home = $fixture['teams']['home'] ?? ['id' => 0, 'name' => 'Time da casa'];
        $away = $fixture['teams']['away'] ?? ['id' => 0, 'name' => 'Time visitante'];
        $homeGoals = isset($fixture['goals']['home']) ? (int) $fixture['goals']['home'] : 0;
        $awayGoals = isset($fixture['goals']['away']) ? (int) $fixture['goals']['away'] : 0;

        return [
            'summary' => [
                'fixture_id' => $fixtureId,
                'name' => ($home['name'] ?? 'Time da casa') . ' vs ' . ($away['name'] ?? 'Time visitante'),
                'starting_at' => $fixture['fixture']['date'] ?? null,
                'status' => (string) ($fixture['fixture']['status']['short'] ?? 'NS'),
                'status_label' => (string) ($fixture['fixture']['status']['long'] ?? 'Not Started'),
                'result_info' => null,
                'league' => (string) ($fixture['league']['name'] ?? 'Liga'),
                'venue' => [
                    'name' => 'Estadio demo',
                    'city' => null,
                    'capacity' => null,
                ],
                'home_team' => (string) ($home['name'] ?? 'Time da casa'),
                'away_team' => (string) ($away['name'] ?? 'Time visitante'),
            ],
            'scoreboard' => [
                'home' => [
                    'team_id' => (int) ($home['id'] ?? 0),
                    'team' => (string) ($home['name'] ?? 'Time da casa'),
                    'current' => $homeGoals,
                    'first_half' => max(0, $homeGoals - 1),
                    'second_half_only' => min(1, $homeGoals),
                ],
                'away' => [
                    'team_id' => (int) ($away['id'] ?? 0),
                    'team' => (string) ($away['name'] ?? 'Time visitante'),
                    'current' => $awayGoals,
                    'first_half' => max(0, $awayGoals - 1),
                    'second_half_only' => min(1, $awayGoals),
                ],
            ],
            'statistics' => $this->fixtureStatistics($fixtureId),
            'xg' => [
                'home' => [
                    'team_id' => (int) ($home['id'] ?? 0),
                    'team' => (string) ($home['name'] ?? 'Time da casa'),
                    'xg' => 1.42,
                    'xgot' => 1.11,
                    'xpts' => 1.74,
                    'npxg' => 1.42,
                    'xg_open_play' => 1.08,
                    'xga' => 0.96,
                    'xg_diff' => 0.46,
                    'shooting_performance' => 0.61,
                    'xg_prevented' => -0.18,
                ],
                'away' => [
                    'team_id' => (int) ($away['id'] ?? 0),
                    'team' => (string) ($away['name'] ?? 'Time visitante'),
                    'xg' => 0.96,
                    'xgot' => 0.78,
                    'xpts' => 0.94,
                    'npxg' => 0.96,
                    'xg_open_play' => 0.72,
                    'xga' => 1.42,
                    'xg_diff' => -0.46,
                    'shooting_performance' => 0.43,
                    'xg_prevented' => -0.55,
                ],
            ],
            'event_totals' => [
                'home' => [
                    'team_id' => (int) ($home['id'] ?? 0),
                    'team' => (string) ($home['name'] ?? 'Time da casa'),
                    'goals' => $homeGoals,
                    'yellowcards' => 2,
                    'redcards' => 0,
                    'substitutions' => 4,
                    'var' => 0,
                ],
                'away' => [
                    'team_id' => (int) ($away['id'] ?? 0),
                    'team' => (string) ($away['name'] ?? 'Time visitante'),
                    'goals' => $awayGoals,
                    'yellowcards' => 3,
                    'redcards' => 0,
                    'substitutions' => 5,
                    'var' => 1,
                ],
            ],
            'lineup_insights' => [
                'home' => [
                    'team_id' => (int) ($home['id'] ?? 0),
                    'team' => (string) ($home['name'] ?? 'Time da casa'),
                    'formation' => '4-3-3',
                    'starting_xi_count' => 11,
                    'bench_used' => 4,
                    'top_rated_players' => [
                        ['name' => 'Atacante da casa', 'rating' => 7.6, 'minutes' => 90, 'goals' => 1, 'assists' => 0],
                        ['name' => 'Meia da casa', 'rating' => 7.3, 'minutes' => 83, 'goals' => 0, 'assists' => 1],
                    ],
                    'top_xg_players' => [
                        ['name' => 'Atacante da casa', 'xg' => 0.64, 'xgot' => 0.51, 'goals' => 1, 'shots' => 4],
                    ],
                ],
                'away' => [
                    'team_id' => (int) ($away['id'] ?? 0),
                    'team' => (string) ($away['name'] ?? 'Time visitante'),
                    'formation' => '4-2-3-1',
                    'starting_xi_count' => 11,
                    'bench_used' => 5,
                    'top_rated_players' => [
                        ['name' => 'Ponta visitante', 'rating' => 7.2, 'minutes' => 90, 'goals' => 1, 'assists' => 0],
                        ['name' => 'Volante visitante', 'rating' => 6.9, 'minutes' => 90, 'goals' => 0, 'assists' => 0],
                    ],
                    'top_xg_players' => [
                        ['name' => 'Ponta visitante', 'xg' => 0.48, 'xgot' => 0.39, 'goals' => 1, 'shots' => 3],
                    ],
                ],
            ],
            'weather' => [
                'description' => 'Ceu parcialmente limpo',
                'temperature_c' => 27.0,
                'humidity' => 71.0,
                'wind_kph' => 14.0,
            ],
            'sidelined' => [
                [
                    'team_id' => (int) ($home['id'] ?? 0),
                    'team' => (string) ($home['name'] ?? 'Time da casa'),
                    'count' => 2,
                    'players' => [
                        ['name' => 'Lateral da casa', 'reason' => 'Hamstring Injury', 'status' => 'Injured'],
                        ['name' => 'Volante da casa', 'reason' => 'Suspended', 'status' => 'Unavailable'],
                    ],
                ],
                [
                    'team_id' => (int) ($away['id'] ?? 0),
                    'team' => (string) ($away['name'] ?? 'Time visitante'),
                    'count' => 1,
                    'players' => [
                        ['name' => 'Zagueiro visitante', 'reason' => 'Knock', 'status' => 'Doubtful'],
                    ],
                ],
            ],
            'predictions' => [
                [
                    'id' => 1,
                    'type_id' => 100,
                    'market' => 'Fulltime Result Probability',
                    'code' => 'fulltime-result-probability',
                    'probabilities' => [
                        'home' => 48.0,
                        'draw' => 27.0,
                        'away' => 25.0,
                    ],
                ],
                [
                    'id' => 2,
                    'type_id' => 101,
                    'market' => 'Both Teams To Score',
                    'code' => 'both-teams-to-score',
                    'probabilities' => [
                        'yes' => 57.0,
                        'no' => 43.0,
                    ],
                ],
                [
                    'id' => 3,
                    'type_id' => 102,
                    'market' => 'Over 2.5 Goals',
                    'code' => 'over-2-5-goals',
                    'probabilities' => [
                        'over' => 54.0,
                        'under' => 46.0,
                    ],
                ],
            ],
            'featured_players' => [
                $this->demoFeaturedPlayer((int) ($home['id'] ?? 0), (string) ($home['name'] ?? 'Time da casa'), 'home', 'Atacante da casa', 26, 'Brasil', 'Centre Forward', 'right', 7.6, 1, 0, 0.64),
                $this->demoFeaturedPlayer((int) ($away['id'] ?? 0), (string) ($away['name'] ?? 'Time visitante'), 'away', 'Ponta visitante', 24, 'Argentina', 'Inside Forward', 'left', 7.2, 1, 0, 0.48),
            ],
        ];
    }

    public function teamScheduleContext(int $teamId, ?int $fixtureId = null): array
    {
        $teamNames = [
            101 => 'Palmeiras',
            102 => 'Flamengo',
            201 => 'Liverpool',
            202 => 'Tottenham',
            301 => 'Real Sociedad',
            302 => 'Villarreal',
        ];

        $teamName = $teamNames[$teamId] ?? ('Time ' . $teamId);
        $referenceTimestamp = strtotime(date('Y-m-d') . ' 19:00:00') ?: time();

        return [
            'team_id' => $teamId,
            'summary' => [
                'fixtures_count' => 8,
                'completed_fixtures' => 5,
                'upcoming_fixtures' => 3,
                'competitions_count' => 2,
                'recent_form' => ['W', 'D', 'W', 'L', 'W'],
                'matches_last_14_days' => 4,
                'matches_next_14_days' => 2,
                'days_since_previous' => 3,
                'days_until_next' => 4,
            ],
            'reference_fixture' => [
                'fixture_id' => $fixtureId ?? 0,
                'name' => $teamName . ' vs Adversario atual',
                'date' => date('Y-m-d H:i:s', $referenceTimestamp),
                'starting_at' => date('Y-m-d H:i:s', $referenceTimestamp),
                'starting_at_timestamp' => $referenceTimestamp,
                'status' => 'NS',
                'status_label' => 'Scheduled',
                'competition' => 'Liga principal - Temporada regular',
                'stage' => 'Temporada regular',
                'round' => '12',
                'aggregate' => '',
                'location' => 'home',
                'opponent' => [
                    'team_id' => 999001,
                    'name' => 'Adversario atual',
                    'logo' => null,
                ],
                'score' => null,
                'team_goals' => null,
                'opponent_goals' => null,
                'result' => null,
                'result_info' => null,
                'is_finished' => false,
            ],
            'previous_fixture' => $this->demoScheduleFixture($teamName, 'Cuiaba', 'Liga principal - Temporada regular', 'away', strtotime('-3 days 20:00'), '2-1', 'W'),
            'next_fixture' => $this->demoScheduleFixture($teamName, 'Fortaleza', 'Copa nacional - Oitavas', 'home', strtotime('+4 days 21:30'), null, null),
            'recent_fixtures' => [
                $this->demoScheduleFixture($teamName, 'Cuiaba', 'Liga principal - Temporada regular', 'away', strtotime('-3 days 20:00'), '2-1', 'W'),
                $this->demoScheduleFixture($teamName, 'Sao Paulo', 'Liga principal - Temporada regular', 'home', strtotime('-6 days 19:00'), '1-1', 'D'),
                $this->demoScheduleFixture($teamName, 'Cruzeiro', 'Copa nacional - Oitavas', 'away', strtotime('-9 days 21:30'), '3-1', 'W'),
                $this->demoScheduleFixture($teamName, 'Bahia', 'Liga principal - Temporada regular', 'away', strtotime('-12 days 18:30'), '0-1', 'L'),
                $this->demoScheduleFixture($teamName, 'Corinthians', 'Liga principal - Temporada regular', 'home', strtotime('-14 days 20:30'), '2-0', 'W'),
            ],
            'upcoming_fixtures' => [
                $this->demoScheduleFixture($teamName, 'Fortaleza', 'Copa nacional - Oitavas', 'home', strtotime('+4 days 21:30'), null, null),
                $this->demoScheduleFixture($teamName, 'Athletico-PR', 'Liga principal - Temporada regular', 'away', strtotime('+8 days 19:00'), null, null),
                $this->demoScheduleFixture($teamName, 'Bragantino', 'Liga principal - Temporada regular', 'home', strtotime('+12 days 20:00'), null, null),
            ],
            'competition_breakdown' => [
                [
                    'competition' => 'Liga principal - Temporada regular',
                    'fixtures' => 6,
                    'completed' => 4,
                    'upcoming' => 2,
                ],
                [
                    'competition' => 'Copa nacional - Oitavas',
                    'fixtures' => 2,
                    'completed' => 1,
                    'upcoming' => 1,
                ],
            ],
            'alerts' => [
                'Descanso curto antes do jogo: 3 dia(s).',
                'Proximo compromisso em 4 dia(s).',
            ],
        ];
    }

    public function roundOddsPayload(int $roundId): ?array
    {
        $payloads = [
            71012 => [
                'id' => 71012,
                'sport_id' => 1,
                'league_id' => 71,
                'season_id' => (int) date('Y'),
                'stage_id' => 7101201,
                'name' => '12',
                'finished' => false,
                'is_current' => true,
                'starting_at' => date('Y-m-d'),
                'ending_at' => date('Y-m-d'),
                'games_in_current_week' => true,
                'fixtures' => [
                    $this->demoRoundFixture(910001, 71012, 'Palmeiras vs Flamengo', 'Brazil', 'Brasileirao Serie A', [
                        ['id' => 101, 'name' => 'Palmeiras', 'short_code' => 'PAL', 'location' => 'home', 'position' => 1],
                        ['id' => 102, 'name' => 'Flamengo', 'short_code' => 'FLA', 'location' => 'away', 'position' => 3],
                    ], ['home' => 2.25, 'draw' => 3.20, 'away' => 3.10]),
                    $this->demoRoundFixture(910004, 71012, 'Gremio vs Internacional', 'Brazil', 'Brasileirao Serie A', [
                        ['id' => 103, 'name' => 'Gremio', 'short_code' => 'GRE', 'location' => 'home', 'position' => 7],
                        ['id' => 104, 'name' => 'Internacional', 'short_code' => 'INT', 'location' => 'away', 'position' => 5],
                    ], ['home' => 2.80, 'draw' => 3.10, 'away' => 2.60]),
                    $this->demoRoundFixture(910005, 71012, 'Atletico-MG vs Botafogo', 'Brazil', 'Brasileirao Serie A', [
                        ['id' => 105, 'name' => 'Atletico-MG', 'short_code' => 'CAM', 'location' => 'home', 'position' => 2],
                        ['id' => 106, 'name' => 'Botafogo', 'short_code' => 'BOT', 'location' => 'away', 'position' => 8],
                    ], ['home' => 2.05, 'draw' => 3.30, 'away' => 3.70]),
                ],
                'league' => $this->demoLeaguePayload(71, 'Brasileirao Serie A', 'Brazil'),
            ],
            39034 => [
                'id' => 39034,
                'sport_id' => 1,
                'league_id' => 39,
                'season_id' => (int) date('Y'),
                'stage_id' => 3903401,
                'name' => '34',
                'finished' => false,
                'is_current' => true,
                'starting_at' => date('Y-m-d'),
                'ending_at' => date('Y-m-d'),
                'games_in_current_week' => true,
                'fixtures' => [
                    $this->demoRoundFixture(910002, 39034, 'Liverpool vs Tottenham', 'England', 'Premier League', [
                        ['id' => 201, 'name' => 'Liverpool', 'short_code' => 'LIV', 'location' => 'home', 'position' => 2],
                        ['id' => 202, 'name' => 'Tottenham', 'short_code' => 'TOT', 'location' => 'away', 'position' => 6],
                    ], ['home' => 1.82, 'draw' => 3.85, 'away' => 4.10]),
                    $this->demoRoundFixture(910006, 39034, 'Arsenal vs Newcastle', 'England', 'Premier League', [
                        ['id' => 203, 'name' => 'Arsenal', 'short_code' => 'ARS', 'location' => 'home', 'position' => 1],
                        ['id' => 204, 'name' => 'Newcastle', 'short_code' => 'NEW', 'location' => 'away', 'position' => 7],
                    ], ['home' => 1.72, 'draw' => 4.00, 'away' => 4.60]),
                    $this->demoRoundFixture(910007, 39034, 'Chelsea vs Aston Villa', 'England', 'Premier League', [
                        ['id' => 205, 'name' => 'Chelsea', 'short_code' => 'CHE', 'location' => 'home', 'position' => 9],
                        ['id' => 206, 'name' => 'Aston Villa', 'short_code' => 'AVL', 'location' => 'away', 'position' => 4],
                    ], ['home' => 2.45, 'draw' => 3.50, 'away' => 2.85]),
                ],
                'league' => $this->demoLeaguePayload(39, 'Premier League', 'England'),
            ],
            14029 => [
                'id' => 14029,
                'sport_id' => 1,
                'league_id' => 140,
                'season_id' => (int) date('Y'),
                'stage_id' => 1402901,
                'name' => '29',
                'finished' => false,
                'is_current' => true,
                'starting_at' => date('Y-m-d'),
                'ending_at' => date('Y-m-d'),
                'games_in_current_week' => true,
                'fixtures' => [
                    $this->demoRoundFixture(910003, 14029, 'Real Sociedad vs Villarreal', 'Spain', 'La Liga', [
                        ['id' => 301, 'name' => 'Real Sociedad', 'short_code' => 'RSO', 'location' => 'home', 'position' => 8],
                        ['id' => 302, 'name' => 'Villarreal', 'short_code' => 'VIL', 'location' => 'away', 'position' => 5],
                    ], ['home' => 2.52, 'draw' => 3.20, 'away' => 2.92]),
                    $this->demoRoundFixture(910008, 14029, 'Sevilla vs Valencia', 'Spain', 'La Liga', [
                        ['id' => 303, 'name' => 'Sevilla', 'short_code' => 'SEV', 'location' => 'home', 'position' => 11],
                        ['id' => 304, 'name' => 'Valencia', 'short_code' => 'VAL', 'location' => 'away', 'position' => 9],
                    ], ['home' => 2.15, 'draw' => 3.15, 'away' => 3.55]),
                    $this->demoRoundFixture(910009, 14029, 'Betis vs Getafe', 'Spain', 'La Liga', [
                        ['id' => 305, 'name' => 'Betis', 'short_code' => 'BET', 'location' => 'home', 'position' => 6],
                        ['id' => 306, 'name' => 'Getafe', 'short_code' => 'GET', 'location' => 'away', 'position' => 13],
                    ], ['home' => 1.90, 'draw' => 3.25, 'away' => 4.20]),
                ],
                'league' => $this->demoLeaguePayload(140, 'La Liga', 'Spain'),
            ],
        ];

        return $payloads[$roundId] ?? null;
    }

    public function headToHead(int $homeTeamId, int $awayTeamId): array
    {
        return [
            ['score' => '2-1', 'winner' => $homeTeamId],
            ['score' => '1-1', 'winner' => null],
            ['score' => '0-2', 'winner' => $awayTeamId],
        ];
    }

    private function demoLeaguePayload(int $leagueId, string $leagueName, string $countryName): array
    {
        return [
            'id' => $leagueId,
            'sport_id' => 1,
            'country_id' => 1,
            'name' => $leagueName,
            'active' => true,
            'country' => [
                'id' => 1,
                'name' => $countryName,
            ],
        ];
    }

    private function demoScheduleFixture(string $teamName, string $opponentName, string $competition, string $location, int|false $timestamp, ?string $score, ?string $result): array
    {
        $validTimestamp = is_int($timestamp) ? $timestamp : time();
        $baseId = abs(crc32($teamName . '|' . $opponentName . '|' . $competition . '|' . $location . '|' . $validTimestamp));
        [$teamGoals, $opponentGoals] = $score !== null && str_contains($score, '-')
            ? array_map('intval', explode('-', $score, 2))
            : [null, null];

        return [
            'fixture_id' => 100000 + ($baseId % 900000),
            'name' => $location === 'home' ? ($teamName . ' vs ' . $opponentName) : ($opponentName . ' vs ' . $teamName),
            'date' => date('Y-m-d H:i:s', $validTimestamp),
            'starting_at' => date('Y-m-d H:i:s', $validTimestamp),
            'starting_at_timestamp' => $validTimestamp,
            'status' => $result !== null ? 'FT' : 'NS',
            'status_label' => $result !== null ? 'Finished' : 'Scheduled',
            'competition' => $competition,
            'stage' => '',
            'round' => '',
            'aggregate' => '',
            'location' => $location,
            'opponent' => [
                'team_id' => 1000 + ($baseId % 8000),
                'name' => $opponentName,
                'logo' => null,
            ],
            'score' => $score,
            'team_goals' => $teamGoals,
            'opponent_goals' => $opponentGoals,
            'result' => $result,
            'result_info' => null,
            'is_finished' => $result !== null,
        ];
    }

    private function demoFeaturedPlayer(
        int $teamId,
        string $teamName,
        string $location,
        string $playerName,
        int $age,
        string $nationality,
        string $position,
        string $preferredFoot,
        float $rating,
        int $goals,
        int $assists,
        float $xg
    ): array {
        $baseId = abs(crc32($teamName . '|' . $playerName));

        return [
            'player_id' => 500000 + ($baseId % 400000),
            'name' => $playerName,
            'age' => $age,
            'nationality' => $nationality,
            'position' => $position,
            'preferred_foot' => $preferredFoot,
            'height_cm' => 180,
            'weight_kg' => 74,
            'current_team' => [
                'team_id' => $teamId,
                'team' => $teamName,
                'location' => $location,
                'type' => 'domestic',
                'is_active' => true,
            ],
            'career' => [
                'total' => 7,
                'won' => 4,
                'runner_up' => 2,
                'competitions' => 5,
                'recent' => [
                    ['competition' => 'Liga principal', 'season' => '2025/2026', 'result' => 'Winner', 'team' => $teamName],
                ],
            ],
            'season_snapshot' => [
                'team' => $teamName,
                'league' => 'Liga principal',
                'season' => '2025/2026',
                'is_current' => true,
                'appearances' => 27,
                'goals' => $goals + 11,
                'assists' => $assists + 6,
                'minutes' => 2140,
                'rating' => $rating,
                'shots' => 68,
                'shots_on_target' => 29,
            ],
            'latest_match' => [
                'match' => $teamName . ' vs Rival recente',
                'league' => 'Liga principal',
                'score' => '2-1',
                'rating' => $rating,
                'minutes' => 90,
                'goals' => $goals,
                'assists' => $assists,
            ],
            'match_focus' => [
                'team_id' => $teamId,
                'team' => $teamName,
                'location' => $location,
                'rating' => $rating,
                'goals' => $goals,
                'assists' => $assists,
                'shots' => 4,
                'minutes' => 90,
                'xg' => $xg,
            ],
        ];
    }

    private function demoRoundFixture(int $fixtureId, int $roundId, string $name, string $countryName, string $leagueName, array $participants, array $odds): array
    {
        return [
            'id' => $fixtureId,
            'sport_id' => 1,
            'league_id' => match ($leagueName) {
                'Brasileirao Serie A' => 71,
                'Premier League' => 39,
                default => 140,
            },
            'season_id' => (int) date('Y'),
            'stage_id' => $roundId * 10,
            'round_id' => $roundId,
            'state_id' => 1,
            'name' => $name,
            'starting_at' => date('Y-m-d') . ' 19:00:00',
            'participants' => array_map(function (array $participant) use ($countryName): array {
                return [
                    'id' => (int) $participant['id'],
                    'name' => (string) $participant['name'],
                    'short_code' => (string) ($participant['short_code'] ?? ''),
                    'country_id' => 1,
                    'meta' => [
                        'location' => (string) $participant['location'],
                        'position' => (int) $participant['position'],
                    ],
                    'image_path' => null,
                    'country' => $countryName,
                ];
            }, $participants),
            'odds' => $this->demoFulltimeOddsRows($fixtureId, $odds),
        ];
    }

    private function demoFulltimeOddsRows(int $fixtureId, array $odds): array
    {
        $mapping = [
            'home' => ['label' => 'Home', 'original_label' => '1', 'sort_order' => 0],
            'draw' => ['label' => 'Draw', 'original_label' => 'Draw', 'sort_order' => 1],
            'away' => ['label' => 'Away', 'original_label' => '2', 'sort_order' => 2],
        ];

        $rows = [];

        foreach ($mapping as $side => $meta) {
            $decimal = (float) ($odds[$side] ?? 0);
            if ($decimal <= 0) {
                continue;
            }

            $rows[] = [
                'id' => ($fixtureId * 10) + $meta['sort_order'],
                'fixture_id' => $fixtureId,
                'market_id' => 1,
                'bookmaker_id' => 2,
                'label' => $meta['label'],
                'value' => number_format($decimal, 2, '.', ''),
                'sort_order' => $meta['sort_order'],
                'probability' => number_format(100 / $decimal, 2, '.', '') . '%',
                'dp3' => number_format($decimal, 3, '.', ''),
                'fractional' => null,
                'american' => null,
                'winning' => false,
                'stopped' => false,
                'original_label' => $meta['original_label'],
                'latest_bookmaker_update' => date('Y-m-d H:i:s'),
                'market' => [
                    'id' => 1,
                    'name' => 'Fulltime Result',
                    'developer_name' => 'FULLTIME_RESULT',
                ],
                'bookmaker' => [
                    'id' => 2,
                    'name' => 'bet365',
                ],
            ];
        }

        return $rows;
    }
}
