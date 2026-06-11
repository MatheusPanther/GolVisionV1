<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LogRepository;
use RuntimeException;

final class ApiFootballService
{
    private const DEFAULT_BASE_URL = 'https://api.sportmonks.com/v3/football';
    private const FIXTURE_INCLUDES = 'participants;league;league.country;state;scores;venue';
    private const FIXTURE_CONTEXT_INCLUDES = 'participants;league;league.country;venue;state;scores;events.type;events.period;events.player;statistics.type;sidelined.sideline.player;sidelined.sideline.type;weatherReport;predictions.type;xGFixture.type;lineups.player;lineups.xGlineup.type;lineups.details.type';
    private const FIXTURE_CONTEXT_TTL = 300;
    private const FIXTURE_PREDICTIONS_INCLUDE = 'type';
    private const FIXTURE_PREDICTIONS_TTL = 900;
    private const TEAM_SCHEDULE_INCLUDE = 'league;league.country';
    private const TEAM_SCHEDULE_TTL = 900;
    private const LIVE_INCLUDES = 'participants;scores;periods;events;league.country;round;state';
    private const HEAD_TO_HEAD_INCLUDES = 'participants;league;league.country;state;scores;venue;events';
    private const FIXTURE_ODDS_INCLUDES = 'participants;odds.market;odds.bookmaker';
    private const ROUND_ODDS_INCLUDE = 'fixtures.odds.market;fixtures.odds.bookmaker;fixtures.participants;league.country';
    private const ROUND_ODDS_FILTERS = 'markets:1;bookmakers:2';
    private const ROUND_ODDS_TTL = 1800;
    private const SEASON_TEAM_STATS_INCLUDE = 'statistics.details.type';
    private const SEASON_TEAM_STATS_TTL = 3600;
    private const PLAYER_PROFILE_INCLUDE = 'trophies.league;trophies.season;trophies.trophy;trophies.team;teams.team;statistics.details.type;statistics.team;statistics.season.league;latest.fixture.participants;latest.fixture.league;latest.fixture.scores;latest.details.type;nationality;detailedPosition;metadata.type';
    private const PLAYER_PROFILE_TTL = 21600;
    private const MAX_FEATURED_PLAYERS = 4;

    private CacheService $cache;
    private DemoDataService $demo;
    private LogRepository $logs;
    private ?string $lastApiErrorMessage = null;
    private bool $lastFallbackUsed = false;

    public function __construct()
    {
        $this->cache = new CacheService();
        $this->demo = new DemoDataService();
        $this->logs = new LogRepository();
    }

    public function fetchFixturesByDate(string $date): array
    {
        $this->resetRequestState();

        return $this->cache->remember('fixtures_' . $date, 600, function () use ($date): array {
            $fixtures = $this->requestPaginatedCollection('/fixtures/date/' . rawurlencode($date), [
                'include' => self::FIXTURE_INCLUDES,
                'per_page' => 50,
            ]);

            if ($fixtures === null) {
                $this->lastFallbackUsed = true;
                $fixtures = $this->demo->fixturesByDate($date);
            }

            return array_map(fn (array $fixture): array => $this->normalizeFixture($fixture), $fixtures);
        });
    }

    public function fetchLiveFixtures(): array
    {
        $this->resetRequestState();

        return $this->cache->remember('fixtures_live', 300, function (): array {
            $fixtures = $this->requestPaginatedCollection('/livescores/inplay', [
                'include' => self::LIVE_INCLUDES,
                'per_page' => 50,
            ]);

            if ($fixtures === null) {
                $this->lastFallbackUsed = true;
                $fixtures = $this->demo->liveFixtures(date('Y-m-d'));
            }

            return array_map(fn (array $fixture): array => $this->normalizeFixture($fixture), $fixtures);
        });
    }

    public function lastApiErrorMessage(): ?string
    {
        return $this->lastApiErrorMessage;
    }

    public function usedFallbackData(): bool
    {
        return $this->lastFallbackUsed;
    }

    public function fetchFixtureById(int $fixtureId): ?array
    {
        $fixture = $this->requestItem('/fixtures/' . $fixtureId, [
            'include' => self::FIXTURE_INCLUDES,
        ]);

        if ($fixture === null) {
            $fixture = $this->demo->fixtureById($fixtureId);
        }

        return $fixture !== null ? $this->normalizeFixture($fixture) : null;
    }

    public function fetchFixtureStatistics(int $fixtureId): array
    {
        $fixture = $this->fetchDetailedFixturePayload($fixtureId);
        if ($fixture !== null && is_array($fixture['statistics'] ?? null)) {
            return $this->normalizeFixtureStatistics($fixture);
        }

        $fixture = $this->requestItem('/fixtures/' . $fixtureId, [
            'include' => 'statistics.type;statistics.participant',
        ]);

        if ($fixture === null) {
            return $this->demo->fixtureStatistics($fixtureId);
        }

        return $this->normalizeFixtureStatistics($fixture);
    }

    public function fetchFixtureEvents(int $fixtureId): array
    {
        $fixture = $this->fetchDetailedFixturePayload($fixtureId);
        if ($fixture !== null) {
            return $this->normalizeFixtureEvents($fixture);
        }

        $fixture = $this->requestItem('/fixtures/' . $fixtureId, [
            'include' => 'events.type;events.participant',
        ]);

        if ($fixture === null) {
            return $this->demo->fixtureEvents($fixtureId);
        }

        return $this->normalizeFixtureEvents($fixture);
    }

    public function fetchFixtureLineups(int $fixtureId): array
    {
        $fixture = $this->fetchDetailedFixturePayload($fixtureId);
        if ($fixture !== null) {
            $lineups = $this->normalizeFixtureFormations($fixture);
            if ($lineups !== []) {
                return $lineups;
            }
        }

        $fixture = $this->requestItem('/fixtures/' . $fixtureId, [
            'include' => 'formations.participant;participants',
        ]);

        if ($fixture === null) {
            return $this->demo->fixtureLineups($fixtureId);
        }

        return $this->normalizeFixtureFormations($fixture);
    }

    public function fetchFixtureContext(int $fixtureId): array
    {
        $fixture = $this->fetchDetailedFixturePayload($fixtureId);

        if ($fixture === null) {
            return $this->demo->fixtureContext($fixtureId);
        }

        return $this->normalizeFixtureContext($fixture);
    }

    public function fetchTeamStatistics(int $teamId, int $leagueId, int $season): array
    {
        $seasonTeams = [];
        $statisticsPayload = $this->requestItem('/teams/' . $teamId, [
            'include' => self::SEASON_TEAM_STATS_INCLUDE,
            'filters' => 'teamstatisticSeasons:' . $season,
        ]);

        if ($statisticsPayload === null) {
            $seasonTeams = $this->fetchSeasonTeamsStatistics($season) ?? [];
            $statisticsPayload = $this->findSeasonTeamPayload($seasonTeams, $teamId);
        }

        if ($statisticsPayload === null) {
            return $this->normalizeLegacyTeamStats($this->demo->teamStatistics($teamId, $leagueId, $season), $teamId, $leagueId, $season);
        }

        $latestPayload = $this->fetchTeamLatestPayload($teamId);

        return $this->normalizeSportmonksTeamStats($statisticsPayload, $latestPayload, $teamId, $leagueId, $season, $seasonTeams);
    }

    public function fetchTeamScheduleContext(int $teamId, int $referenceFixtureId = 0): array
    {
        if ($teamId <= 0) {
            return [];
        }

        $schedules = $this->fetchTeamSchedulePayload($teamId);

        if ($schedules === null) {
            return $this->demo->teamScheduleContext($teamId, $referenceFixtureId);
        }

        return $this->normalizeTeamScheduleContext($schedules, $teamId, $referenceFixtureId);
    }

    public function fetchHeadToHead(int $homeTeamId, int $awayTeamId): array
    {
        return $this->cache->remember('head_to_head_' . $homeTeamId . '_' . $awayTeamId, 3600, function () use ($homeTeamId, $awayTeamId): array {
            $fixtures = $this->requestPaginatedCollection('/fixtures/head-to-head/' . $homeTeamId . '/' . $awayTeamId, [
                'include' => self::HEAD_TO_HEAD_INCLUDES,
                'per_page' => 8,
            ], 1);

            if ($fixtures === null) {
                return $this->demo->headToHead($homeTeamId, $awayTeamId);
            }

            return array_map(function (array $fixture): array {
                $normalized = $this->normalizeFixture($fixture);

                return [
                    'fixture_id' => $normalized['fixture_id'],
                    'date' => $normalized['date'],
                    'status' => $normalized['status'],
                    'league' => $normalized['league']['name'],
                    'home_team' => $normalized['home_team']['name'],
                    'away_team' => $normalized['away_team']['name'],
                    'score' => $this->formatScoreline($normalized['home_score'], $normalized['away_score']),
                    'raw' => $normalized['raw'],
                ];
            }, $fixtures);
        });
    }

    public function fetchOddsByFixture(int $fixtureId): array
    {
        $fixture = $this->fetchFixtureOddsSnapshot($fixtureId);

        return is_array($fixture) && is_array($fixture['odds'] ?? null) ? $fixture['odds'] : [];
    }

    public function fetchRoundOddsContext(int $roundId, int $fixtureId): array
    {
        if ($fixtureId <= 0) {
            return [];
        }

        if ($roundId > 0) {
            $roundPayload = $this->fetchRoundOddsPayload($roundId);
            if ($roundPayload !== null) {
                $context = $this->normalizeRoundOddsContext($roundPayload, $fixtureId);
                if ($context !== []) {
                    return $context;
                }
            }
        }

        $fixtureSnapshot = $this->fetchFixtureOddsSnapshot($fixtureId);
        $fixturePayload = is_array($fixtureSnapshot) ? $fixtureSnapshot : [];
        $fixtureOdds = $this->normalizeThreeWayOdds($fixturePayload['odds'] ?? []);
        if ($fixtureOdds === []) {
            return [];
        }

        return [
            'round_id' => $roundId,
            'source' => 'fixture_odds_fallback',
            'market' => [
                'id' => 1,
                'name' => 'Fulltime Result',
                'developer_name' => 'FULLTIME_RESULT',
                'bookmaker' => 'bet365',
            ],
            'current_fixture' => $this->buildFixtureOddsSummaryFromOutcomes(
                $fixtureId,
                is_array($fixturePayload['participants'] ?? null) ? $fixturePayload['participants'] : [],
                $fixtureOdds,
                $fixturePayload
            ),
        ];
    }

    public function normalizeFixture(array $fixture): array
    {
        if (isset($fixture['fixture'])) {
            return $this->normalizeLegacyFixture($fixture);
        }

        $homeParticipant = $this->resolveParticipantByLocation($fixture['participants'] ?? [], 'home');
        $awayParticipant = $this->resolveParticipantByLocation($fixture['participants'] ?? [], 'away');
        $scoreline = $this->extractCurrentGoals($fixture['scores'] ?? []);
        $leagueCountry = (string) ($fixture['league']['country']['name'] ?? 'N/A');
        $statusShort = (string) ($fixture['state']['short_name'] ?? $fixture['state']['state'] ?? 'NS');
        $statusLong = (string) ($fixture['state']['name'] ?? $statusShort);
        $participants = $this->participantsById($fixture['participants'] ?? []);

        return [
            'fixture_id' => (int) ($fixture['id'] ?? 0),
            'date' => date('Y-m-d H:i:s', strtotime((string) ($fixture['starting_at'] ?? 'now'))),
            'status' => $statusShort,
            'home_score' => $scoreline['home'],
            'away_score' => $scoreline['away'],
            'league' => [
                'external_id' => (int) ($fixture['league']['id'] ?? 0),
                'name' => (string) ($fixture['league']['name'] ?? 'Liga'),
                'country' => $leagueCountry,
                'logo' => $fixture['league']['image_path'] ?? null,
                'season' => (int) ($fixture['season_id'] ?? date('Y')),
                'enabled' => 1,
            ],
            'home_team' => $this->normalizeParticipantTeam($homeParticipant, $leagueCountry, 'Mandante'),
            'away_team' => $this->normalizeParticipantTeam($awayParticipant, $leagueCountry, 'Visitante'),
            'round' => $this->normalizeRound($fixture['round'] ?? null),
            'live_context' => $this->extractFixtureLiveContext($fixture, $statusShort),
            'live_events' => $this->extractFixtureKeyEvents($fixture['events'] ?? [], $participants),
            'raw' => $this->buildSportmonksFixtureRaw($fixture, $homeParticipant, $awayParticipant, $scoreline, $statusShort, $statusLong, $leagueCountry),
        ];
    }

    public function normalizeTeamStats(array $payload, int $teamId, int $leagueId, int $season): array
    {
        return $this->normalizeLegacyTeamStats($payload, $teamId, $leagueId, $season);
    }

    public function calculateGoalTrends(array $homeStats, array $awayStats): array
    {
        $over15 = min(96, max(24, (int) round(($homeStats['over_1_5_rate'] + $awayStats['over_1_5_rate']) / 2)));
        $over25 = min(92, max(12, (int) round(($homeStats['over_2_5_rate'] + $awayStats['over_2_5_rate']) / 2)));
        $btts = min(90, max(10, (int) round(($homeStats['btts_rate'] + $awayStats['btts_rate']) / 2)));

        return [
            'projected_total_goals' => round(($homeStats['goals_for_avg'] + $awayStats['goals_for_avg'] + $homeStats['goals_against_avg'] + $awayStats['goals_against_avg']) / 2, 2),
            'over_1_5_probability' => $over15,
            'over_2_5_probability' => $over25,
            'btts_probability' => $btts,
        ];
    }

    public function calculateRiskScore(array $trends, array $context = []): array
    {
        $dispersion = abs(($context['home_form_score'] ?? 60) - ($context['away_form_score'] ?? 60));
        $base = 100 - (int) round(($trends['over_1_5_probability'] + $trends['over_2_5_probability'] + $trends['btts_probability']) / 3);
        $riskScore = min(100, max(5, $base + (int) round($dispersion / 4)));

        if ($riskScore <= 34) {
            return ['score' => $riskScore, 'level' => 'low'];
        }

        if ($riskScore <= 64) {
            return ['score' => $riskScore, 'level' => 'medium'];
        }

        return ['score' => $riskScore, 'level' => 'high'];
    }

    private function normalizeLegacyFixture(array $fixture): array
    {
        $fixtureData = $fixture['fixture'] ?? [];
        $league = $fixture['league'] ?? [];
        $teams = $fixture['teams'] ?? [];
        $goals = $fixture['goals'] ?? [];
        $statusShort = (string) ($fixtureData['status']['short'] ?? $fixtureData['status']['long'] ?? 'NS');

        return [
            'fixture_id' => (int) ($fixtureData['id'] ?? 0),
            'date' => date('Y-m-d H:i:s', strtotime((string) ($fixtureData['date'] ?? 'now'))),
            'status' => $statusShort,
            'home_score' => isset($goals['home']) ? (int) $goals['home'] : null,
            'away_score' => isset($goals['away']) ? (int) $goals['away'] : null,
            'league' => [
                'external_id' => (int) ($league['id'] ?? 0),
                'name' => (string) ($league['name'] ?? 'Liga'),
                'country' => (string) ($league['country'] ?? 'N/A'),
                'logo' => $league['logo'] ?? null,
                'season' => (int) ($league['season'] ?? date('Y')),
                'enabled' => 1,
            ],
            'home_team' => [
                'external_id' => (int) ($teams['home']['id'] ?? 0),
                'name' => (string) ($teams['home']['name'] ?? 'Mandante'),
                'logo' => $teams['home']['logo'] ?? null,
                'country' => (string) ($teams['home']['country'] ?? $league['country'] ?? 'N/A'),
            ],
            'away_team' => [
                'external_id' => (int) ($teams['away']['id'] ?? 0),
                'name' => (string) ($teams['away']['name'] ?? 'Visitante'),
                'logo' => $teams['away']['logo'] ?? null,
                'country' => (string) ($teams['away']['country'] ?? $league['country'] ?? 'N/A'),
            ],
            'round' => $this->normalizeRound($fixture['round'] ?? null),
            'live_context' => $this->extractFixtureLiveContext($fixture, $statusShort),
            'live_events' => $this->extractFixtureKeyEvents($fixture['events'] ?? [], []),
            'raw' => $fixture,
        ];
    }

    private function normalizeLegacyTeamStats(array $payload, int $teamId, int $leagueId, int $season): array
    {
        $derived = $payload['derived'] ?? [];

        return [
            'team_id' => $teamId,
            'league_id' => $leagueId,
            'season' => $season,
            'matches_played' => (int) ($payload['fixtures']['played']['total'] ?? 0),
            'goals_for_avg' => (float) ($payload['goals']['for']['average']['total'] ?? 0),
            'goals_against_avg' => (float) ($payload['goals']['against']['average']['total'] ?? 0),
            'over_1_5_rate' => (float) ($derived['over_1_5_rate'] ?? 0),
            'over_2_5_rate' => (float) ($derived['over_2_5_rate'] ?? 0),
            'btts_rate' => (float) ($derived['btts_rate'] ?? 0),
            'clean_sheet_rate' => (float) ($derived['clean_sheet_rate'] ?? 0),
            'failed_to_score_rate' => (float) ($derived['failed_to_score_rate'] ?? 0),
            'raw' => $payload,
        ];
    }

    private function normalizeSportmonksTeamStats(
        array $teamPayload,
        ?array $latestPayload,
        int $teamId,
        int $leagueId,
        int $season,
        array $seasonTeams = []
    ): array
    {
        $statistics = is_array($teamPayload['statistics'] ?? null) ? $teamPayload['statistics'] : [];
        $statistic = $statistics[0] ?? [];
        $details = is_array($statistic['details'] ?? null) ? $statistic['details'] : [];
        $lookup = $this->mapStatisticDetailsByType($details);
        $metricSnapshot = $this->extractTeamMetricSnapshot($lookup);

        $recentFixtures = $this->extractRecentFixtures($latestPayload['latest'] ?? []);
        $recentRates = $this->calculateRecentRates($recentFixtures, $teamId);

        $matchesPlayed = (int) ($metricSnapshot['matches_played'] ?? 0);
        if ($matchesPlayed === 0) {
            $matchesPlayed = count($recentFixtures);
        }

        $goalsForAvg = (float) ($metricSnapshot['goals_for_avg'] ?? 0);
        $goalsAgainstAvg = (float) ($metricSnapshot['goals_against_avg'] ?? 0);
        $advancedMetrics = $this->buildAdvancedMetrics($lookup, $metricSnapshot);
        $seasonComparison = $this->buildSeasonComparison($teamPayload, $seasonTeams, $season, $metricSnapshot);

        return [
            'team_id' => $teamId,
            'league_id' => $leagueId,
            'season' => $season,
            'matches_played' => $matchesPlayed,
            'goals_for_avg' => $goalsForAvg,
            'goals_against_avg' => $goalsAgainstAvg,
            'over_1_5_rate' => (float) ($metricSnapshot['over_1_5_rate'] ?? $recentRates['over_1_5_rate']),
            'over_2_5_rate' => (float) ($metricSnapshot['over_2_5_rate'] ?? $recentRates['over_2_5_rate']),
            'btts_rate' => $this->extractStatPercentage($lookup['BTTS'] ?? null, $recentRates['btts_rate']),
            'clean_sheet_rate' => $this->extractStatPercentage($lookup['CLEANSHEET'] ?? null, $recentRates['clean_sheet_rate']),
            'failed_to_score_rate' => $this->extractStatPercentage($lookup['FAILED_TO_SCORE'] ?? null, $recentRates['failed_to_score_rate']),
            'raw' => [
                'team' => $teamPayload,
                'statistics' => $statistic,
                'form' => $recentRates['form'],
                'recent_fixtures' => $recentFixtures,
                'advanced_metrics' => $advancedMetrics,
                'season_comparison' => $seasonComparison,
            ],
        ];
    }

    private function fetchSeasonTeamsStatistics(int $season): ?array
    {
        return $this->cache->remember('sportmonks_season_teams_' . $season, self::SEASON_TEAM_STATS_TTL, function () use ($season): ?array {
            return $this->requestPaginatedCollection('/teams/seasons/' . $season, [
                'include' => self::SEASON_TEAM_STATS_INCLUDE,
                'filters' => 'teamstatisticSeasons:' . $season,
                'per_page' => 50,
            ]);
        });
    }

    private function fetchFixtureOddsSnapshot(int $fixtureId): ?array
    {
        return $this->requestItem('/fixtures/' . $fixtureId, [
            'include' => self::FIXTURE_ODDS_INCLUDES,
        ]);
    }

    private function fetchRoundOddsPayload(int $roundId): ?array
    {
        return $this->cache->remember('sportmonks_round_odds_' . $roundId, self::ROUND_ODDS_TTL, function () use ($roundId): ?array {
            $payload = $this->requestItem('/rounds/' . $roundId, [
                'include' => self::ROUND_ODDS_INCLUDE,
                'filters' => self::ROUND_ODDS_FILTERS,
            ]);

            if ($payload !== null) {
                return $payload;
            }

            $payload = $this->requestItem('/rounds/' . $roundId, [
                'include' => self::ROUND_ODDS_INCLUDE,
            ]);

            return $payload ?? $this->demo->roundOddsPayload($roundId);
        });
    }

    private function fetchDetailedFixturePayload(int $fixtureId): ?array
    {
        return $this->cache->remember('sportmonks_fixture_context_' . $fixtureId, self::FIXTURE_CONTEXT_TTL, function () use ($fixtureId): ?array {
            return $this->requestItem('/fixtures/' . $fixtureId, [
                'include' => self::FIXTURE_CONTEXT_INCLUDES,
            ]);
        });
    }

    private function fetchTeamSchedulePayload(int $teamId): ?array
    {
        return $this->cache->remember('sportmonks_team_schedule_' . $teamId, self::TEAM_SCHEDULE_TTL, function () use ($teamId): ?array {
            $payload = $this->requestPaginatedCollection('/schedules/teams/' . $teamId, [
                'include' => self::TEAM_SCHEDULE_INCLUDE,
                'per_page' => 12,
            ], 1);

            if ($payload !== null) {
                return $payload;
            }

            return $this->requestPaginatedCollection('/schedules/teams/' . $teamId, ['per_page' => 12], 1);
        });
    }

    private function fetchTeamLatestPayload(int $teamId): ?array
    {
        return $this->cache->remember('sportmonks_team_latest_' . $teamId, 900, function () use ($teamId): ?array {
            return $this->requestItem('/teams/' . $teamId, [
                'include' => 'latest.participants;latest.scores;latest.state',
            ]);
        });
    }

    private function fetchFixturePredictionsPayload(int $fixtureId): ?array
    {
        return $this->cache->remember('sportmonks_fixture_predictions_' . $fixtureId, self::FIXTURE_PREDICTIONS_TTL, function () use ($fixtureId): ?array {
            return $this->requestPaginatedCollection('/predictions/probabilities/fixtures/' . $fixtureId, [
                'include' => self::FIXTURE_PREDICTIONS_INCLUDE,
                'per_page' => 50,
            ]);
        });
    }

    private function fetchPlayerProfilePayload(int $playerId): ?array
    {
        return $this->cache->remember('sportmonks_player_profile_' . $playerId, self::PLAYER_PROFILE_TTL, function () use ($playerId): ?array {
            return $this->requestItem('/players/' . $playerId, [
                'include' => self::PLAYER_PROFILE_INCLUDE,
            ]);
        });
    }

    private function findSeasonTeamPayload(array $seasonTeams, int $teamId): ?array
    {
        foreach ($seasonTeams as $seasonTeam) {
            if (!is_array($seasonTeam)) {
                continue;
            }

            if ((int) ($seasonTeam['id'] ?? 0) === $teamId) {
                return $seasonTeam;
            }
        }

        return null;
    }

    private function normalizeFixtureStatistics(array $fixture): array
    {
        $grouped = [];
        $participants = $this->participantsById($fixture['participants'] ?? []);

        foreach (($fixture['statistics'] ?? []) as $statistic) {
            if (!is_array($statistic)) {
                continue;
            }

            $participantId = (int) ($statistic['participant_id'] ?? 0);
            $participantName = (string) ($statistic['participant']['name'] ?? ($participants[$participantId]['name'] ?? ($statistic['location'] ?? 'Time')));

            if (!isset($grouped[$participantId])) {
                $grouped[$participantId] = [
                    'team' => [
                        'id' => $participantId,
                        'name' => $participantName,
                    ],
                    'statistics' => [],
                ];
            }

            $grouped[$participantId]['statistics'][] = [
                'type' => (string) ($statistic['type']['name'] ?? $statistic['type']['developer_name'] ?? 'Statistic'),
                'value' => $statistic['data']['value'] ?? null,
            ];
        }

        return array_values($grouped);
    }

    private function normalizeFixtureContext(array $fixture): array
    {
        $participants = $this->participantsById($fixture['participants'] ?? []);
        $homeParticipant = $this->resolveParticipantByLocation($fixture['participants'] ?? [], 'home');
        $awayParticipant = $this->resolveParticipantByLocation($fixture['participants'] ?? [], 'away');
        $scores = is_array($fixture['scores'] ?? null) ? $fixture['scores'] : [];
        $events = is_array($fixture['events'] ?? null) ? $fixture['events'] : [];
        $lineups = is_array($fixture['lineups'] ?? null) ? $fixture['lineups'] : [];
        $xgRows = is_array($fixture['xgfixture'] ?? null)
            ? $fixture['xgfixture']
            : (is_array($fixture['xGFixture'] ?? null) ? $fixture['xGFixture'] : []);
        $scoreboard = $this->buildFixtureScoreboard($scores, $homeParticipant, $awayParticipant);
        $predictions = $this->extractFixturePredictions($fixture);

        if ($predictions === []) {
            $predictionRows = $this->fetchFixturePredictionsPayload((int) ($fixture['id'] ?? 0)) ?? [];
            $predictions = $this->normalizeFixturePredictionRows($predictionRows);
        }

        return [
            'summary' => [
                'fixture_id' => (int) ($fixture['id'] ?? 0),
                'name' => (string) ($fixture['name'] ?? ''),
                'starting_at' => $fixture['starting_at'] ?? null,
                'status' => (string) ($fixture['state']['short_name'] ?? $fixture['state']['state'] ?? 'NS'),
                'status_label' => (string) ($fixture['state']['name'] ?? ''),
                'result_info' => $this->toStringOrNull($fixture['result_info'] ?? null),
                'league' => (string) ($fixture['league']['name'] ?? 'Liga'),
                'venue' => [
                    'name' => (string) ($fixture['venue']['name'] ?? 'N/A'),
                    'city' => $this->toStringOrNull($fixture['venue']['city_name'] ?? null),
                    'capacity' => $this->toNullableInt($fixture['venue']['capacity'] ?? null),
                ],
                'home_team' => (string) ($homeParticipant['name'] ?? 'Mandante'),
                'away_team' => (string) ($awayParticipant['name'] ?? 'Visitante'),
            ],
            'scoreboard' => $scoreboard,
            'statistics' => $this->normalizeFixtureStatistics($fixture),
            'xg' => $this->extractFixtureXgSummary($xgRows, $participants),
            'event_totals' => $this->extractFixtureEventTotals($events, $participants, $scoreboard),
            'lineup_insights' => $this->extractFixtureLineupInsights($lineups, $participants),
            'weather' => $this->extractFixtureWeather($fixture),
            'sidelined' => $this->extractFixtureSidelined($fixture, $participants),
            'predictions' => $predictions,
            'featured_players' => $this->extractFeaturedPlayerProfiles($lineups, $events, $participants),
        ];
    }

    private function normalizeTeamScheduleContext(array $schedules, int $teamId, int $referenceFixtureId): array
    {
        $normalizedFixtures = [];

        foreach ($this->flattenTeamScheduleFixtures($schedules) as $fixture) {
            $normalized = $this->normalizeScheduledFixture($fixture, $teamId);
            if ($normalized !== null) {
                $normalizedFixtures[] = $normalized;
            }
        }

        if ($normalizedFixtures === []) {
            return [];
        }

        usort($normalizedFixtures, fn (array $left, array $right): int => (($left['starting_at_timestamp'] ?? 0) <=> ($right['starting_at_timestamp'] ?? 0)));

        $referenceFixture = $this->findReferenceScheduleFixture($normalizedFixtures, $referenceFixtureId);
        $referenceTimestamp = (int) ($referenceFixture['starting_at_timestamp'] ?? time());
        $completedFixtures = array_values(array_filter($normalizedFixtures, static fn (array $fixture): bool => (bool) ($fixture['is_finished'] ?? false)));
        $upcomingFixtures = array_values(array_filter($normalizedFixtures, static fn (array $fixture): bool => !(bool) ($fixture['is_finished'] ?? false)));
        $displayCompletedFixtures = $referenceFixtureId > 0
            ? array_values(array_filter($completedFixtures, static fn (array $fixture): bool => (int) ($fixture['fixture_id'] ?? 0) !== $referenceFixtureId))
            : $completedFixtures;
        $displayUpcomingFixtures = $referenceFixtureId > 0
            ? array_values(array_filter($upcomingFixtures, static fn (array $fixture): bool => (int) ($fixture['fixture_id'] ?? 0) !== $referenceFixtureId))
            : $upcomingFixtures;

        usort($completedFixtures, fn (array $left, array $right): int => (($right['starting_at_timestamp'] ?? 0) <=> ($left['starting_at_timestamp'] ?? 0)));
        usort($upcomingFixtures, fn (array $left, array $right): int => (($left['starting_at_timestamp'] ?? 0) <=> ($right['starting_at_timestamp'] ?? 0)));
        usort($displayCompletedFixtures, fn (array $left, array $right): int => (($right['starting_at_timestamp'] ?? 0) <=> ($left['starting_at_timestamp'] ?? 0)));
        usort($displayUpcomingFixtures, fn (array $left, array $right): int => (($left['starting_at_timestamp'] ?? 0) <=> ($right['starting_at_timestamp'] ?? 0)));

        $previousFixture = null;
        $nextFixture = null;

        foreach ($completedFixtures as $fixture) {
            $timestamp = (int) ($fixture['starting_at_timestamp'] ?? 0);
            if ($timestamp <= 0 || $timestamp >= $referenceTimestamp) {
                continue;
            }

            if ($referenceFixtureId > 0 && (int) ($fixture['fixture_id'] ?? 0) === $referenceFixtureId) {
                continue;
            }

            $previousFixture = $fixture;
            break;
        }

        foreach ($upcomingFixtures as $fixture) {
            $timestamp = (int) ($fixture['starting_at_timestamp'] ?? 0);
            if ($timestamp <= 0 || $timestamp <= $referenceTimestamp) {
                continue;
            }

            if ($referenceFixtureId > 0 && (int) ($fixture['fixture_id'] ?? 0) === $referenceFixtureId) {
                continue;
            }

            $nextFixture = $fixture;
            break;
        }

        $recentForm = array_values(array_filter(array_map(
            static fn (array $fixture): ?string => is_string($fixture['result'] ?? null) ? $fixture['result'] : null,
            array_slice($completedFixtures, 0, 5)
        )));

        $windowStart = $referenceTimestamp - (14 * 86400);
        $windowEnd = $referenceTimestamp + (14 * 86400);
        $matchesLast14Days = 0;
        $matchesNext14Days = 0;

        foreach ($normalizedFixtures as $fixture) {
            $timestamp = (int) ($fixture['starting_at_timestamp'] ?? 0);
            if ($timestamp <= 0) {
                continue;
            }

            if ($timestamp < $referenceTimestamp && $timestamp >= $windowStart) {
                $matchesLast14Days++;
            }

            if ($timestamp > $referenceTimestamp && $timestamp <= $windowEnd) {
                $matchesNext14Days++;
            }
        }

        $daysSincePrevious = $previousFixture !== null
            ? (int) floor(max(0, $referenceTimestamp - (int) ($previousFixture['starting_at_timestamp'] ?? $referenceTimestamp)) / 86400)
            : null;
        $daysUntilNext = $nextFixture !== null
            ? (int) floor(max(0, (int) ($nextFixture['starting_at_timestamp'] ?? $referenceTimestamp) - $referenceTimestamp) / 86400)
            : null;

        $competitionBreakdown = $this->buildScheduleCompetitionBreakdown($normalizedFixtures);
        $alerts = [];

        if ($daysSincePrevious !== null && $daysSincePrevious <= 3) {
            $alerts[] = 'Descanso curto antes do jogo: ' . $daysSincePrevious . ' dia(s).';
        }

        if ($daysUntilNext !== null && $daysUntilNext <= 3) {
            $alerts[] = 'Proximo compromisso em ' . $daysUntilNext . ' dia(s).';
        }

        if ($matchesLast14Days >= 5) {
            $alerts[] = 'Sequencia pesada recente: ' . $matchesLast14Days . ' jogos nos 14 dias anteriores.';
        }

        if ($matchesNext14Days >= 4) {
            $alerts[] = 'Janela curta pela frente: ' . $matchesNext14Days . ' jogos nos proximos 14 dias.';
        }

        if (count($competitionBreakdown) >= 3) {
            $alerts[] = 'Calendario dividido em ' . count($competitionBreakdown) . ' competicoes.';
        }

        return [
            'team_id' => $teamId,
            'summary' => [
                'fixtures_count' => count($normalizedFixtures),
                'completed_fixtures' => count($completedFixtures),
                'upcoming_fixtures' => count($upcomingFixtures),
                'competitions_count' => count($competitionBreakdown),
                'recent_form' => $recentForm,
                'matches_last_14_days' => $matchesLast14Days,
                'matches_next_14_days' => $matchesNext14Days,
                'days_since_previous' => $daysSincePrevious,
                'days_until_next' => $daysUntilNext,
            ],
            'reference_fixture' => $referenceFixture,
            'previous_fixture' => $previousFixture,
            'next_fixture' => $nextFixture,
            'recent_fixtures' => array_slice($displayCompletedFixtures, 0, 5),
            'upcoming_fixtures' => array_slice($displayUpcomingFixtures, 0, 5),
            'competition_breakdown' => $competitionBreakdown,
            'alerts' => array_values(array_unique($alerts)),
        ];
    }

    private function normalizeRoundOddsContext(array $roundPayload, int $fixtureId): array
    {
        $fixtures = is_array($roundPayload['fixtures'] ?? null) ? $roundPayload['fixtures'] : [];
        if ($fixtures === []) {
            return [];
        }

        $fixtureSummaries = [];
        foreach ($fixtures as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }

            $participants = is_array($fixture['participants'] ?? null) ? $fixture['participants'] : [];
            $odds = $this->normalizeThreeWayOdds($fixture['odds'] ?? []);
            if ($odds === []) {
                continue;
            }

            $fixtureSummaries[] = $this->buildFixtureOddsSummaryFromOutcomes(
                (int) ($fixture['id'] ?? 0),
                $participants,
                $odds,
                $fixture
            );
        }

        if ($fixtureSummaries === []) {
            return [];
        }

        $currentFixture = null;
        foreach ($fixtureSummaries as $summary) {
            if ((int) ($summary['fixture_id'] ?? 0) === $fixtureId) {
                $currentFixture = $summary;
                break;
            }
        }

        if ($currentFixture === null) {
            return [];
        }

        $favorites = $fixtureSummaries;
        usort($favorites, fn (array $left, array $right): int => ((float) ($right['favorite_probability'] ?? 0)) <=> ((float) ($left['favorite_probability'] ?? 0)));

        $balanced = $fixtureSummaries;
        usort($balanced, fn (array $left, array $right): int => ((float) ($left['favorite_gap'] ?? 0)) <=> ((float) ($right['favorite_gap'] ?? 0)));

        $favoriteProbabilities = array_map(fn (array $summary): float => (float) ($summary['favorite_probability'] ?? 0), $fixtureSummaries);
        $drawProbabilities = array_map(fn (array $summary): float => (float) ($summary['draw_probability'] ?? 0), $fixtureSummaries);

        return [
            'round_id' => (int) ($roundPayload['id'] ?? $currentFixture['round_id'] ?? 0),
            'source' => 'round_odds',
            'round' => [
                'name' => (string) ($roundPayload['name'] ?? ''),
                'is_current' => (bool) ($roundPayload['is_current'] ?? false),
                'starting_at' => $roundPayload['starting_at'] ?? null,
                'ending_at' => $roundPayload['ending_at'] ?? null,
                'games_in_current_week' => (bool) ($roundPayload['games_in_current_week'] ?? false),
            ],
            'league' => [
                'id' => (int) ($roundPayload['league']['id'] ?? 0),
                'name' => (string) ($roundPayload['league']['name'] ?? 'Liga'),
                'country' => (string) ($roundPayload['league']['country']['name'] ?? ''),
            ],
            'market' => [
                'id' => 1,
                'name' => 'Fulltime Result',
                'developer_name' => 'FULLTIME_RESULT',
                'bookmaker' => 'bet365',
            ],
            'current_fixture' => $currentFixture,
            'round_market_overview' => [
                'fixtures_count' => count($fixtureSummaries),
                'average_favorite_probability' => round(array_sum($favoriteProbabilities) / max(1, count($favoriteProbabilities)), 2),
                'average_draw_probability' => round(array_sum($drawProbabilities) / max(1, count($drawProbabilities)), 2),
                'current_fixture_favorite_rank' => $this->findFixtureRank($favorites, $fixtureId),
                'current_fixture_balance_rank' => $this->findFixtureRank($balanced, $fixtureId),
                'strongest_favorites' => array_slice(array_map(fn (array $summary): array => $this->compactRoundFixtureOddsSummary($summary), $favorites), 0, 5),
                'most_balanced_fixtures' => array_slice(array_map(fn (array $summary): array => $this->compactRoundFixtureOddsSummary($summary), $balanced), 0, 5),
            ],
        ];
    }

    private function normalizeFixtureEvents(array $fixture): array
    {
        $participants = $this->participantsById($fixture['participants'] ?? []);
        $events = is_array($fixture['events'] ?? null) ? $fixture['events'] : [];

        usort($events, function (array $left, array $right): int {
            $leftMinute = (int) ($left['minute'] ?? 0);
            $rightMinute = (int) ($right['minute'] ?? 0);

            if ($leftMinute === $rightMinute) {
                return (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0);
            }

            return $leftMinute <=> $rightMinute;
        });

        return array_map(function (array $event) use ($participants): array {
            $participantId = (int) ($event['participant_id'] ?? 0);
            $teamName = (string) ($event['participant']['name'] ?? ($participants[$participantId]['name'] ?? 'Time'));
            $type = (string) ($event['type']['name'] ?? $event['addition'] ?? 'Evento');

            $detail = array_filter([
                trim((string) ($event['player_name'] ?? '')),
                trim((string) ($event['addition'] ?? '')),
                trim((string) ($event['info'] ?? '')),
            ], fn (string $value): bool => $value !== '' && $value !== $type);

            return [
                'time' => ['elapsed' => $this->formatElapsedTime($event)],
                'team' => ['name' => $teamName],
                'type' => $type,
                'detail' => implode(' • ', $detail),
            ];
        }, $events);
    }

    private function normalizeFixtureFormations(array $fixture): array
    {
        $participants = $this->participantsById($fixture['participants'] ?? []);
        $formations = is_array($fixture['formations'] ?? null) ? $fixture['formations'] : [];

        if ($formations === []) {
            $insights = $this->extractFixtureLineupInsights($fixture['lineups'] ?? [], $participants);
            $normalized = [];

            foreach (['home', 'away'] as $location) {
                $team = $insights[$location] ?? [];
                if ($team === []) {
                    continue;
                }

                $normalized[] = [
                    'team' => [
                        'id' => (int) ($team['team_id'] ?? 0),
                        'name' => (string) ($team['team'] ?? 'Time'),
                    ],
                    'formation' => (string) ($team['formation'] ?? 'N/A'),
                ];
            }

            return $normalized;
        }

        usort($formations, function (array $left, array $right): int {
            $order = ['home' => 0, 'away' => 1];

            return ($order[(string) ($left['location'] ?? 'away')] ?? 99) <=> ($order[(string) ($right['location'] ?? 'away')] ?? 99);
        });

        return array_map(function (array $formation) use ($participants): array {
            $participantId = (int) ($formation['participant_id'] ?? 0);

            return [
                'team' => [
                    'id' => $participantId,
                    'name' => (string) ($formation['participant']['name'] ?? ($participants[$participantId]['name'] ?? 'Time')),
                ],
                'formation' => (string) ($formation['formation'] ?? 'N/A'),
            ];
        }, $formations);
    }

    private function buildFixtureScoreboard(array $scores, array $homeParticipant, array $awayParticipant): array
    {
        $scoreboard = [
            'home' => [
                'team_id' => (int) ($homeParticipant['id'] ?? 0),
                'team' => (string) ($homeParticipant['name'] ?? 'Mandante'),
                'current' => null,
                'first_half' => null,
                'second_half_only' => null,
            ],
            'away' => [
                'team_id' => (int) ($awayParticipant['id'] ?? 0),
                'team' => (string) ($awayParticipant['name'] ?? 'Visitante'),
                'current' => null,
                'first_half' => null,
                'second_half_only' => null,
            ],
        ];

        foreach ($scores as $score) {
            if (!is_array($score)) {
                continue;
            }

            $participant = strtolower((string) ($score['score']['participant'] ?? ''));
            if (!isset($scoreboard[$participant])) {
                continue;
            }

            $goals = $this->toNullableInt($score['score']['goals'] ?? null);
            $description = strtoupper((string) ($score['description'] ?? ''));

            if ($description === 'CURRENT') {
                $scoreboard[$participant]['current'] = $goals;
                continue;
            }

            if ($description === '1ST_HALF') {
                $scoreboard[$participant]['first_half'] = $goals;
                continue;
            }

            if ($description === '2ND_HALF_ONLY') {
                $scoreboard[$participant]['second_half_only'] = $goals;
                continue;
            }

            if ($description === '2ND_HALF' && $scoreboard[$participant]['current'] === null) {
                $scoreboard[$participant]['current'] = $goals;
            }
        }

        $current = $this->extractCurrentGoals($scores);
        if ($scoreboard['home']['current'] === null) {
            $scoreboard['home']['current'] = $current['home'];
        }
        if ($scoreboard['away']['current'] === null) {
            $scoreboard['away']['current'] = $current['away'];
        }

        return $scoreboard;
    }

    private function extractFixtureXgSummary(array $xgRows, array $participants): array
    {
        $summary = [];

        foreach ($participants as $participantId => $participant) {
            $location = strtolower((string) ($participant['meta']['location'] ?? ''));
            if (!in_array($location, ['home', 'away'], true)) {
                continue;
            }

            $summary[$location] = [
                'team_id' => (int) $participantId,
                'team' => (string) ($participant['name'] ?? 'Time'),
                'xg' => null,
                'xgot' => null,
                'xpts' => null,
                'npxg' => null,
                'xg_open_play' => null,
                'xga' => null,
                'xg_diff' => null,
                'shooting_performance' => null,
                'xg_prevented' => null,
            ];
        }

        foreach ($xgRows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $participantId = (int) ($row['participant_id'] ?? 0);
            $location = strtolower((string) ($row['location'] ?? ($participants[$participantId]['meta']['location'] ?? '')));
            if (!isset($summary[$location])) {
                continue;
            }

            $metric = strtoupper((string) ($row['type']['developer_name'] ?? ''));
            $value = $this->toFloatOrNull($this->getNestedValue($row['data'] ?? null, ['value']));

            switch ($metric) {
                case 'EXPECTED_GOALS':
                    $summary[$location]['xg'] = $value;
                    break;
                case 'EXPECTED_GOALS_ON_TARGET':
                    $summary[$location]['xgot'] = $value;
                    break;
                case 'EXPECTED_POINTS':
                    $summary[$location]['xpts'] = $value;
                    break;
                case 'EXPECTED_NON_PENALTY_GOALS':
                    $summary[$location]['npxg'] = $value;
                    break;
                case 'EXPECTED_GOALS_OPEN_PLAY':
                    $summary[$location]['xg_open_play'] = $value;
                    break;
                case 'EXPECTED_GOALS_AGAINST':
                    $summary[$location]['xga'] = $value;
                    break;
                case 'EXPECTED_GOALS_DIFFERENCE':
                    $summary[$location]['xg_diff'] = $value;
                    break;
                case 'SHOOTING_PERFORMANCE':
                    $summary[$location]['shooting_performance'] = $value;
                    break;
                case 'EXPECTED_GOALS_PREVENTED':
                    $summary[$location]['xg_prevented'] = $value;
                    break;
            }
        }

        foreach ($summary as &$teamSummary) {
            foreach (['xg', 'xgot', 'xpts', 'npxg', 'xg_open_play', 'xga', 'xg_diff', 'shooting_performance', 'xg_prevented'] as $metric) {
                if ($teamSummary[$metric] !== null) {
                    $teamSummary[$metric] = round((float) $teamSummary[$metric], 3);
                }
            }
        }
        unset($teamSummary);

        return $summary;
    }

    private function extractFixtureEventTotals(array $events, array $participants, array $scoreboard): array
    {
        $totals = [
            'home' => [
                'team_id' => (int) ($scoreboard['home']['team_id'] ?? 0),
                'team' => (string) ($scoreboard['home']['team'] ?? 'Mandante'),
                'goals' => $this->toNullableInt($scoreboard['home']['current'] ?? null) ?? 0,
                'yellowcards' => 0,
                'redcards' => 0,
                'substitutions' => 0,
                'var' => 0,
            ],
            'away' => [
                'team_id' => (int) ($scoreboard['away']['team_id'] ?? 0),
                'team' => (string) ($scoreboard['away']['team'] ?? 'Visitante'),
                'goals' => $this->toNullableInt($scoreboard['away']['current'] ?? null) ?? 0,
                'yellowcards' => 0,
                'redcards' => 0,
                'substitutions' => 0,
                'var' => 0,
            ],
        ];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $participantId = (int) ($event['participant_id'] ?? 0);
            $location = strtolower((string) ($participants[$participantId]['meta']['location'] ?? ''));
            if (!isset($totals[$location])) {
                continue;
            }

            $type = strtoupper((string) ($event['type']['developer_name'] ?? $event['type']['code'] ?? ''));

            switch ($type) {
                case 'YELLOWCARD':
                    $totals[$location]['yellowcards']++;
                    break;
                case 'REDCARD':
                    $totals[$location]['redcards']++;
                    break;
                case 'SUBSTITUTION':
                    $totals[$location]['substitutions']++;
                    break;
                case 'VAR':
                    $totals[$location]['var']++;
                    break;
            }
        }

        return $totals;
    }

    private function extractFixtureLineupInsights(array $lineups, array $participants): array
    {
        $grouped = [];

        foreach ($participants as $participantId => $participant) {
            $location = strtolower((string) ($participant['meta']['location'] ?? ''));
            if (!in_array($location, ['home', 'away'], true)) {
                continue;
            }

            $grouped[$participantId] = [
                'team_id' => (int) $participantId,
                'team' => (string) ($participant['name'] ?? 'Time'),
                'location' => $location,
                'lineups' => [],
            ];
        }

        foreach ($lineups as $lineup) {
            if (!is_array($lineup)) {
                continue;
            }

            $teamId = (int) ($lineup['team_id'] ?? 0);
            if (!isset($grouped[$teamId])) {
                $participant = $participants[$teamId] ?? [];
                $grouped[$teamId] = [
                    'team_id' => $teamId,
                    'team' => (string) ($participant['name'] ?? 'Time'),
                    'location' => strtolower((string) ($participant['meta']['location'] ?? '')),
                    'lineups' => [],
                ];
            }

            $grouped[$teamId]['lineups'][] = $lineup;
        }

        $insights = [];

        foreach (['home', 'away'] as $location) {
            foreach ($grouped as $teamGroup) {
                $teamLineups = is_array($teamGroup['lineups'] ?? null) ? $teamGroup['lineups'] : [];
                if (($teamGroup['location'] ?? '') !== $location || $teamLineups === []) {
                    continue;
                }

                $insights[$location] = $this->buildTeamLineupInsight(
                    (int) ($teamGroup['team_id'] ?? 0),
                    (string) ($teamGroup['team'] ?? 'Time'),
                    $teamLineups
                );

                break;
            }
        }

        return $insights;
    }

    private function flattenTeamScheduleFixtures(array $schedules): array
    {
        $fixtures = [];

        foreach ($schedules as $schedule) {
            if (!is_array($schedule)) {
                continue;
            }

            foreach (($schedule['fixtures'] ?? []) as $fixture) {
                $this->rememberScheduledFixture($fixtures, $fixture, $schedule);
            }

            foreach (($schedule['rounds'] ?? []) as $round) {
                if (!is_array($round)) {
                    continue;
                }

                foreach (($round['fixtures'] ?? []) as $fixture) {
                    $this->rememberScheduledFixture($fixtures, $fixture, $schedule, $round);
                }
            }

            foreach (($schedule['aggregates'] ?? []) as $aggregate) {
                if (!is_array($aggregate)) {
                    continue;
                }

                foreach (($aggregate['fixtures'] ?? []) as $fixture) {
                    $this->rememberScheduledFixture($fixtures, $fixture, $schedule, null, $aggregate);
                }
            }
        }

        return array_values($fixtures);
    }

    private function rememberScheduledFixture(array &$fixtures, mixed $fixture, array $stage, ?array $round = null, ?array $aggregate = null): void
    {
        if (!is_array($fixture)) {
            return;
        }

        $fixtureId = (int) ($fixture['id'] ?? 0);
        if ($fixtureId <= 0) {
            return;
        }

        $existing = $fixtures[$fixtureId] ?? [];
        $fixture['schedule_stage'] = [
            'id' => (int) ($stage['id'] ?? 0),
            'name' => (string) ($stage['name'] ?? ''),
            'league_id' => (int) ($stage['league_id'] ?? $fixture['league_id'] ?? 0),
            'league_name' => (string) ($stage['league']['name'] ?? $fixture['league']['name'] ?? ''),
            'country' => (string) ($stage['league']['country']['name'] ?? $fixture['league']['country']['name'] ?? ''),
        ];

        if ($round !== null) {
            $fixture['schedule_round'] = [
                'id' => (int) ($round['id'] ?? 0),
                'name' => (string) ($round['name'] ?? ''),
            ];
        }

        if ($aggregate !== null) {
            $fixture['schedule_aggregate'] = [
                'id' => (int) ($aggregate['id'] ?? 0),
                'name' => (string) ($aggregate['name'] ?? ''),
                'result' => $this->toStringOrNull($aggregate['result'] ?? null),
                'detail' => $this->toStringOrNull($aggregate['detail'] ?? null),
            ];
        }

        $fixtures[$fixtureId] = $existing === [] ? $fixture : array_replace_recursive($existing, $fixture);
    }

    private function normalizeScheduledFixture(array $fixture, int $teamId): ?array
    {
        $participants = is_array($fixture['participants'] ?? null) ? $fixture['participants'] : [];
        if ($participants === []) {
            return null;
        }

        $participantsById = $this->participantsById($participants);
        $teamParticipant = $participantsById[$teamId] ?? [];
        if ($teamParticipant === []) {
            return null;
        }

        $location = strtolower((string) ($teamParticipant['meta']['location'] ?? ''));
        $opponent = [];

        foreach ($participants as $participant) {
            if (!is_array($participant) || (int) ($participant['id'] ?? 0) === $teamId) {
                continue;
            }

            $opponent = $participant;
            break;
        }

        $scoreline = $this->extractCurrentGoals(is_array($fixture['scores'] ?? null) ? $fixture['scores'] : []);
        $timestamp = isset($fixture['starting_at_timestamp']) && is_numeric($fixture['starting_at_timestamp'])
            ? (int) $fixture['starting_at_timestamp']
            : strtotime((string) ($fixture['starting_at'] ?? ''));
        $timestamp = $timestamp > 0 ? $timestamp : null;

        $teamGoals = null;
        $opponentGoals = null;
        if ($location === 'home') {
            $teamGoals = $scoreline['home'];
            $opponentGoals = $scoreline['away'];
        } elseif ($location === 'away') {
            $teamGoals = $scoreline['away'];
            $opponentGoals = $scoreline['home'];
        }

        $statusShort = strtoupper((string) ($fixture['state']['short_name'] ?? $fixture['state']['state'] ?? ''));
        $isFinished = $this->isScheduledFixtureFinished($fixture, $timestamp, $statusShort, $teamGoals, $opponentGoals);
        $result = null;

        if ($isFinished && $teamGoals !== null && $opponentGoals !== null) {
            $result = $teamGoals > $opponentGoals ? 'W' : ($teamGoals === $opponentGoals ? 'D' : 'L');
        }

        return [
            'fixture_id' => (int) ($fixture['id'] ?? 0),
            'name' => (string) ($fixture['name'] ?? ''),
            'date' => $timestamp !== null ? date('Y-m-d H:i:s', $timestamp) : null,
            'starting_at' => $fixture['starting_at'] ?? null,
            'starting_at_timestamp' => $timestamp,
            'status' => $statusShort !== '' ? $statusShort : ($isFinished ? 'FT' : 'NS'),
            'status_label' => (string) ($fixture['state']['name'] ?? ($isFinished ? 'Finished' : 'Scheduled')),
            'competition' => $this->buildScheduledCompetitionLabel($fixture),
            'stage' => (string) ($fixture['schedule_stage']['name'] ?? ''),
            'round' => (string) ($fixture['schedule_round']['name'] ?? ''),
            'aggregate' => (string) ($fixture['schedule_aggregate']['name'] ?? ''),
            'location' => in_array($location, ['home', 'away'], true) ? $location : null,
            'opponent' => [
                'team_id' => (int) ($opponent['id'] ?? 0),
                'name' => (string) ($opponent['name'] ?? 'Adversario'),
                'logo' => $opponent['image_path'] ?? null,
            ],
            'score' => ($teamGoals !== null && $opponentGoals !== null) ? ($teamGoals . '-' . $opponentGoals) : null,
            'team_goals' => $teamGoals,
            'opponent_goals' => $opponentGoals,
            'result' => $result,
            'result_info' => $this->toStringOrNull($fixture['result_info'] ?? null),
            'is_finished' => $isFinished,
        ];
    }

    private function findReferenceScheduleFixture(array $fixtures, int $referenceFixtureId): ?array
    {
        if ($referenceFixtureId > 0) {
            foreach ($fixtures as $fixture) {
                if ((int) ($fixture['fixture_id'] ?? 0) === $referenceFixtureId) {
                    return $fixture;
                }
            }
        }

        $now = time();
        $nextFixture = null;
        $lastFixture = null;

        foreach ($fixtures as $fixture) {
            $timestamp = (int) ($fixture['starting_at_timestamp'] ?? 0);
            if ($timestamp <= 0) {
                continue;
            }

            if ($timestamp >= $now && $nextFixture === null) {
                $nextFixture = $fixture;
                continue;
            }

            if ($timestamp < $now) {
                $lastFixture = $fixture;
            }
        }

        return $nextFixture ?? $lastFixture ?? ($fixtures[0] ?? null);
    }

    private function buildScheduledCompetitionLabel(array $fixture): string
    {
        $leagueName = trim((string) ($fixture['schedule_stage']['league_name'] ?? $fixture['league']['name'] ?? ''));
        $stageName = trim((string) ($fixture['schedule_stage']['name'] ?? ''));

        if ($leagueName !== '' && $stageName !== '' && strcasecmp($leagueName, $stageName) !== 0) {
            return $leagueName . ' - ' . $stageName;
        }

        if ($leagueName !== '') {
            return $leagueName;
        }

        $leagueId = (int) ($fixture['schedule_stage']['league_id'] ?? $fixture['league_id'] ?? 0);

        if ($stageName !== '') {
            return $leagueId > 0 ? ('Liga ' . $leagueId . ' - ' . $stageName) : $stageName;
        }

        return $leagueId > 0 ? 'Liga ' . $leagueId : 'Competicao';
    }

    private function buildScheduleCompetitionBreakdown(array $fixtures): array
    {
        $breakdown = [];

        foreach ($fixtures as $fixture) {
            $label = (string) ($fixture['competition'] ?? 'Competicao');
            if (!isset($breakdown[$label])) {
                $breakdown[$label] = [
                    'competition' => $label,
                    'fixtures' => 0,
                    'completed' => 0,
                    'upcoming' => 0,
                ];
            }

            $breakdown[$label]['fixtures']++;

            if ((bool) ($fixture['is_finished'] ?? false)) {
                $breakdown[$label]['completed']++;
            } else {
                $breakdown[$label]['upcoming']++;
            }
        }

        $breakdown = array_values($breakdown);

        usort($breakdown, function (array $left, array $right): int {
            if ((int) ($left['fixtures'] ?? 0) === (int) ($right['fixtures'] ?? 0)) {
                return strcmp((string) ($left['competition'] ?? ''), (string) ($right['competition'] ?? ''));
            }

            return (int) ($right['fixtures'] ?? 0) <=> (int) ($left['fixtures'] ?? 0);
        });

        return $breakdown;
    }

    private function isScheduledFixtureFinished(array $fixture, ?int $timestamp, string $statusShort, ?int $teamGoals, ?int $opponentGoals): bool
    {
        if (in_array($statusShort, ['FT', 'AET', 'PEN', 'FT_PEN'], true)) {
            return true;
        }

        if ((bool) ($fixture['finished'] ?? false)) {
            return true;
        }

        if ($this->toStringOrNull($fixture['result_info'] ?? null) !== null) {
            return true;
        }

        if ($timestamp !== null && $timestamp < (time() - 6 * 3600)) {
            return true;
        }

        return $teamGoals !== null && $opponentGoals !== null && $timestamp !== null && $timestamp < (time() - 3 * 3600);
    }

    private function buildTeamLineupInsight(int $teamId, string $teamName, array $lineups): array
    {
        $players = [];
        $startingXiCount = 0;
        $benchUsed = 0;

        foreach ($lineups as $lineup) {
            if (!is_array($lineup)) {
                continue;
            }

            $details = $this->mapLineupValuesByType($lineup['details'] ?? []);
            $xg = $this->mapLineupValuesByType($lineup['xglineup'] ?? []);
            $minutes = $this->toNullableInt($details['MINUTES_PLAYED'] ?? $details['CUMULATIVE_MINUTES_PLAYED'] ?? null);
            $isStarter = (int) ($lineup['type_id'] ?? 0) === 11;

            if ($isStarter) {
                $startingXiCount++;
            } elseif (($minutes ?? 0) > 0) {
                $benchUsed++;
            }

            $players[] = [
                'player_id' => (int) ($lineup['player_id'] ?? $lineup['player']['id'] ?? 0),
                'name' => (string) ($lineup['player']['display_name'] ?? $lineup['player_name'] ?? 'Jogador'),
                'rating' => round($this->toFloatOrNull($details['RATING'] ?? null) ?? 0.0, 2),
                'minutes' => $minutes,
                'goals' => $this->toNullableInt($details['GOALS'] ?? null) ?? 0,
                'assists' => $this->toNullableInt($details['ASSISTS'] ?? null) ?? 0,
                'shots' => $this->toNullableInt($details['SHOTS_TOTAL'] ?? null) ?? 0,
                'xg' => $this->toFloatOrNull($xg['EXPECTED_GOALS'] ?? null),
                'xgot' => $this->toFloatOrNull($xg['EXPECTED_GOALS_ON_TARGET'] ?? null),
            ];
        }

        $ratedPlayers = array_values(array_filter($players, static fn (array $player): bool => (float) ($player['rating'] ?? 0) > 0));
        usort($ratedPlayers, function (array $left, array $right): int {
            if ((float) ($left['rating'] ?? 0) === (float) ($right['rating'] ?? 0)) {
                return (int) ($right['goals'] ?? 0) <=> (int) ($left['goals'] ?? 0);
            }

            return (float) ($right['rating'] ?? 0) <=> (float) ($left['rating'] ?? 0);
        });

        $xgPlayers = array_values(array_filter($players, static fn (array $player): bool => ($player['xg'] ?? null) !== null || ($player['xgot'] ?? null) !== null));
        usort($xgPlayers, function (array $left, array $right): int {
            $leftXg = (float) ($left['xg'] ?? 0);
            $rightXg = (float) ($right['xg'] ?? 0);

            if ($leftXg === $rightXg) {
                return (float) ($right['xgot'] ?? 0) <=> (float) ($left['xgot'] ?? 0);
            }

            return $rightXg <=> $leftXg;
        });

        return [
            'team_id' => $teamId,
            'team' => $teamName,
            'formation' => $this->inferFormationFromLineups($lineups) ?? 'N/A',
            'starting_xi_count' => $startingXiCount,
            'bench_used' => $benchUsed,
            'top_rated_players' => array_map(function (array $player): array {
                return [
                    'player_id' => (int) ($player['player_id'] ?? 0),
                    'name' => $player['name'],
                    'rating' => round((float) ($player['rating'] ?? 0), 2),
                    'minutes' => $player['minutes'],
                    'goals' => (int) ($player['goals'] ?? 0),
                    'assists' => (int) ($player['assists'] ?? 0),
                ];
            }, array_slice($ratedPlayers, 0, 3)),
            'top_xg_players' => array_map(function (array $player): array {
                return [
                    'player_id' => (int) ($player['player_id'] ?? 0),
                    'name' => $player['name'],
                    'xg' => ($player['xg'] ?? null) !== null ? round((float) $player['xg'], 3) : null,
                    'xgot' => ($player['xgot'] ?? null) !== null ? round((float) $player['xgot'], 3) : null,
                    'goals' => (int) ($player['goals'] ?? 0),
                    'shots' => (int) ($player['shots'] ?? 0),
                ];
            }, array_slice($xgPlayers, 0, 3)),
        ];
    }

    private function extractFixtureWeather(array $fixture): array
    {
        $weather = is_array($fixture['weatherreport'] ?? null)
            ? $fixture['weatherreport']
            : (is_array($fixture['weatherReport'] ?? null) ? $fixture['weatherReport'] : []);

        if ($weather === []) {
            return [];
        }

        $summary = [
            'description' => $this->firstNonEmptyString([
                $weather['description'] ?? null,
                $weather['weather_description'] ?? null,
                $weather['condition'] ?? null,
                $weather['text'] ?? null,
                $weather['type'] ?? null,
            ]),
            'temperature_c' => $this->firstNumericValue([
                $weather['temperature'] ?? null,
                $weather['temperature_c'] ?? null,
                $weather['temp_c'] ?? null,
                $weather['temp'] ?? null,
            ]),
            'feels_like_c' => $this->firstNumericValue([
                $weather['feels_like'] ?? null,
                $weather['feels_like_c'] ?? null,
                $weather['apparent_temperature'] ?? null,
            ]),
            'humidity' => $this->firstNumericValue([
                $weather['humidity'] ?? null,
                $weather['humidity_percentage'] ?? null,
            ]),
            'wind_kph' => $this->firstNumericValue([
                $weather['wind_speed'] ?? null,
                $weather['wind_speed_kph'] ?? null,
                $weather['wind_kph'] ?? null,
            ]),
            'pressure' => $this->firstNumericValue([
                $weather['pressure'] ?? null,
                $weather['pressure_mb'] ?? null,
            ]),
            'clouds' => $this->firstNumericValue([
                $weather['clouds'] ?? null,
                $weather['cloudiness'] ?? null,
            ]),
        ];

        return array_filter($summary, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function extractFixtureSidelined(array $fixture, array $participants): array
    {
        $rows = is_array($fixture['sidelined'] ?? null) ? $fixture['sidelined'] : [];
        if ($rows === []) {
            return [];
        }

        $grouped = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $sideline = is_array($row['sideline'] ?? null) ? $row['sideline'] : $row;
            $teamId = (int) ($row['participant_id'] ?? $row['team_id'] ?? $sideline['participant_id'] ?? $sideline['team_id'] ?? 0);
            $player = is_array($sideline['player'] ?? null) ? $sideline['player'] : [];
            $type = is_array($sideline['type'] ?? null) ? $sideline['type'] : [];

            if ($teamId <= 0 && $player === []) {
                continue;
            }

            $teamName = (string) ($participants[$teamId]['name'] ?? ('Time ' . max(1, $teamId)));

            if (!isset($grouped[$teamId])) {
                $grouped[$teamId] = [
                    'team_id' => $teamId,
                    'team' => $teamName,
                    'players' => [],
                ];
            }

            $grouped[$teamId]['players'][] = array_filter([
                'player_id' => (int) ($player['id'] ?? 0),
                'name' => $this->firstNonEmptyString([
                    $player['display_name'] ?? null,
                    $player['common_name'] ?? null,
                    $player['name'] ?? null,
                ]) ?? 'Jogador',
                'reason' => $this->firstNonEmptyString([
                    $type['name'] ?? null,
                    $type['developer_name'] ?? null,
                    $type['code'] ?? null,
                ]),
                'status' => $this->firstNonEmptyString([
                    $sideline['status'] ?? null,
                    $sideline['category'] ?? null,
                ]),
                'start_date' => $this->toStringOrNull($sideline['start_date'] ?? $sideline['starting_at'] ?? null),
                'end_date' => $this->toStringOrNull($sideline['end_date'] ?? $sideline['ending_at'] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0);
        }

        $normalized = [];

        foreach ($grouped as $teamGroup) {
            $players = is_array($teamGroup['players'] ?? null) ? $teamGroup['players'] : [];
            if ($players === []) {
                continue;
            }

            usort($players, static function (array $left, array $right): int {
                return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            });

            $normalized[] = [
                'team_id' => (int) ($teamGroup['team_id'] ?? 0),
                'team' => (string) ($teamGroup['team'] ?? 'Time'),
                'count' => count($players),
                'players' => array_slice($players, 0, 6),
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            if ((int) ($left['count'] ?? 0) === (int) ($right['count'] ?? 0)) {
                return strcmp((string) ($left['team'] ?? ''), (string) ($right['team'] ?? ''));
            }

            return (int) ($right['count'] ?? 0) <=> (int) ($left['count'] ?? 0);
        });

        return $normalized;
    }

    private function extractFixturePredictions(array $fixture): array
    {
        $rows = is_array($fixture['predictions'] ?? null) ? $fixture['predictions'] : [];

        return $this->normalizeFixturePredictionRows($rows);
    }

    private function normalizeFixturePredictionRows(array $rows): array
    {
        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $values = is_array($row['predictions'] ?? null) ? $row['predictions'] : [];
            if ($values === []) {
                continue;
            }

            $probabilities = [];

            foreach ($values as $key => $value) {
                $normalizedValue = $this->normalizePredictionValue($value);
                if ($normalizedValue === null) {
                    continue;
                }

                $probabilities[(string) $key] = $normalizedValue;
            }

            if ($probabilities === []) {
                continue;
            }

            $normalized[] = [
                'id' => (int) ($row['id'] ?? 0),
                'type_id' => (int) ($row['type_id'] ?? 0),
                'market' => $this->firstNonEmptyString([
                    $row['type']['name'] ?? null,
                    $row['type']['developer_name'] ?? null,
                    $row['type']['code'] ?? null,
                ]) ?? 'Prediction',
                'code' => $this->firstNonEmptyString([
                    $row['type']['code'] ?? null,
                    $row['type']['developer_name'] ?? null,
                ]),
                'probabilities' => $probabilities,
            ];
        }

        usort($normalized, static function (array $left, array $right): int {
            return strcmp((string) ($left['market'] ?? ''), (string) ($right['market'] ?? ''));
        });

        return array_slice($normalized, 0, 10);
    }

    private function normalizePredictionValue(mixed $value): int|float|string|null
    {
        if (is_numeric($value)) {
            $number = (float) $value;

            if ($number >= 0 && $number <= 1) {
                $number *= 100;
            }

            return round($number, 2);
        }

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return null;
    }

    private function extractFeaturedPlayerProfiles(array $lineups, array $events, array $participants): array
    {
        $candidates = $this->collectFeaturedLineupPlayers($lineups, $participants);

        if ($candidates === []) {
            $candidates = $this->collectFeaturedEventPlayers($events, $participants);
        }

        if ($candidates === []) {
            return [];
        }

        $profiles = [];
        $perSideCount = ['home' => 0, 'away' => 0];

        foreach ($candidates as $candidate) {
            if (count($profiles) >= self::MAX_FEATURED_PLAYERS) {
                break;
            }

            $location = in_array($candidate['location'] ?? '', ['home', 'away'], true) ? $candidate['location'] : null;
            if ($location !== null && $perSideCount[$location] >= 2) {
                continue;
            }

            $playerId = (int) ($candidate['player_id'] ?? 0);
            if ($playerId <= 0) {
                continue;
            }

            $payload = $this->fetchPlayerProfilePayload($playerId);
            $profile = $payload !== null
                ? $this->normalizePlayerProfileContext($payload, $candidate)
                : $this->buildFallbackFeaturedPlayerContext($candidate);

            if ($profile === []) {
                continue;
            }

            $profiles[] = $profile;

            if ($location !== null) {
                $perSideCount[$location]++;
            }
        }

        return $profiles;
    }

    private function collectFeaturedLineupPlayers(array $lineups, array $participants): array
    {
        $candidates = [];

        foreach ($lineups as $lineup) {
            if (!is_array($lineup)) {
                continue;
            }

            $playerId = (int) ($lineup['player_id'] ?? $lineup['player']['id'] ?? 0);
            if ($playerId <= 0) {
                continue;
            }

            $teamId = (int) ($lineup['team_id'] ?? 0);
            $details = $this->mapLineupValuesByType($lineup['details'] ?? []);
            $xg = $this->mapLineupValuesByType($lineup['xglineup'] ?? []);
            $rating = $this->toFloatOrNull($details['RATING'] ?? null) ?? 0.0;
            $goals = $this->toNullableInt($details['GOALS'] ?? null) ?? 0;
            $assists = $this->toNullableInt($details['ASSISTS'] ?? null) ?? 0;
            $shots = $this->toNullableInt($details['SHOTS_TOTAL'] ?? null) ?? 0;
            $minutes = $this->toNullableInt($details['MINUTES_PLAYED'] ?? $details['CUMULATIVE_MINUTES_PLAYED'] ?? null);
            $xgValue = $this->toFloatOrNull($xg['EXPECTED_GOALS'] ?? null);
            $isStarter = (int) ($lineup['type_id'] ?? 0) === 11;
            $location = strtolower((string) ($participants[$teamId]['meta']['location'] ?? ''));
            $teamName = (string) ($participants[$teamId]['name'] ?? 'Time');
            $score = ($isStarter ? 5 : 0)
                + ($goals * 100)
                + ($assists * 45)
                + ($rating * 10)
                + (($xgValue ?? 0.0) * 35)
                + ($shots * 3)
                + (($minutes ?? 0) / 90);

            $candidate = [
                'player_id' => $playerId,
                'name' => (string) ($lineup['player']['display_name'] ?? $lineup['player_name'] ?? 'Jogador'),
                'team_id' => $teamId,
                'team' => $teamName,
                'location' => in_array($location, ['home', 'away'], true) ? $location : null,
                'rating' => round($rating, 2),
                'goals' => $goals,
                'assists' => $assists,
                'shots' => $shots,
                'minutes' => $minutes,
                'xg' => $xgValue !== null ? round($xgValue, 3) : null,
                'score' => round($score, 3),
            ];

            $existing = $candidates[$playerId] ?? null;
            if (!is_array($existing) || (float) ($candidate['score'] ?? 0) > (float) ($existing['score'] ?? 0)) {
                $candidates[$playerId] = $candidate;
            }
        }

        $candidates = array_values($candidates);

        usort($candidates, static function (array $left, array $right): int {
            if ((float) ($left['score'] ?? 0) === (float) ($right['score'] ?? 0)) {
                return strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''));
            }

            return (float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0);
        });

        return $candidates;
    }

    private function collectFeaturedEventPlayers(array $events, array $participants): array
    {
        $candidates = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $playerId = (int) ($event['player_id'] ?? $event['player']['id'] ?? 0);
            if ($playerId <= 0) {
                continue;
            }

            $teamId = (int) ($event['participant_id'] ?? 0);
            $location = strtolower((string) ($participants[$teamId]['meta']['location'] ?? ''));
            $type = strtoupper((string) ($event['type']['developer_name'] ?? $event['type']['code'] ?? ''));

            $bonus = match ($type) {
                'GOAL' => 120,
                'PENALTY_SCORED' => 110,
                'ASSIST' => 60,
                'RED_CARD', 'REDCARD' => 25,
                default => 10,
            };

            $candidate = [
                'player_id' => $playerId,
                'name' => (string) ($event['player']['display_name'] ?? $event['player']['name'] ?? 'Jogador'),
                'team_id' => $teamId,
                'team' => (string) ($participants[$teamId]['name'] ?? 'Time'),
                'location' => in_array($location, ['home', 'away'], true) ? $location : null,
                'rating' => null,
                'goals' => in_array($type, ['GOAL', 'PENALTY_SCORED'], true) ? 1 : 0,
                'assists' => $type === 'ASSIST' ? 1 : 0,
                'shots' => 0,
                'minutes' => null,
                'xg' => null,
                'score' => $bonus,
            ];

            $existing = $candidates[$playerId] ?? null;
            if (!is_array($existing) || (float) ($candidate['score'] ?? 0) > (float) ($existing['score'] ?? 0)) {
                $candidates[$playerId] = $candidate;
            }
        }

        $candidates = array_values($candidates);

        usort($candidates, static function (array $left, array $right): int {
            return (float) ($right['score'] ?? 0) <=> (float) ($left['score'] ?? 0);
        });

        return $candidates;
    }

    private function normalizePlayerProfileContext(array $player, array $matchPerformance = []): array
    {
        $metadata = $this->mapMetadataValuesByType($player['metadata'] ?? []);
        $seasonSnapshot = $this->extractPrimaryPlayerSeasonSnapshot($player['statistics'] ?? []);
        $latestMatch = $this->extractLatestPlayerAppearance($player['latest'] ?? null);

        return array_filter([
            'player_id' => (int) ($player['id'] ?? 0),
            'name' => $this->firstNonEmptyString([
                $player['display_name'] ?? null,
                $player['common_name'] ?? null,
                $player['name'] ?? null,
            ]) ?? 'Jogador',
            'age' => $this->calculateAge($this->toStringOrNull($player['date_of_birth'] ?? null)),
            'nationality' => $this->toStringOrNull($player['nationality']['name'] ?? null),
            'position' => $this->firstNonEmptyString([
                $player['detailedposition']['name'] ?? null,
                $player['detailedPosition']['name'] ?? null,
            ]),
            'preferred_foot' => $this->toStringOrNull($metadata['PREFERRED_FOOT'] ?? null),
            'height_cm' => $this->toNullableInt($player['height'] ?? null),
            'weight_kg' => $this->toNullableInt($player['weight'] ?? null),
            'current_team' => $this->resolvePlayerCurrentTeam($player['teams'] ?? []),
            'career' => $this->summarizePlayerTrophies($player['trophies'] ?? []),
            'season_snapshot' => $seasonSnapshot,
            'latest_match' => $latestMatch,
            'match_focus' => $this->buildPlayerMatchFocus($matchPerformance),
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    private function buildFallbackFeaturedPlayerContext(array $candidate): array
    {
        return [
            'player_id' => (int) ($candidate['player_id'] ?? 0),
            'name' => (string) ($candidate['name'] ?? 'Jogador'),
            'current_team' => [
                'team_id' => (int) ($candidate['team_id'] ?? 0),
                'team' => (string) ($candidate['team'] ?? 'Time'),
                'location' => $candidate['location'] ?? null,
            ],
            'match_focus' => $this->buildPlayerMatchFocus($candidate),
        ];
    }

    private function mapMetadataValuesByType(array $rows): array
    {
        $lookup = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtoupper((string) ($row['type']['developer_name'] ?? ''));
            if ($type === '') {
                continue;
            }

            $lookup[$type] = $row['values'] ?? $row['value'] ?? null;
        }

        return $lookup;
    }

    private function extractPrimaryPlayerSeasonSnapshot(array $statistics): array
    {
        $row = $this->pickPrimaryPlayerStatisticRow($statistics);
        if ($row === []) {
            return [];
        }

        $details = $this->mapPlayerStatisticValuesByType($row['details'] ?? []);

        return array_filter([
            'team' => $this->toStringOrNull($row['team']['name'] ?? null),
            'league' => $this->toStringOrNull($row['season']['league']['name'] ?? null),
            'season' => $this->toStringOrNull($row['season']['name'] ?? null),
            'is_current' => (bool) ($row['season']['is_current'] ?? false),
            'appearances' => $this->toNullableInt($this->extractPreferredNumericValue($details['APPEARANCES'] ?? null, ['total'])),
            'goals' => $this->toNullableInt($this->extractPreferredNumericValue($details['GOALS'] ?? null, ['goals', 'total'])),
            'assists' => $this->toNullableInt($this->extractPreferredNumericValue($details['ASSISTS'] ?? null, ['total'])),
            'minutes' => $this->toNullableInt($this->extractPreferredNumericValue($details['MINUTES_PLAYED'] ?? null, ['total'])),
            'rating' => $this->toFloatOrNull($this->extractPreferredNumericValue($details['RATING'] ?? null, ['average', 'total'])),
            'shots' => $this->toNullableInt($this->extractPreferredNumericValue($details['SHOTS_TOTAL'] ?? null, ['total'])),
            'shots_on_target' => $this->toNullableInt($this->extractPreferredNumericValue($details['SHOTS_ON_TARGET'] ?? null, ['total'])),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function pickPrimaryPlayerStatisticRow(array $statistics): array
    {
        $rows = array_values(array_filter($statistics, 'is_array'));
        if ($rows === []) {
            return [];
        }

        usort($rows, static function (array $left, array $right): int {
            $leftCurrent = (bool) ($left['season']['is_current'] ?? false);
            $rightCurrent = (bool) ($right['season']['is_current'] ?? false);

            if ($leftCurrent !== $rightCurrent) {
                return $rightCurrent <=> $leftCurrent;
            }

            $leftStart = strtotime((string) ($left['season']['starting_at'] ?? '')) ?: 0;
            $rightStart = strtotime((string) ($right['season']['starting_at'] ?? '')) ?: 0;

            if ($leftStart === $rightStart) {
                return (int) ($right['season_id'] ?? 0) <=> (int) ($left['season_id'] ?? 0);
            }

            return $rightStart <=> $leftStart;
        });

        return is_array($rows[0] ?? null) ? $rows[0] : [];
    }

    private function mapPlayerStatisticValuesByType(array $rows): array
    {
        $lookup = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtoupper((string) ($row['type']['developer_name'] ?? ''));
            if ($type === '') {
                continue;
            }

            $lookup[$type] = $row['value'] ?? null;
        }

        return $lookup;
    }

    private function extractPreferredNumericValue(mixed $value, array $preferredKeys = ['total', 'average', 'value']): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        foreach ($preferredKeys as $key) {
            $candidate = $this->toFloatOrNull($this->getNestedValue($value, [$key]));
            if ($candidate !== null) {
                return $candidate;
            }
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ($value as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return null;
    }

    private function resolvePlayerCurrentTeam(array $teams): array
    {
        $rows = [];
        $today = date('Y-m-d');

        foreach ($teams as $row) {
            if (!is_array($row)) {
                continue;
            }

            $team = is_array($row['team'] ?? null) ? $row['team'] : [];
            if ($team === []) {
                continue;
            }

            $end = $this->toStringOrNull($row['end'] ?? null);
            $start = $this->toStringOrNull($row['start'] ?? null);
            $isActive = $end === null || $end >= $today;

            $rows[] = [
                'team_id' => (int) ($team['id'] ?? 0),
                'team' => (string) ($team['name'] ?? 'Time'),
                'short_code' => $this->toStringOrNull($team['short_code'] ?? null),
                'type' => $this->toStringOrNull($team['type'] ?? null),
                'start' => $start,
                'end' => $end,
                'is_active' => $isActive,
                '_sort_end' => strtotime((string) ($end ?? '9999-12-31')) ?: PHP_INT_MAX,
                '_sort_start' => strtotime((string) ($start ?? '1970-01-01')) ?: 0,
            ];
        }

        if ($rows === []) {
            return [];
        }

        usort($rows, static function (array $left, array $right): int {
            $leftActive = (bool) ($left['is_active'] ?? false);
            $rightActive = (bool) ($right['is_active'] ?? false);

            if ($leftActive !== $rightActive) {
                return $rightActive <=> $leftActive;
            }

            $leftDomestic = ($left['type'] ?? '') === 'domestic';
            $rightDomestic = ($right['type'] ?? '') === 'domestic';

            if ($leftDomestic !== $rightDomestic) {
                return $rightDomestic <=> $leftDomestic;
            }

            if ((int) ($left['_sort_end'] ?? 0) === (int) ($right['_sort_end'] ?? 0)) {
                return (int) ($right['_sort_start'] ?? 0) <=> (int) ($left['_sort_start'] ?? 0);
            }

            return (int) ($right['_sort_end'] ?? 0) <=> (int) ($left['_sort_end'] ?? 0);
        });

        $team = $rows[0];
        unset($team['_sort_end'], $team['_sort_start']);

        return array_filter($team, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function summarizePlayerTrophies(array $trophies): array
    {
        $rows = array_values(array_filter($trophies, 'is_array'));
        if ($rows === []) {
            return [];
        }

        $won = 0;
        $runnerUp = 0;
        $competitions = [];

        usort($rows, static function (array $left, array $right): int {
            $leftEnd = strtotime((string) ($left['season']['ending_at'] ?? $left['season']['starting_at'] ?? '')) ?: 0;
            $rightEnd = strtotime((string) ($right['season']['ending_at'] ?? $right['season']['starting_at'] ?? '')) ?: 0;

            return $rightEnd <=> $leftEnd;
        });

        foreach ($rows as $trophy) {
            $position = (int) ($trophy['trophy']['position'] ?? 0);
            if ($position === 1) {
                $won++;
            } elseif ($position === 2) {
                $runnerUp++;
            }

            $competition = (string) ($trophy['league']['name'] ?? '');
            if ($competition !== '') {
                $competitions[$competition] = true;
            }
        }

        $recent = array_map(function (array $trophy): array {
            return array_filter([
                'competition' => $this->toStringOrNull($trophy['league']['name'] ?? null),
                'season' => $this->toStringOrNull($trophy['season']['name'] ?? null),
                'result' => $this->toStringOrNull($trophy['trophy']['name'] ?? null),
                'team' => $this->toStringOrNull($trophy['team']['name'] ?? null),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }, array_slice($rows, 0, 3));

        return [
            'total' => count($rows),
            'won' => $won,
            'runner_up' => $runnerUp,
            'competitions' => count($competitions),
            'recent' => array_values(array_filter($recent, static fn (array $row): bool => $row !== [])),
        ];
    }

    private function extractLatestPlayerAppearance(mixed $latest): array
    {
        $rows = array_values(array_filter($this->extractRecentFixtures($latest), 'is_array'));
        if ($rows === []) {
            return [];
        }

        usort($rows, static function (array $left, array $right): int {
            $leftTimestamp = (int) ($left['fixture']['starting_at_timestamp'] ?? 0);
            $rightTimestamp = (int) ($right['fixture']['starting_at_timestamp'] ?? 0);

            if ($leftTimestamp === $rightTimestamp) {
                $leftDate = strtotime((string) ($left['fixture']['starting_at'] ?? '')) ?: 0;
                $rightDate = strtotime((string) ($right['fixture']['starting_at'] ?? '')) ?: 0;

                return $rightDate <=> $leftDate;
            }

            return $rightTimestamp <=> $leftTimestamp;
        });

        $appearance = is_array($rows[0] ?? null) ? $rows[0] : [];
        if ($appearance === []) {
            return [];
        }

        $details = $this->mapLineupValuesByType($appearance['details'] ?? []);
        $fixture = is_array($appearance['fixture'] ?? null) ? $appearance['fixture'] : [];
        $participants = $this->participantsById($fixture['participants'] ?? []);
        $teamId = (int) ($appearance['team_id'] ?? 0);
        $location = strtolower((string) ($participants[$teamId]['meta']['location'] ?? ''));
        $scoreline = $this->extractCurrentGoals(is_array($fixture['scores'] ?? null) ? $fixture['scores'] : []);
        $teamGoals = $location === 'away' ? $scoreline['away'] : $scoreline['home'];
        $opponentGoals = $location === 'away' ? $scoreline['home'] : $scoreline['away'];
        $opponentName = null;

        foreach ($participants as $participantId => $participant) {
            if ((int) $participantId === $teamId) {
                continue;
            }

            $opponentName = (string) ($participant['name'] ?? '');
            break;
        }

        return array_filter([
            'fixture_id' => (int) ($fixture['id'] ?? 0),
            'match' => $this->toStringOrNull($fixture['name'] ?? null),
            'date' => $this->toStringOrNull($fixture['starting_at'] ?? null),
            'league' => $this->toStringOrNull($fixture['league']['name'] ?? null),
            'team' => $this->toStringOrNull($participants[$teamId]['name'] ?? null),
            'opponent' => $this->toStringOrNull($opponentName),
            'score' => ($teamGoals !== null && $opponentGoals !== null) ? ($teamGoals . '-' . $opponentGoals) : null,
            'rating' => $this->toFloatOrNull($details['RATING'] ?? null),
            'minutes' => $this->toNullableInt($details['MINUTES_PLAYED'] ?? $details['CUMULATIVE_MINUTES_PLAYED'] ?? null),
            'goals' => $this->toNullableInt($details['GOALS'] ?? null),
            'assists' => $this->toNullableInt($details['ASSISTS'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function buildPlayerMatchFocus(array $candidate): array
    {
        return array_filter([
            'team_id' => (int) ($candidate['team_id'] ?? 0),
            'team' => $this->toStringOrNull($candidate['team'] ?? null),
            'location' => $this->toStringOrNull($candidate['location'] ?? null),
            'rating' => isset($candidate['rating']) && $candidate['rating'] !== null ? round((float) $candidate['rating'], 2) : null,
            'goals' => isset($candidate['goals']) ? (int) $candidate['goals'] : null,
            'assists' => isset($candidate['assists']) ? (int) $candidate['assists'] : null,
            'shots' => isset($candidate['shots']) ? (int) $candidate['shots'] : null,
            'minutes' => isset($candidate['minutes']) && $candidate['minutes'] !== null ? (int) $candidate['minutes'] : null,
            'xg' => isset($candidate['xg']) && $candidate['xg'] !== null ? round((float) $candidate['xg'], 3) : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== 0);
    }

    private function mapLineupValuesByType(array $rows): array
    {
        $lookup = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $type = strtoupper((string) ($row['type']['developer_name'] ?? ''));
            if ($type === '') {
                continue;
            }

            $lookup[$type] = $this->getNestedValue($row['data'] ?? null, ['value']);
        }

        return $lookup;
    }

    private function inferFormationFromLineups(array $lineups): ?string
    {
        $rows = [];

        foreach ($lineups as $lineup) {
            if (!is_array($lineup) || (int) ($lineup['type_id'] ?? 0) !== 11) {
                continue;
            }

            $field = (string) ($lineup['formation_field'] ?? '');
            if ($field === '' || !str_contains($field, ':')) {
                continue;
            }

            [$line] = explode(':', $field, 2);
            $row = (int) $line;
            if ($row <= 1) {
                continue;
            }

            $rows[$row] = ($rows[$row] ?? 0) + 1;
        }

        if ($rows === []) {
            return null;
        }

        ksort($rows);

        return implode('-', array_values($rows));
    }

    private function requestPaginatedCollection(string $endpoint, array $query = [], int $maxPages = 20): ?array
    {
        $page = 1;
        $items = [];

        while (true) {
            $payload = $this->request($endpoint, array_merge($query, ['page' => $page]));
            if ($payload === null) {
                return $items === [] ? null : $items;
            }

            $chunk = $this->extractCollection($payload);
            if ($chunk === null) {
                return $items === [] ? null : $items;
            }

            $items = array_merge($items, $chunk);

            $hasMore = (bool) ($payload['pagination']['has_more'] ?? $payload['meta']['pagination']['has_more'] ?? false);
            if (!$hasMore) {
                break;
            }

            $page++;
            if ($page > $maxPages) {
                break;
            }
        }

        return $items;
    }

    private function requestItem(string $endpoint, array $query = []): ?array
    {
        $payload = $this->request($endpoint, $query);

        return $this->extractItem($payload);
    }

    private function request(string $endpoint, array $query = []): ?array
    {
        $baseUrl = rtrim((string) env('SPORTMONKS_BASE_URL', env('API_FOOTBALL_BASE_URL', self::DEFAULT_BASE_URL)), '/');
        $apiToken = env('SPORTMONKS_API_KEY', env('API_FOOTBALL_KEY'));

        if (!$apiToken || !function_exists('curl_init')) {
            $this->lastApiErrorMessage = !$apiToken
                ? 'API token ausente na configuracao.'
                : 'A extensao cURL nao esta habilitada no PHP da hospedagem.';
            return null;
        }

        $query['api_token'] = $apiToken;

        $url = $baseUrl . $endpoint . '?' . http_build_query($query);
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if (!is_string($response) || $response === '' || $statusCode >= 400 || $error !== '') {
            $message = $error !== '' ? $error : ('HTTP ' . $statusCode);
            $decodedError = json_decode((string) $response, true);
            if (is_array($decodedError) && isset($decodedError['message'])) {
                $message .= ' - ' . (string) $decodedError['message'];
            }

            $this->lastApiErrorMessage = $message;
            $this->logs->logApiError('sportmonks', $endpoint, $message);

            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('SportMonks returned invalid JSON.');
        }

        return $decoded;
    }

    private function resetRequestState(): void
    {
        $this->lastApiErrorMessage = null;
        $this->lastFallbackUsed = false;
    }

    private function extractCollection(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $data = $this->extractPayloadData($payload);
        if (!is_array($data)) {
            return null;
        }

        return array_is_list($data) ? $data : [$data];
    }

    private function extractItem(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $data = $this->extractPayloadData($payload);
        if (!is_array($data)) {
            return null;
        }

        if (array_is_list($data)) {
            return $data[0] ?? null;
        }

        return $data;
    }

    private function extractPayloadData(array $payload): mixed
    {
        if (array_key_exists('data', $payload)) {
            return $payload['data'];
        }

        return $payload;
    }

    private function resolveParticipantByLocation(array $participants, string $location): array
    {
        foreach ($participants as $participant) {
            if (strtolower((string) ($participant['meta']['location'] ?? '')) === $location) {
                return $participant;
            }
        }

        $index = $location === 'home' ? 0 : 1;

        return is_array($participants[$index] ?? null) ? $participants[$index] : [];
    }

    private function normalizeParticipantTeam(array $participant, string $leagueCountry, string $fallbackName): array
    {
        return [
            'external_id' => (int) ($participant['id'] ?? 0),
            'name' => (string) ($participant['name'] ?? $fallbackName),
            'logo' => $participant['image_path'] ?? null,
            'country' => $leagueCountry,
        ];
    }

    private function buildSportmonksFixtureRaw(
        array $fixture,
        array $homeParticipant,
        array $awayParticipant,
        array $scoreline,
        string $statusShort,
        string $statusLong,
        string $leagueCountry
    ): array {
        $raw = $fixture;
        $raw['fixture'] = [
            'id' => (int) ($fixture['id'] ?? 0),
            'date' => (string) ($fixture['starting_at'] ?? ''),
            'status' => [
                'short' => $statusShort,
                'long' => $statusLong,
            ],
        ];

        $raw['league'] = is_array($raw['league'] ?? null) ? $raw['league'] : [];
        if (is_array($raw['league']['country'] ?? null)) {
            $raw['league']['country_data'] = $raw['league']['country'];
        }
        $raw['league']['country'] = $leagueCountry;
        $raw['league']['logo'] = $raw['league']['image_path'] ?? null;
        $raw['league']['season'] = (int) ($fixture['season_id'] ?? date('Y'));

        $raw['teams'] = [
            'home' => [
                'id' => (int) ($homeParticipant['id'] ?? 0),
                'name' => (string) ($homeParticipant['name'] ?? 'Mandante'),
                'logo' => $homeParticipant['image_path'] ?? null,
                'country' => $leagueCountry,
            ],
            'away' => [
                'id' => (int) ($awayParticipant['id'] ?? 0),
                'name' => (string) ($awayParticipant['name'] ?? 'Visitante'),
                'logo' => $awayParticipant['image_path'] ?? null,
                'country' => $leagueCountry,
            ],
        ];
        $raw['goals'] = [
            'home' => $scoreline['home'],
            'away' => $scoreline['away'],
        ];

        return $raw;
    }

    private function extractCurrentGoals(array $scores): array
    {
        $current = [];
        $fallback = [];

        foreach ($scores as $score) {
            if (!is_array($score)) {
                continue;
            }

            $participant = strtolower((string) ($score['score']['participant'] ?? ''));
            if ($participant !== 'home' && $participant !== 'away') {
                continue;
            }

            $goals = $this->toNullableInt($score['score']['goals'] ?? null);
            $fallback[$participant] = $goals;

            if (strtoupper((string) ($score['description'] ?? '')) === 'CURRENT') {
                $current[$participant] = $goals;
            }
        }

        return [
            'home' => $current['home'] ?? $fallback['home'] ?? null,
            'away' => $current['away'] ?? $fallback['away'] ?? null,
        ];
    }

    private function participantsById(array $participants): array
    {
        $indexed = [];

        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }

            $indexed[(int) ($participant['id'] ?? 0)] = $participant;
        }

        return $indexed;
    }

    private function normalizeThreeWayOdds(array $odds): array
    {
        $normalized = [];

        foreach ($odds as $odd) {
            if (!is_array($odd)) {
                continue;
            }

            $marketDeveloper = strtoupper((string) ($odd['market']['developer_name'] ?? ''));
            if ($marketDeveloper !== '' && $marketDeveloper !== 'FULLTIME_RESULT') {
                continue;
            }

            $bookmakerName = strtolower((string) ($odd['bookmaker']['name'] ?? ''));
            if ($bookmakerName !== '' && $bookmakerName !== 'bet365') {
                continue;
            }

            $side = $this->normalizeOddOutcomeLabel((string) ($odd['label'] ?? ''), (string) ($odd['original_label'] ?? ''));
            if ($side === null) {
                continue;
            }

            $normalized[$side] = [
                'label' => $side,
                'decimal' => $this->toFloatOrNull($odd['value'] ?? null) ?? $this->toFloatOrNull($odd['dp3'] ?? null) ?? 0.0,
                'probability' => $this->extractOddProbability($odd['probability'] ?? null, $odd['value'] ?? null),
                'american' => $odd['american'] ?? null,
                'fractional' => $odd['fractional'] ?? null,
                'winning' => (bool) ($odd['winning'] ?? false),
                'stopped' => (bool) ($odd['stopped'] ?? false),
                'last_update' => $odd['latest_bookmaker_update'] ?? null,
            ];
        }

        return isset($normalized['home'], $normalized['draw'], $normalized['away']) ? $normalized : [];
    }

    private function buildFixtureOddsSummaryFromOutcomes(int $fixtureId, array $participants, array $odds, array $fixture = []): array
    {
        $home = $this->resolveParticipantByLocation($participants, 'home');
        $away = $this->resolveParticipantByLocation($participants, 'away');
        $ordered = [
            'home' => $odds['home'],
            'draw' => $odds['draw'],
            'away' => $odds['away'],
        ];

        $favoriteSide = 'draw';
        $favoriteProbability = -1.0;
        foreach ($ordered as $side => $outcome) {
            $probability = (float) ($outcome['probability'] ?? 0);
            if ($probability > $favoriteProbability) {
                $favoriteProbability = $probability;
                $favoriteSide = $side;
            }
        }

        $probabilities = array_map(fn (array $outcome): float => (float) ($outcome['probability'] ?? 0), $ordered);
        rsort($probabilities);
        $favoriteGap = round(($probabilities[0] ?? 0) - ($probabilities[1] ?? 0), 2);

        return [
            'fixture_id' => $fixtureId,
            'round_id' => (int) ($fixture['round_id'] ?? 0),
            'fixture_name' => (string) ($fixture['name'] ?? ''),
            'starting_at' => $fixture['starting_at'] ?? null,
            'home_team' => (string) ($home['name'] ?? 'Mandante'),
            'away_team' => (string) ($away['name'] ?? 'Visitante'),
            'home_position' => $this->toNullableInt($home['meta']['position'] ?? null),
            'away_position' => $this->toNullableInt($away['meta']['position'] ?? null),
            'market_odds' => $ordered,
            'favorite_side' => $favoriteSide,
            'favorite_team' => match ($favoriteSide) {
                'home' => (string) ($home['name'] ?? 'Mandante'),
                'away' => (string) ($away['name'] ?? 'Visitante'),
                default => 'Empate',
            },
            'favorite_probability' => round($favoriteProbability, 2),
            'draw_probability' => round((float) ($odds['draw']['probability'] ?? 0), 2),
            'favorite_gap' => $favoriteGap,
            'market_balance' => $this->describeMarketBalance($favoriteGap, $favoriteProbability),
        ];
    }

    private function compactRoundFixtureOddsSummary(array $summary): array
    {
        return [
            'fixture_id' => (int) ($summary['fixture_id'] ?? 0),
            'home_team' => (string) ($summary['home_team'] ?? 'Mandante'),
            'away_team' => (string) ($summary['away_team'] ?? 'Visitante'),
            'favorite_team' => (string) ($summary['favorite_team'] ?? ''),
            'favorite_side' => (string) ($summary['favorite_side'] ?? ''),
            'favorite_probability' => round((float) ($summary['favorite_probability'] ?? 0), 2),
            'draw_probability' => round((float) ($summary['draw_probability'] ?? 0), 2),
            'favorite_gap' => round((float) ($summary['favorite_gap'] ?? 0), 2),
            'market_balance' => (string) ($summary['market_balance'] ?? ''),
        ];
    }

    private function mapStatisticDetailsByType(array $details): array
    {
        $mapped = [];

        foreach ($details as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $type = strtoupper((string) ($detail['type']['developer_name'] ?? ''));
            if ($type === '') {
                continue;
            }

            $mapped[$type] = $detail['value'] ?? null;
        }

        return $mapped;
    }

    private function extractStatCount(mixed $value): int
    {
        if (is_array($value)) {
            if (isset($value['all']['count'])) {
                return (int) round((float) $value['all']['count']);
            }

            if (isset($value['count'])) {
                return (int) round((float) $value['count']);
            }
        }

        return is_numeric($value) ? (int) round((float) $value) : 0;
    }

    private function extractStatAverage(mixed $value): float
    {
        if (is_array($value)) {
            if (isset($value['all']['average'])) {
                return (float) $value['all']['average'];
            }

            if (isset($value['average'])) {
                return (float) $value['average'];
            }
        }

        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function extractStatPercentage(mixed $value, float $fallback = 0.0): float
    {
        if (is_array($value)) {
            if (isset($value['all']['percentage'])) {
                return (float) $value['all']['percentage'];
            }

            if (isset($value['percentage'])) {
                return (float) $value['percentage'];
            }

            if (isset($value['home']) && isset($value['away']) && is_numeric($value['home']) && is_numeric($value['away'])) {
                return round((((float) $value['home']) + ((float) $value['away'])) / 2, 2);
            }

            if (is_array($value['home'] ?? null) && is_array($value['away'] ?? null)
                && isset($value['home']['percentage']) && isset($value['away']['percentage'])) {
                return round((((float) $value['home']['percentage']) + ((float) $value['away']['percentage'])) / 2, 2);
            }
        }

        return is_numeric($value) ? (float) $value : $fallback;
    }

    private function extractGoalLineRate(mixed $value, string $line, float $fallback = 0.0): float
    {
        if (!is_array($value)) {
            return $fallback;
        }

        $modernKey = 'over_' . $line;
        $node = $value[$modernKey] ?? null;
        if (is_array($node)) {
            if (isset($node['matches']['percentage'])) {
                return (float) $node['matches']['percentage'];
            }

            if (isset($node['team']['percentage'])) {
                return (float) $node['team']['percentage'];
            }
        }

        if (is_numeric($node)) {
            return (float) $node;
        }

        $node = $value['over'][$line] ?? null;
        if (is_numeric($node)) {
            return (float) $node;
        }

        if (is_array($node)) {
            if (isset($node['all']['percentage'])) {
                return (float) $node['all']['percentage'];
            }

            if (isset($node['percentage'])) {
                return (float) $node['percentage'];
            }

            if (isset($node['home']) && isset($node['away']) && is_numeric($node['home']) && is_numeric($node['away'])) {
                return round((((float) $node['home']) + ((float) $node['away'])) / 2, 2);
            }

            if (is_array($node['home'] ?? null) && is_array($node['away'] ?? null)
                && isset($node['home']['percentage']) && isset($node['away']['percentage'])) {
                return round((((float) $node['home']['percentage']) + ((float) $node['away']['percentage'])) / 2, 2);
            }
        }

        return $fallback;
    }

    private function normalizeRound(mixed $round): array
    {
        if (!is_array($round)) {
            return [];
        }

        return [
            'id' => (int) ($round['id'] ?? 0),
            'name' => (string) ($round['name'] ?? ''),
            'starting_at' => $round['starting_at'] ?? null,
            'ending_at' => $round['ending_at'] ?? null,
            'is_current' => (bool) ($round['is_current'] ?? false),
        ];
    }

    private function extractFixtureLiveContext(array $fixture, ?string $statusShort = null): array
    {
        $periods = is_array($fixture['periods'] ?? null) ? $fixture['periods'] : [];
        if ($periods === []) {
            return [];
        }

        usort($periods, function (array $left, array $right): int {
            $leftOrder = (int) ($left['sort_order'] ?? 0);
            $rightOrder = (int) ($right['sort_order'] ?? 0);

            if ($leftOrder === $rightOrder) {
                return (int) ($left['minutes'] ?? 0) <=> (int) ($right['minutes'] ?? 0);
            }

            return $leftOrder <=> $rightOrder;
        });

        $current = null;
        foreach ($periods as $period) {
            if ((bool) ($period['ticking'] ?? false)) {
                $current = $period;
                break;
            }
        }

        if (!is_array($current)) {
            $current = end($periods);
            if (!is_array($current)) {
                return [];
            }
        }

        $status = strtoupper((string) ($statusShort ?? ''));
        $isLive = (bool) ($current['ticking'] ?? false) || in_array($status, ['LIVE', '1H', 'HT', '2H', 'ET', 'BT', 'INT'], true);

        return [
            'is_live' => $isLive,
            'period' => (string) ($current['description'] ?? $statusShort ?? 'Live'),
            'minute' => isset($current['minutes']) ? (int) $current['minutes'] : null,
            'second' => isset($current['seconds']) ? (int) $current['seconds'] : null,
            'added_time' => isset($current['time_added']) ? (int) $current['time_added'] : null,
            'clock' => $this->formatLiveClock($current),
            'ticking' => (bool) ($current['ticking'] ?? false),
            'has_timer' => (bool) ($current['has_timer'] ?? false),
        ];
    }

    private function extractFixtureKeyEvents(array $events, array $participants): array
    {
        $normalized = [];

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $participantId = (int) ($event['participant_id'] ?? 0);
            $eventType = (string) ($event['type']['name'] ?? $event['addition'] ?? $event['section'] ?? 'Evento');
            $teamName = (string) ($participants[$participantId]['name'] ?? '');

            $detailParts = array_filter([
                trim((string) ($event['player_name'] ?? '')),
                trim((string) ($event['addition'] ?? '')),
                trim((string) ($event['info'] ?? '')),
                trim((string) ($event['result'] ?? '')),
            ], static fn (string $value): bool => $value !== '' && $value !== $eventType);

            $normalized[] = [
                'minute' => $this->formatElapsedTime($event),
                'team' => $teamName,
                'type' => $eventType,
                'detail' => implode(' • ', $detailParts),
                'sort_order' => (int) ($event['sort_order'] ?? 0),
                'raw' => $event,
            ];
        }

        usort($normalized, function (array $left, array $right): int {
            [$leftMinute, $leftExtra] = $this->splitElapsedTime($left['minute'] ?? '0');
            [$rightMinute, $rightExtra] = $this->splitElapsedTime($right['minute'] ?? '0');

            if ($leftMinute === $rightMinute && $leftExtra === $rightExtra) {
                return ($left['sort_order'] ?? 0) <=> ($right['sort_order'] ?? 0);
            }

            if ($leftMinute === $rightMinute) {
                return $leftExtra <=> $rightExtra;
            }

            return $leftMinute <=> $rightMinute;
        });

        return array_map(function (array $event): array {
            unset($event['sort_order']);
            return $event;
        }, $normalized);
    }

    private function extractTeamMetricSnapshot(array $lookup): array
    {
        $matchesPlayed = $this->resolveMatchesPlayedFromLookup($lookup);
        $shotsOnTarget = $this->toFloatOrNull($this->getNestedValue($lookup['SHOTS'] ?? null, ['on_target']));

        return [
            'matches_played' => $matchesPlayed,
            'rating' => $this->toFloatOrNull($this->getNestedValue($lookup['RATING'] ?? null, ['value'])) ?? 0.0,
            'win_rate' => $this->extractStatPercentage($lookup['WIN'] ?? null),
            'draw_rate' => $this->extractStatPercentage($lookup['DRAW'] ?? null),
            'loss_rate' => $this->extractStatPercentage($lookup['LOST'] ?? null),
            'goals_for_total' => $this->extractGoalsCount($lookup['GOALS'] ?? null),
            'goals_for_avg' => $this->extractStatAverage($lookup['GOALS'] ?? null),
            'goals_against_total' => $this->extractGoalsCount($lookup['GOALS_CONCEDED'] ?? null),
            'goals_against_avg' => $this->extractStatAverage($lookup['GOALS_CONCEDED'] ?? null),
            'over_1_5_rate' => $this->extractGoalLineRate($lookup['NUMBER_OF_GOALS'] ?? $lookup['GOAL_LINE'] ?? null, '1_5'),
            'over_2_5_rate' => $this->extractGoalLineRate($lookup['NUMBER_OF_GOALS'] ?? $lookup['GOAL_LINE'] ?? null, '2_5'),
            'btts_rate' => $this->extractStatPercentage($lookup['BTTS'] ?? null),
            'clean_sheet_rate' => $this->extractStatPercentage($lookup['CLEANSHEET'] ?? null),
            'failed_to_score_rate' => $this->extractStatPercentage($lookup['FAILED_TO_SCORE'] ?? null),
            'xg_expected' => $this->toFloatOrNull($this->getNestedValue($lookup['EXPECTED_GOALS'] ?? null, ['expected'])) ?? 0.0,
            'xg_actual' => $this->toFloatOrNull($this->getNestedValue($lookup['EXPECTED_GOALS'] ?? null, ['actual'])) ?? 0.0,
            'xg_difference' => $this->toFloatOrNull($this->getNestedValue($lookup['EXPECTED_GOALS'] ?? null, ['difference'])) ?? 0.0,
            'possession_avg' => $this->extractStatAverage($lookup['BALL_POSSESSION'] ?? null),
            'shots_per_game' => $this->extractStatAverage($lookup['SHOTS'] ?? null),
            'shots_on_target_total' => $shotsOnTarget ?? 0.0,
            'shots_on_target_per_game' => $matchesPlayed > 0 && $shotsOnTarget !== null ? round($shotsOnTarget / $matchesPlayed, 2) : 0.0,
            'corners_avg' => $this->extractStatAverage($lookup['CORNERS'] ?? null),
            'attacks_avg' => $this->extractStatAverage($lookup['ATTACKS'] ?? null),
            'dangerous_attacks_avg' => $this->extractStatAverage($lookup['DANGEROUS_ATTACKS'] ?? null),
            'assists_per_game' => $this->toFloatOrNull($this->getNestedValue($lookup['ASSIST_STATS'] ?? null, ['assists_per_game'])) ?? 0.0,
            'passes_per_game' => $this->toFloatOrNull($this->getNestedValue($lookup['PASS_STATS'] ?? null, ['passes_per_game'])) ?? 0.0,
            'interceptions_per_game' => $this->toFloatOrNull($this->getNestedValue($lookup['INTERCEPTION_STATS'] ?? null, ['interceptions_per_game'])) ?? 0.0,
            'tackles_per_game' => $this->extractStatAverage($lookup['TACKLES'] ?? null),
            'fouls_per_game' => $this->extractStatAverage($lookup['FOULS'] ?? null),
            'yellowcards_avg' => $this->extractStatAverage($lookup['YELLOWCARDS'] ?? null),
            'redcards_avg' => $this->extractStatAverage($lookup['REDCARDS'] ?? null),
            'scoring_frequency' => $this->toFloatOrNull($this->getNestedValue($lookup['SCORING_FREQUENCY'] ?? null, ['scoring_frequency'])) ?? 0.0,
            'most_frequent_scoring_minute' => $this->toFloatOrNull($this->getNestedValue($lookup['MOST_FREQUENT_SCORING_MINUTE'] ?? null, ['most_frequent_scoring_minute'])),
            'most_scored_half' => $this->toStringOrNull($this->getNestedValue($lookup['MOST_SCORED_HALF'] ?? null, ['most_scored_half'])),
        ];
    }

    private function buildAdvancedMetrics(array $lookup, array $metrics): array
    {
        return [
            'matches_played' => (int) ($metrics['matches_played'] ?? 0),
            'rating' => round((float) ($metrics['rating'] ?? 0), 2),
            'win_rate' => round((float) ($metrics['win_rate'] ?? 0), 2),
            'draw_rate' => round((float) ($metrics['draw_rate'] ?? 0), 2),
            'loss_rate' => round((float) ($metrics['loss_rate'] ?? 0), 2),
            'goals_for_total' => round((float) ($metrics['goals_for_total'] ?? 0), 2),
            'goals_for_avg' => round((float) ($metrics['goals_for_avg'] ?? 0), 2),
            'goals_against_total' => round((float) ($metrics['goals_against_total'] ?? 0), 2),
            'goals_against_avg' => round((float) ($metrics['goals_against_avg'] ?? 0), 2),
            'over_1_5_rate' => round((float) ($metrics['over_1_5_rate'] ?? 0), 2),
            'over_2_5_rate' => round((float) ($metrics['over_2_5_rate'] ?? 0), 2),
            'btts_rate' => round((float) ($metrics['btts_rate'] ?? 0), 2),
            'clean_sheet_rate' => round((float) ($metrics['clean_sheet_rate'] ?? 0), 2),
            'failed_to_score_rate' => round((float) ($metrics['failed_to_score_rate'] ?? 0), 2),
            'xg' => [
                'expected' => round((float) ($metrics['xg_expected'] ?? 0), 3),
                'actual' => round((float) ($metrics['xg_actual'] ?? 0), 2),
                'difference' => round((float) ($metrics['xg_difference'] ?? 0), 3),
            ],
            'possession_avg' => round((float) ($metrics['possession_avg'] ?? 0), 2),
            'shots' => [
                'per_game' => round((float) ($metrics['shots_per_game'] ?? 0), 2),
                'on_target_total' => round((float) ($metrics['shots_on_target_total'] ?? 0), 2),
                'on_target_per_game' => round((float) ($metrics['shots_on_target_per_game'] ?? 0), 2),
            ],
            'corners_avg' => round((float) ($metrics['corners_avg'] ?? 0), 2),
            'attacks_avg' => round((float) ($metrics['attacks_avg'] ?? 0), 2),
            'dangerous_attacks_avg' => round((float) ($metrics['dangerous_attacks_avg'] ?? 0), 2),
            'assists_per_game' => round((float) ($metrics['assists_per_game'] ?? 0), 2),
            'passes_per_game' => round((float) ($metrics['passes_per_game'] ?? 0), 2),
            'interceptions_per_game' => round((float) ($metrics['interceptions_per_game'] ?? 0), 2),
            'tackles_per_game' => round((float) ($metrics['tackles_per_game'] ?? 0), 2),
            'fouls_per_game' => round((float) ($metrics['fouls_per_game'] ?? 0), 2),
            'yellowcards_avg' => round((float) ($metrics['yellowcards_avg'] ?? 0), 2),
            'redcards_avg' => round((float) ($metrics['redcards_avg'] ?? 0), 2),
            'scoring_frequency' => round((float) ($metrics['scoring_frequency'] ?? 0), 2),
            'most_frequent_scoring_minute' => isset($metrics['most_frequent_scoring_minute']) ? (int) round((float) $metrics['most_frequent_scoring_minute']) : null,
            'most_scored_half' => $metrics['most_scored_half'] ?? null,
            'highest_rated_player' => $lookup['HIGHEST_RATED_PLAYER'] ?? null,
            'penalties' => $lookup['PENALTIES'] ?? null,
            'scoring_minutes' => $lookup['SCORING_MINUTES'] ?? null,
            'conceded_scoring_minutes' => $lookup['CONCEDED_SCORING_MINUTES'] ?? null,
            'most_injured_players' => $this->getNestedValue($lookup['MOST_INJURED_PLAYERS'] ?? null, ['most_injured_players']) ?? [],
            'most_appearing_players' => $this->getNestedValue($lookup['APPEARING_PLAYERS'] ?? null, ['most_appearing_players']) ?? [],
            'longest_appearing_players' => $this->getNestedValue($lookup['APPEARING_PLAYERS'] ?? null, ['longest_appearing_players']) ?? [],
            'most_substituted_players' => $this->getNestedValue($lookup['MOST_SUBSTITUTED_PLAYERS'] ?? null, ['most_substituted_players']) ?? [],
            'national_team_players' => $this->getNestedValue($lookup['NATIONAL_TEAM_PLAYERS'] ?? null, ['national_team_players']) ?? [],
        ];
    }

    private function buildSeasonComparison(array $teamPayload, array $seasonTeams, int $season, array $metricSnapshot): array
    {
        if ($seasonTeams === []) {
            return [];
        }

        $peerMetrics = [];
        foreach ($seasonTeams as $seasonTeam) {
            if (!is_array($seasonTeam)) {
                continue;
            }

            $statistics = is_array($seasonTeam['statistics'] ?? null) ? $seasonTeam['statistics'] : [];
            $statistic = $statistics[0] ?? [];
            $details = is_array($statistic['details'] ?? null) ? $statistic['details'] : [];
            $lookup = $this->mapStatisticDetailsByType($details);
            $metrics = $this->extractTeamMetricSnapshot($lookup);

            if (($metrics['matches_played'] ?? 0) <= 0) {
                continue;
            }

            $peerMetrics[] = [
                'team_id' => (int) ($seasonTeam['id'] ?? 0),
                'team_name' => (string) ($seasonTeam['name'] ?? 'Time'),
                'metrics' => $metrics,
            ];
        }

        if ($peerMetrics === []) {
            return [];
        }

        $teamRanks = [];
        $leagueAverages = [];
        $leagueLeaders = [];
        $strengths = [];
        $alerts = [];
        $metricConfigs = $this->seasonComparisonMetricConfigs();

        foreach ($metricConfigs as $metricKey => $config) {
            $samples = [];
            foreach ($peerMetrics as $peer) {
                $value = $peer['metrics'][$metricKey] ?? null;
                if (!is_numeric($value)) {
                    continue;
                }

                $samples[] = [
                    'team_id' => $peer['team_id'],
                    'team_name' => $peer['team_name'],
                    'value' => (float) $value,
                ];
            }

            if ($samples === []) {
                continue;
            }

            usort($samples, function (array $left, array $right) use ($config): int {
                if ((float) $left['value'] === (float) $right['value']) {
                    return $left['team_id'] <=> $right['team_id'];
                }

                if (($config['direction'] ?? 'desc') === 'asc') {
                    return $left['value'] <=> $right['value'];
                }

                return $right['value'] <=> $left['value'];
            });

            $currentValue = (float) ($metricSnapshot[$metricKey] ?? 0);
            $currentTeamId = (int) ($teamPayload['id'] ?? 0);
            $rank = null;

            foreach ($samples as $index => $sample) {
                if ((int) $sample['team_id'] === $currentTeamId) {
                    $rank = $index + 1;
                    break;
                }
            }

            if ($rank === null) {
                continue;
            }

            $average = round(array_sum(array_column($samples, 'value')) / count($samples), 2);
            $leader = $samples[0];

            $teamRanks[$metricKey] = [
                'label' => $config['label'],
                'value' => round($currentValue, 2),
                'rank' => $rank,
                'out_of' => count($samples),
                'direction' => $config['direction'],
                'league_average' => $average,
                'delta_vs_average' => round($currentValue - $average, 2),
            ];

            $leagueAverages[$metricKey] = $average;
            $leagueLeaders[$metricKey] = [
                'label' => $config['label'],
                'team' => $leader['team_name'],
                'value' => round((float) $leader['value'], 2),
            ];

            if (!($config['insight'] ?? false)) {
                continue;
            }

            $totalTeams = count($samples);
            $topThreshold = max(1, min(3, (int) ceil($totalTeams * 0.2)));
            $bottomThreshold = min($totalTeams, max($totalTeams - 2, (int) floor($totalTeams * 0.8)));
            $rankingLabel = $config['ranking_hint'] ?? (($config['direction'] ?? 'desc') === 'asc' ? 'quanto menor, melhor' : 'quanto maior, melhor');

            if ($rank <= $topThreshold) {
                $strengths[] = $config['label'] . ' entre os melhores da liga (' . $rankingLabel . ').';
            } elseif ($rank >= $bottomThreshold) {
                $alerts[] = $config['label'] . ' entre os piores recortes da liga (' . $rankingLabel . ').';
            }
        }

        return [
            'season_id' => $season,
            'team_count' => count($peerMetrics),
            'league_averages' => $leagueAverages,
            'team_ranks' => $teamRanks,
            'league_leaders' => $leagueLeaders,
            'strengths' => array_values(array_slice(array_unique($strengths), 0, 5)),
            'alerts' => array_values(array_slice(array_unique($alerts), 0, 5)),
        ];
    }

    private function seasonComparisonMetricConfigs(): array
    {
        return [
            'goals_for_avg' => ['label' => 'Gols marcados por jogo', 'direction' => 'desc', 'insight' => true],
            'goals_against_avg' => ['label' => 'Gols sofridos por jogo', 'direction' => 'asc', 'insight' => true],
            'win_rate' => ['label' => 'Taxa de vitorias', 'direction' => 'desc', 'insight' => true],
            'clean_sheet_rate' => ['label' => 'Taxa de clean sheets', 'direction' => 'desc', 'insight' => true],
            'failed_to_score_rate' => ['label' => 'Taxa de jogos sem marcar', 'direction' => 'asc', 'insight' => true],
            'xg_expected' => ['label' => 'xG esperado', 'direction' => 'desc', 'insight' => true],
            'possession_avg' => ['label' => 'Posse de bola media', 'direction' => 'desc', 'insight' => true],
            'shots_per_game' => ['label' => 'Finalizacoes por jogo', 'direction' => 'desc', 'insight' => true],
            'corners_avg' => ['label' => 'Escanteios por jogo', 'direction' => 'desc', 'insight' => false],
            'over_2_5_rate' => ['label' => 'Over 2.5', 'direction' => 'desc', 'insight' => false],
            'btts_rate' => ['label' => 'BTTS', 'direction' => 'desc', 'insight' => false],
            'rating' => ['label' => 'Rating medio', 'direction' => 'desc', 'insight' => true],
            'scoring_frequency' => ['label' => 'Frequencia para marcar', 'direction' => 'asc', 'insight' => true],
        ];
    }

    private function resolveMatchesPlayedFromLookup(array $lookup): int
    {
        $matchesPlayed = $this->extractStatCount($lookup['GAMES_PLAYED'] ?? null);
        if ($matchesPlayed > 0) {
            return $matchesPlayed;
        }

        return $this->extractStatCount($lookup['WIN'] ?? null)
            + $this->extractStatCount($lookup['DRAW'] ?? null)
            + $this->extractStatCount($lookup['LOST'] ?? null);
    }

    private function extractGoalsCount(mixed $value): float
    {
        $count = $this->toFloatOrNull($this->getNestedValue($value, ['all', 'count']));
        if ($count !== null) {
            return $count;
        }

        $count = $this->toFloatOrNull($this->getNestedValue($value, ['count']));
        return $count ?? 0.0;
    }

    private function getNestedValue(mixed $value, array $path): mixed
    {
        foreach ($path as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    private function toFloatOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function toStringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function firstNonEmptyString(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function firstNumericValue(array $candidates): ?float
    {
        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return round((float) $candidate, 2);
            }
        }

        return null;
    }

    private function calculateAge(?string $dateOfBirth): ?int
    {
        if ($dateOfBirth === null) {
            return null;
        }

        $timestamp = strtotime($dateOfBirth);
        if ($timestamp === false) {
            return null;
        }

        return (int) floor((time() - $timestamp) / 31556926);
    }

    private function extractRecentFixtures(mixed $latest): array
    {
        if (!is_array($latest)) {
            return [];
        }

        if (!array_is_list($latest)) {
            return [$latest];
        }

        return $latest;
    }

    private function calculateRecentRates(array $fixtures, int $teamId): array
    {
        $completed = [];
        $form = [];

        foreach ($fixtures as $fixture) {
            if (!is_array($fixture)) {
                continue;
            }

            $status = strtoupper((string) ($fixture['state']['short_name'] ?? $fixture['state']['state'] ?? ''));
            if (!in_array($status, ['FT', 'AET', 'PEN'], true)) {
                continue;
            }

            $scoreline = $this->extractCurrentGoals($fixture['scores'] ?? []);
            $participants = $this->participantsById($fixture['participants'] ?? []);
            $teamLocation = strtolower((string) ($participants[$teamId]['meta']['location'] ?? ''));
            $teamGoals = $teamLocation === 'away' ? $scoreline['away'] : $scoreline['home'];
            $opponentGoals = $teamLocation === 'away' ? $scoreline['home'] : $scoreline['away'];

            if ($teamGoals === null || $opponentGoals === null) {
                continue;
            }

            $completed[] = [
                'team_goals' => $teamGoals,
                'opponent_goals' => $opponentGoals,
            ];

            $form[] = $teamGoals > $opponentGoals ? 'W' : ($teamGoals === $opponentGoals ? 'D' : 'L');
        }

        $total = count($completed);
        if ($total === 0) {
            return [
                'over_1_5_rate' => 0.0,
                'over_2_5_rate' => 0.0,
                'btts_rate' => 0.0,
                'clean_sheet_rate' => 0.0,
                'failed_to_score_rate' => 0.0,
                'form' => [],
            ];
        }

        $over15 = 0;
        $over25 = 0;
        $btts = 0;
        $cleanSheets = 0;
        $failedToScore = 0;

        foreach ($completed as $fixture) {
            $totalGoals = $fixture['team_goals'] + $fixture['opponent_goals'];

            if ($totalGoals >= 2) {
                $over15++;
            }

            if ($totalGoals >= 3) {
                $over25++;
            }

            if ($fixture['team_goals'] >= 1 && $fixture['opponent_goals'] >= 1) {
                $btts++;
            }

            if ($fixture['opponent_goals'] === 0) {
                $cleanSheets++;
            }

            if ($fixture['team_goals'] === 0) {
                $failedToScore++;
            }
        }

        return [
            'over_1_5_rate' => round(($over15 / $total) * 100, 2),
            'over_2_5_rate' => round(($over25 / $total) * 100, 2),
            'btts_rate' => round(($btts / $total) * 100, 2),
            'clean_sheet_rate' => round(($cleanSheets / $total) * 100, 2),
            'failed_to_score_rate' => round(($failedToScore / $total) * 100, 2),
            'form' => array_slice($form, 0, 5),
        ];
    }

    private function formatElapsedTime(array $event): string
    {
        $minute = (string) ($event['minute'] ?? '0');
        $extra = $event['extra_minute'] ?? null;

        if ($extra === null || $extra === '' || (int) $extra === 0) {
            return $minute;
        }

        return $minute . '+' . (string) $extra;
    }

    private function formatLiveClock(array $period): ?string
    {
        if (!isset($period['minutes']) && !isset($period['seconds'])) {
            return null;
        }

        $minutes = max(0, (int) ($period['minutes'] ?? 0));
        $seconds = max(0, min(59, (int) ($period['seconds'] ?? 0)));

        return sprintf('%d:%02d', $minutes, $seconds);
    }

    private function formatScoreline(?int $homeScore, ?int $awayScore): string
    {
        if ($homeScore === null || $awayScore === null) {
            return '--';
        }

        return $homeScore . ' x ' . $awayScore;
    }

    private function toNullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }

    private function splitElapsedTime(string $elapsed): array
    {
        if (str_contains($elapsed, '+')) {
            [$minute, $extra] = array_map('intval', explode('+', $elapsed, 2));
            return [$minute, $extra];
        }

        return [(int) $elapsed, 0];
    }

    private function normalizeOddOutcomeLabel(string $label, string $originalLabel): ?string
    {
        $candidates = [strtolower(trim($label)), strtolower(trim($originalLabel))];

        foreach ($candidates as $candidate) {
            if (in_array($candidate, ['1', 'home'], true)) {
                return 'home';
            }

            if (in_array($candidate, ['x', 'draw'], true)) {
                return 'draw';
            }

            if (in_array($candidate, ['2', 'away'], true)) {
                return 'away';
            }
        }

        return null;
    }

    private function extractOddProbability(mixed $probability, mixed $decimal): float
    {
        if (is_string($probability)) {
            $normalized = str_replace(['%', ','], ['', '.'], trim($probability));
            if (is_numeric($normalized)) {
                return round((float) $normalized, 2);
            }
        }

        if (is_numeric($probability)) {
            return round((float) $probability, 2);
        }

        if (is_numeric($decimal) && (float) $decimal > 0) {
            return round(100 / (float) $decimal, 2);
        }

        return 0.0;
    }

    private function findFixtureRank(array $summaries, int $fixtureId): ?int
    {
        foreach ($summaries as $index => $summary) {
            if ((int) ($summary['fixture_id'] ?? 0) === $fixtureId) {
                return $index + 1;
            }
        }

        return null;
    }

    private function describeMarketBalance(float $favoriteGap, float $favoriteProbability): string
    {
        if ($favoriteProbability >= 60 || $favoriteGap >= 18) {
            return 'favorito_claro';
        }

        if ($favoriteProbability >= 50 || $favoriteGap >= 10) {
            return 'favoritismo_moderado';
        }

        return 'mercado_equilibrado';
    }
}
