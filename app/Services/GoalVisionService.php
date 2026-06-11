<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\LeagueRepository;
use App\Repositories\LogRepository;
use App\Repositories\MatchAnalysisRepository;
use App\Repositories\MatchRepository;
use App\Repositories\SlipSuggestionRepository;
use App\Repositories\TeamStatsRepository;
use App\Repositories\UserRepository;
use RuntimeException;
use Throwable;

final class GoalVisionService
{
    private ApiFootballService $apiFootball;
    private OpenAIService $openAI;
    private MatchRepository $matches;
    private TeamStatsRepository $stats;
    private MatchAnalysisRepository $analyses;
    private SlipSuggestionRepository $slips;
    private LeagueRepository $leagues;
    private UserRepository $users;
    private LogRepository $logs;
    private ?string $dashboardWarning = null;

    public function __construct()
    {
        $this->apiFootball = new ApiFootballService();
        $this->openAI = new OpenAIService();
        $this->matches = new MatchRepository();
        $this->stats = new TeamStatsRepository();
        $this->analyses = new MatchAnalysisRepository();
        $this->slips = new SlipSuggestionRepository();
        $this->leagues = new LeagueRepository();
        $this->users = new UserRepository();
        $this->logs = new LogRepository();
    }

    public function dashboard(string $date, array $filters = []): array
    {
        $this->dashboardWarning = null;

        $this->syncFixturesByDate($date);
        if ($date === date('Y-m-d')) {
            $this->syncLiveFixtures();
        }

        $fixtures = $this->matches->fixturesByDate($date, [
            'league_id' => $filters['league_id'] ?? null,
            'country' => $filters['country'] ?? null,
            'status' => $filters['status'] ?? null,
        ]);

        return [
            'fixtures' => array_map(fn (array $match): array => $this->decorateMatch($match), $fixtures),
            'leagues' => $this->matches->listLeaguesForDate($date),
            'date' => $date,
            'api_warning' => $this->dashboardWarning,
        ];
    }

    public function syncFixturesByDate(string $date): array
    {
        try {
            $fixtures = $this->apiFootball->fetchFixturesByDate($date);

            if ($this->apiFootball->usedFallbackData()) {
                $this->dashboardWarning = \friendly_service_message(
                    (string) ($this->apiFootball->lastApiErrorMessage() ?? ''),
                    'A API nao respondeu como esperado. O sistema exibiu dados de contingencia para nao deixar o dashboard vazio.'
                );
            }

            $synced = $this->matches->syncFixtures($fixtures);

            if ($fixtures !== [] && $synced === []) {
                $this->dashboardWarning = 'A API respondeu, mas o MySQL nao conseguiu salvar nenhum jogo desta sincronizacao. Isso normalmente indica estrutura do banco desatualizada ou incompatibilidade em alguma coluna.';
            } elseif (count($synced) < count($fixtures) && $this->dashboardWarning === null) {
                $this->dashboardWarning = 'Alguns jogos foram recebidos da API, mas parte deles nao conseguiu ser salva no MySQL. O dashboard segue com os que entraram com sucesso.';
            }

            return $synced;
        } catch (Throwable $exception) {
            $this->logs->logApiError('goalvision', 'syncFixturesByDate', $exception->getMessage());
            app_log('error', 'Falha ao sincronizar fixtures por data.', [
                'date' => $date,
                'exception' => $exception->getMessage(),
            ]);
            $this->dashboardWarning = \friendly_service_message(
                $exception->getMessage(),
                'A sincronizacao dos jogos falhou antes de concluir. Isso costuma acontecer quando a API responde, mas o MySQL nao consegue salvar a estrutura atual dos fixtures.'
            );
            return [];
        }
    }

    public function syncLiveFixtures(): array
    {
        try {
            $fixtures = $this->apiFootball->fetchLiveFixtures();

            if ($this->dashboardWarning === null && $this->apiFootball->usedFallbackData()) {
                $this->dashboardWarning = \friendly_service_message(
                    (string) ($this->apiFootball->lastApiErrorMessage() ?? ''),
                    'A API ao vivo nao respondeu como esperado. O sistema exibiu dados de contingencia.'
                );
            }

            $synced = $this->matches->syncFixtures($fixtures);

            if ($fixtures !== [] && $synced === [] && $this->dashboardWarning === null) {
                $this->dashboardWarning = 'A API ao vivo respondeu, mas o MySQL nao conseguiu salvar os jogos recebidos.';
            } elseif (count($synced) < count($fixtures) && $this->dashboardWarning === null) {
                $this->dashboardWarning = 'Alguns jogos ao vivo nao conseguiram ser salvos no MySQL, mas o sistema manteve os que deram certo.';
            }

            $indexed = [];

            foreach ($synced as $match) {
                $indexed[(int) ($match['external_fixture_id'] ?? 0)] = $match;
            }

            return array_map(function (array $fixture) use ($indexed): array {
                $match = $indexed[(int) ($fixture['fixture_id'] ?? 0)] ?? [];

                $fixture['match_id'] = $match['id'] ?? null;
                $fixture['external_fixture_id'] = $match['external_fixture_id'] ?? $fixture['fixture_id'];

                return $fixture;
            }, $fixtures);
        } catch (Throwable $exception) {
            $this->logs->logApiError('goalvision', 'syncLiveFixtures', $exception->getMessage());
            app_log('error', 'Falha ao sincronizar fixtures ao vivo.', [
                'exception' => $exception->getMessage(),
            ]);
            if ($this->dashboardWarning === null) {
                $this->dashboardWarning = \friendly_service_message(
                    $exception->getMessage(),
                    'Nao foi possivel atualizar os jogos ao vivo agora.'
                );
            }
            return [];
        }
    }

    public function getMatchPage(int $matchId, bool $allowExternalFetch = true, bool $allowStatsRefresh = true): array
    {
        $match = $this->matches->findById($matchId);
        if ($match === null) {
            throw new RuntimeException('Partida nao encontrada.');
        }

        $raw = json_decode((string) ($match['raw_data_json'] ?? '{}'), true) ?: [];
        $season = (int) ($raw['league']['season'] ?? date('Y'));
        $roundId = (int) ($raw['round']['id'] ?? $raw['round_id'] ?? 0);
        $fixtureId = (int) $match['external_fixture_id'];
        $homeExternalTeamId = (int) ($raw['teams']['home']['id'] ?? 0);
        $awayExternalTeamId = (int) ($raw['teams']['away']['id'] ?? 0);

        $homeStats = $this->resolveMatchTeamStats(
            (int) $match['home_team_id'],
            (int) $match['league_id'],
            $season,
            $homeExternalTeamId,
            $allowStatsRefresh
        );
        $awayStats = $this->resolveMatchTeamStats(
            (int) $match['away_team_id'],
            (int) $match['league_id'],
            $season,
            $awayExternalTeamId,
            $allowStatsRefresh
        );
        $analysis = $this->analyses->findByMatchId($matchId);

        if ($analysis !== null) {
            $this->settleAnalysisIfPossible($analysis, $match);
            $analysis = $this->analyses->findByMatchId($matchId);
        }

        $events = [];
        $lineups = [];
        $fixtureStats = [];
        $fixtureContext = [];
        $homeScheduleContext = [];
        $awayScheduleContext = [];
        $roundOddsContext = [];
        $headToHead = [];

        if ($allowExternalFetch) {
            $events = $this->safeContextFetch(
                fn (): array => $this->apiFootball->fetchFixtureEvents($fixtureId)
            );
            $lineups = $this->safeContextFetch(
                fn (): array => $this->apiFootball->fetchFixtureLineups($fixtureId)
            );
            $fixtureStats = $this->safeContextFetch(
                fn (): array => $this->apiFootball->fetchFixtureStatistics($fixtureId)
            );
            $fixtureContext = $this->safeContextFetch(
                fn (): array => $this->apiFootball->fetchFixtureContext($fixtureId)
            );
            $homeScheduleContext = $this->safeContextFetch(
                fn (): array => $this->apiFootball->fetchTeamScheduleContext($homeExternalTeamId, $fixtureId)
            );
            $awayScheduleContext = $this->safeContextFetch(
                fn (): array => $this->apiFootball->fetchTeamScheduleContext($awayExternalTeamId, $fixtureId)
            );
            $roundOddsContext = $this->safeContextFetch(
                fn (): array => $this->apiFootball->fetchRoundOddsContext($roundId, $fixtureId)
            );
            $headToHead = $this->safeContextFetch(
                fn (): array => $this->apiFootball->fetchHeadToHead(
                    $homeExternalTeamId,
                    $awayExternalTeamId
                )
            );
        }

        return [
            'match' => $this->decorateMatch($match),
            'raw' => $raw,
            'home_stats' => $homeStats,
            'away_stats' => $awayStats,
            'trends' => $this->apiFootball->calculateGoalTrends($homeStats, $awayStats),
            'events' => $events,
            'lineups' => $lineups,
            'fixture_stats' => $fixtureStats,
            'fixture_context' => $fixtureContext,
            'home_schedule' => $homeScheduleContext,
            'away_schedule' => $awayScheduleContext,
            'round_odds_context' => $roundOddsContext,
            'head_to_head' => $headToHead,
            'analysis' => $analysis,
        ];
    }

    public function loadMatchPageWithAutoAnalysis(int $matchId, ?int $userId = null): array
    {
        $page = $this->getMatchPage($matchId);
        $generated = false;
        $error = null;

        if (($page['analysis'] ?? null) !== null) {
            return [
                'page' => $page,
                'generated' => false,
                'error' => null,
            ];
        }

        try {
            $analysis = $this->generateMatchAnalysisFromPage($matchId, $page, $userId);
            $page['analysis'] = $analysis;
            $generated = $analysis !== [];

            if (!$generated) {
                $error = 'A analise automatica nao retornou dados suficientes para esta partida.';
            }
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        return [
            'page' => $page,
            'generated' => $generated,
            'error' => $error,
        ];
    }

    public function generateMatchAnalysis(int $matchId, ?int $userId = null, bool $force = false): array
    {
        $match = $this->matches->findById($matchId);
        if ($match === null) {
            throw new RuntimeException('Partida nao encontrada.');
        }

        $existing = $this->analyses->findFreshByMatchId($matchId);
        if ($existing !== null && !$force) {
            return $existing;
        }

        if ($userId !== null && !$force) {
            $user = $this->users->findById($userId);
            $this->assertPlanAllows($user, 'match_analysis');
        }

        try {
            $page = $this->getMatchPage($matchId, true, true);
        } catch (Throwable $exception) {
            app_log('warning', 'Falha ao montar contexto completo da partida. Gerando analise em modo local.', [
                'match_id' => $matchId,
                'exception' => $exception->getMessage(),
            ]);

            $page = $this->buildMinimalMatchPage($match);
        }

        return $this->generateMatchAnalysisFromPage($matchId, $page, $userId, $force);
    }

    private function generateMatchAnalysisFromPage(int $matchId, array $page, ?int $userId = null, bool $force = false): array
    {
        $match = $this->matches->findById($matchId);
        if ($match === null) {
            throw new RuntimeException('Partida nao encontrada.');
        }

        if (!$force) {
            $existing = $this->analyses->findFreshByMatchId($matchId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $riskScore = $this->apiFootball->calculateRiskScore($page['trends'], [
            'home_form_score' => $this->formScore($page['home_stats']['raw']['form'] ?? []),
            'away_form_score' => $this->formScore($page['away_stats']['raw']['form'] ?? []),
        ]);

        $payload = [
            'fixture_id' => (int) $match['external_fixture_id'],
            'match' => [
                'league' => $match['league_name'],
                'date' => $match['date'],
                'status' => $match['status'],
                'home_team' => $match['home_team_name'],
                'away_team' => $match['away_team_name'],
            ],
            'home_stats' => $page['home_stats'],
            'away_stats' => $page['away_stats'],
            'season_context' => $this->buildSeasonContext($page['home_stats'], $page['away_stats']),
            'goal_trends' => $page['trends'],
            'risk_projection' => $riskScore,
            'events' => $page['events'],
            'lineups' => $page['lineups'],
            'fixture_stats' => $page['fixture_stats'],
            'fixture_context' => $page['fixture_context'],
            'match_centre' => [
                'weather' => $page['fixture_context']['weather'] ?? [],
                'sidelined' => $page['fixture_context']['sidelined'] ?? [],
                'statistics' => $page['fixture_context']['statistics'] ?? [],
            ],
            'prediction_model' => $page['fixture_context']['predictions'] ?? [],
            'featured_players' => $page['fixture_context']['featured_players'] ?? [],
            'schedule_context' => [
                'home' => $page['home_schedule'],
                'away' => $page['away_schedule'],
            ],
            'round_odds_context' => $page['round_odds_context'],
            'head_to_head' => $page['head_to_head'],
        ];

        $analysis = $this->openAI->analyzeMatch($payload, $userId);

        return $this->analyses->save($matchId, $analysis, (string) env('OPENAI_MODEL', 'gpt-4.1-mini'));
    }

    public function generateMatchAnalysisByFixtureId(int $fixtureId, ?int $userId = null, bool $force = false): array
    {
        $match = $this->matches->findByFixtureId($fixtureId);
        if ($match === null) {
            $fixture = $this->apiFootball->fetchFixtureById($fixtureId);
            if ($fixture === null) {
                throw new RuntimeException('Fixture nao encontrado.');
            }

            $match = $this->matches->syncFixture($fixture);
        }

        return $this->generateMatchAnalysis((int) $match['id'], $userId, $force);
    }

    public function matchPayloadByFixtureId(int $fixtureId): array
    {
        $match = $this->matches->findByFixtureId($fixtureId);
        if ($match === null) {
            $fixture = $this->apiFootball->fetchFixtureById($fixtureId);
            if ($fixture === null) {
                throw new RuntimeException('Fixture nao encontrado.');
            }

            $match = $this->matches->syncFixture($fixture);
        }

        return $this->getMatchPage((int) $match['id']);
    }

    public function generateSlip(int $userId, array $input): array
    {
        $user = $this->users->findById($userId);
        $this->assertPlanAllows($user, 'slip_builder');

        $date = (string) ($input['date'] ?? date('Y-m-d'));
        $this->syncFixturesByDate($date);

        $fixtures = $this->matches->fixturesByDate($date);
        $fixtures = $this->filterFixturesForSlip($fixtures, $input);
        $maxSelections = max(1, min(5, (int) ($input['maxSelections'] ?? 3)));
        $candidatePoolLimit = max(4, min(8, $maxSelections * 2));
        $maxAutoGeneratedAnalyses = max(2, min(4, $maxSelections + 1));

        if ($fixtures === []) {
            throw new RuntimeException('Nao ha jogos importados para a data e filtros selecionados.');
        }

        $analyses = [];
        $fixturesToGenerate = [];

        foreach ($fixtures as $fixture) {
            $analysis = $this->analyses->findFreshByMatchId((int) $fixture['id']);

            if ($analysis !== null) {
                $candidate = $this->buildSlipCandidate(
                    $fixture,
                    $analysis,
                    (string) ($input['marketFocus'] ?? 'mixed'),
                    (bool) ($input['excludeHighRisk'] ?? false)
                );

                if ($candidate !== null) {
                    $analyses[] = $candidate;
                }

                continue;
            }

            $fixturesToGenerate[] = $fixture;
        }

        usort($analyses, fn (array $a, array $b): int => ($b['confidence_score'] <=> $a['confidence_score']));

        if (count($analyses) < $candidatePoolLimit) {
            $remainingSlots = min($candidatePoolLimit - count($analyses), $maxAutoGeneratedAnalyses);

            foreach (array_slice($fixturesToGenerate, 0, $remainingSlots) as $fixture) {
                try {
                    $analysis = $this->generateMatchAnalysis((int) $fixture['id'], $userId, false);
                } catch (Throwable $exception) {
                    continue;
                }

                $candidate = $this->buildSlipCandidate(
                    $fixture,
                    $analysis,
                    (string) ($input['marketFocus'] ?? 'mixed'),
                    (bool) ($input['excludeHighRisk'] ?? false)
                );

                if ($candidate !== null) {
                    $analyses[] = $candidate;
                }
            }
        }

        usort($analyses, fn (array $a, array $b): int => ($b['confidence_score'] <=> $a['confidence_score']));
        $analyses = array_slice($analyses, 0, $candidatePoolLimit);

        if ($analyses === []) {
            throw new RuntimeException('Nao encontrei jogos com leitura suficiente para montar o cenario agora. Tente menos ligas, outra data ou gere algumas analises individuais primeiro.');
        }

        $slip = $this->openAI->generateSlip($input, $analyses, $userId);

        if (($slip['selections'] ?? []) === []) {
            $fallbackSelections = array_map(function (array $analysis): array {
                return [
                    'game' => $analysis['game'],
                    'market' => $analysis['preferred_market'],
                    'confidence' => $analysis['confidence_score'],
                    'risk' => $analysis['risk_level'],
                    'justification' => $this->buildSlipJustification($analysis, (string) $analysis['preferred_market'], (int) $analysis['confidence_score']),
                ];
            }, array_slice($analyses, 0, $maxSelections));

            if ($fallbackSelections === []) {
                throw new RuntimeException('A IA nao devolveu selecoes validas para este cenario. Tente novamente com menos ligas ou outro perfil.');
            }

            $slip = [
                'selections' => $fallbackSelections,
                'global_confidence' => (int) round(array_sum(array_column($fallbackSelections, 'confidence')) / max(1, count($fallbackSelections))),
                'global_risk' => (string) ($fallbackSelections[0]['risk'] ?? 'medium'),
                'explanation' => 'Cenario montado com as melhores leituras disponiveis no momento, priorizando confianca e consistencia entre os jogos selecionados.',
                'disclaimer' => 'Isto e uma analise informativa, nao uma garantia. Nao aposte valores que voce nao pode perder.',
            ];
        }

        $slip['selections'] = $this->enrichSlipSelections(
            is_array($slip['selections'] ?? null) ? $slip['selections'] : [],
            $analyses
        );

        return $this->slips->create($userId, [
            'risk_profile' => $input['riskProfile'],
            'market_focus' => $input['marketFocus'],
            'selections' => $slip['selections'],
            'global_confidence' => $slip['global_confidence'],
            'global_risk' => $slip['global_risk'],
            'explanation' => $slip['explanation'] . ' ' . $slip['disclaimer'],
        ]);
    }

    private function buildSlipCandidate(array $fixture, array $analysis, string $marketFocus, bool $excludeHighRisk): ?array
    {
        if (($analysis['confidence_score'] ?? null) === null) {
            return null;
        }

        if ($excludeHighRisk && ($analysis['risk_level'] ?? 'high') === 'high') {
            return null;
        }

        $preferredMarket = $this->preferredMarketFromAnalysis($analysis, $marketFocus);

        return [
            'match_id' => $fixture['id'],
            'game' => $fixture['home_team_name'] . ' x ' . $fixture['away_team_name'],
            'league' => $fixture['league_name'],
            'preferred_market' => $preferredMarket,
            'confidence_score' => (int) ($analysis['confidence_score'] ?? 0),
            'risk_level' => (string) ($analysis['risk_level'] ?? 'high'),
            'summary' => (string) ($analysis['summary'] ?? ''),
            'analysis' => $analysis,
        ];
    }

    private function enrichSlipSelections(array $selections, array $analyses): array
    {
        $indexedAnalyses = [];

        foreach ($analyses as $candidate) {
            $game = strtolower(trim((string) ($candidate['game'] ?? '')));
            if ($game === '') {
                continue;
            }

            $indexedAnalyses[$game] = $candidate;
        }

        return array_map(function (array $selection) use ($indexedAnalyses): array {
            $game = strtolower(trim((string) ($selection['game'] ?? '')));
            $candidate = $indexedAnalyses[$game] ?? null;

            if ($candidate === null) {
                return $selection;
            }

            $justification = trim((string) ($selection['justification'] ?? ''));
            if ($this->isWeakSlipJustification($justification)) {
                $selection['justification'] = $this->buildSlipJustification(
                    $candidate,
                    (string) ($selection['market'] ?? ''),
                    (int) ($selection['confidence'] ?? 0)
                );
            }

            return $selection;
        }, $selections);
    }

    private function isWeakSlipJustification(string $justification): bool
    {
        $normalized = function_exists('mb_strtolower')
            ? mb_strtolower(trim($justification))
            : strtolower(trim($justification));

        if ($normalized === '') {
            return true;
        }

        $genericFragments = [
            'a tendencia principal aponta',
            'selecao sustentada pelos indicadores',
            'vale usar como apoio',
            'contexto final perto da partida',
            'producao ofensiva razoavel',
        ];

        foreach ($genericFragments as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        $length = function_exists('mb_strlen') ? mb_strlen($normalized) : strlen($normalized);

        return $length < 120;
    }

    private function buildSlipJustification(array $candidate, string $market = '', int $confidence = 0): string
    {
        $analysis = is_array($candidate['analysis'] ?? null) ? $candidate['analysis'] : [];
        $marketLabel = trim($market) !== '' ? trim($market) : (string) ($candidate['preferred_market'] ?? 'Over 1.5 gols');
        $confidenceValue = $confidence > 0 ? $confidence : (int) ($candidate['confidence_score'] ?? 0);

        $over15 = (int) ($analysis['over_1_5_probability'] ?? 0);
        $over25 = (int) ($analysis['over_2_5_probability'] ?? 0);
        $btts = (int) ($analysis['btts_probability'] ?? 0);

        $probability = match ($marketLabel) {
            'Ambas marcam' => $btts,
            'Over 2.5 gols' => $over25,
            default => $over15,
        };

        $keyFactors = array_values(array_filter(array_map('strval', is_array($analysis['key_factors'] ?? null) ? $analysis['key_factors'] : []), static fn (string $value): bool => trim($value) !== ''));
        $redFlags = array_values(array_filter(array_map('strval', is_array($analysis['red_flags'] ?? null) ? $analysis['red_flags'] : []), static fn (string $value): bool => trim($value) !== ''));

        $reasonByMarket = match ($marketLabel) {
            'Ambas marcam' => 'o mercado foi puxado pela chance de os dois ataques participarem do placar',
            'Over 2.5 gols' => 'a leitura favorece um jogo mais aberto e com volume suficiente para bater a linha de 2.5',
            default => 'a linha curta de gols aparece como o caminho mais consistente para este confronto',
        };

        $parts = [
            'Chegamos em ' . $marketLabel . ' porque ' . $reasonByMarket . ', com probabilidade estimada de ' . \format_percent($probability) . ' e confianca geral de ' . \format_percent($confidenceValue) . '.',
        ];

        if (isset($keyFactors[0])) {
            $parts[] = 'O primeiro suporte vem de ' . $this->normalizeSlipSentence($keyFactors[0]) . '.';
        }

        if (isset($keyFactors[1])) {
            $parts[] = 'Tambem pesa na leitura ' . $this->normalizeSlipSentence($keyFactors[1]) . '.';
        }

        if (isset($redFlags[0])) {
            $parts[] = 'O principal cuidado e ' . $this->normalizeSlipSentence($redFlags[0]) . '.';
        }

        return implode(' ', $parts);
    }

    private function normalizeSlipSentence(string $text): string
    {
        $trimmed = trim($text);
        $trimmed = rtrim($trimmed, '.');

        if ($trimmed === '') {
            return 'contexto adicional do jogo';
        }

        $first = function_exists('mb_substr') ? mb_substr($trimmed, 0, 1) : substr($trimmed, 0, 1);
        $rest = function_exists('mb_substr') ? mb_substr($trimmed, 1) : substr($trimmed, 1);
        $first = function_exists('mb_strtolower') ? mb_strtolower($first) : strtolower($first);

        return $first . $rest;
    }

    public function history(): array
    {
        $history = $this->analyses->history();

        foreach ($history as $item) {
            $match = [
                'status' => $item['match_status'] ?? null,
                'home_score' => $item['home_score'] ?? null,
                'away_score' => $item['away_score'] ?? null,
            ];
            $this->settleAnalysisIfPossible($item, $match);
        }

        $history = $this->analyses->history();

        return [
            'items' => $history,
            'summary' => $this->performanceSummary($history),
        ];
    }

    public function userSettings(int $userId): array
    {
        $user = $this->users->findById($userId);
        if ($user === null) {
            throw new RuntimeException('Usuario nao encontrado.');
        }

        return [
            'user' => $user,
            'preferences' => $this->users->getPreferences($userId),
            'leagues' => $this->leagues->all(),
        ];
    }

    public function updateUserSettings(int $userId, array $data): array
    {
        $this->users->updateSettings($userId, $data);

        return $this->userSettings($userId);
    }

    public function adminOverview(?string $focusDate = null): array
    {
        $focusDate = $this->normalizeDate($focusDate ?? date('Y-m-d'));
        $leagues = $this->leagues->all();
        $usage = $this->logs->usageSummary();
        $matchSummary = $this->matches->adminSummary($focusDate);
        $userSummary = $this->users->adminSummary();
        $slipSummary = $this->slips->adminSummary();

        return [
            'focus_date' => $focusDate,
            'summary' => array_merge($matchSummary, $userSummary, $slipSummary, [
                'enabled_leagues' => count(array_filter($leagues, static fn (array $league): bool => !empty($league['enabled']))),
                'total_leagues' => count($leagues),
                'openai_calls' => (int) ($usage['totals']['calls'] ?? 0),
                'openai_cost_usd' => (float) ($usage['totals']['estimated_cost_usd'] ?? 0),
            ]),
            'leagues' => $leagues,
            'matches' => $this->matches->recentImported(),
            'pending_matches' => $this->matches->pendingAnalysisQueue($focusDate, 12, false),
            'recent_users' => $this->users->recentUsers(8),
            'recent_analyses' => $this->analyses->recentGenerated(8),
            'usage' => $usage,
            'api_errors' => $this->logs->apiErrors(),
        ];
    }

    public function toggleLeague(int $leagueId): void
    {
        $this->leagues->toggle($leagueId);
    }

    public function syncAdminFixtures(string $date): array
    {
        $focusDate = $this->normalizeDate($date);
        $synced = $this->syncFixturesByDate($focusDate);
        $summary = $this->matches->adminSummary($focusDate);

        return [
            'date' => $focusDate,
            'synced' => count($synced),
            'matches_on_focus_date' => (int) ($summary['matches_on_focus_date'] ?? 0),
            'pending_on_focus_date' => (int) ($summary['pending_on_focus_date'] ?? 0),
        ];
    }

    public function syncAdminLive(): array
    {
        $synced = $this->syncLiveFixtures();

        return [
            'synced' => count($synced),
        ];
    }

    public function generatePendingAnalyses(string $date, int $limit = 10): array
    {
        $focusDate = $this->normalizeDate($date);
        $limit = max(1, min(25, $limit));

        $this->syncFixturesByDate($focusDate);
        $queue = $this->matches->pendingAnalysisQueue($focusDate, $limit, false);
        $generated = 0;
        $failed = 0;

        foreach ($queue as $match) {
            try {
                $this->generateMatchAnalysis((int) $match['id'], null, false);
                $generated++;
            } catch (Throwable $exception) {
                $failed++;
                $this->logs->logApiError(
                    'goalvision',
                    'adminGeneratePendingAnalyses',
                    $exception->getMessage() . ' [match_id=' . (int) $match['id'] . ']'
                );
            }
        }

        $summary = $this->matches->adminSummary($focusDate);

        return [
            'date' => $focusDate,
            'processed' => count($queue),
            'generated' => $generated,
            'failed' => $failed,
            'remaining' => (int) ($summary['pending_on_focus_date'] ?? 0),
        ];
    }

    private function ensureTeamStats(int $localTeamId, int $leagueId, int $season, int $externalTeamId): array
    {
        $stats = $this->stats->findForTeamLeagueSeason($localTeamId, $leagueId, $season);
        if ($stats !== null && !$this->shouldRefreshTeamStats($stats)) {
            return $stats;
        }

        $normalized = $this->apiFootball->fetchTeamStatistics($externalTeamId, $this->externalLeagueId($leagueId), $season);
        $normalized['team_id'] = $localTeamId;
        $normalized['league_id'] = $leagueId;
        $this->stats->upsert($normalized);

        return (array) $this->stats->findForTeamLeagueSeason($localTeamId, $leagueId, $season);
    }

    private function resolveMatchTeamStats(
        int $localTeamId,
        int $leagueId,
        int $season,
        int $externalTeamId,
        bool $allowRefresh
    ): array {
        $stats = $this->stats->findForTeamLeagueSeason($localTeamId, $leagueId, $season);

        if ($stats !== null) {
            if (!$allowRefresh || !$this->shouldRefreshTeamStats($stats)) {
                return $stats;
            }
        } elseif (!$allowRefresh) {
            return $this->emptyTeamStats($localTeamId, $leagueId, $season);
        }

        if (!$allowRefresh) {
            return $this->emptyTeamStats($localTeamId, $leagueId, $season);
        }

        try {
            return $this->ensureTeamStats($localTeamId, $leagueId, $season, $externalTeamId);
        } catch (Throwable $exception) {
            app_log('warning', 'Falha ao atualizar team_stats para a pagina da partida.', [
                'team_id' => $localTeamId,
                'league_id' => $leagueId,
                'season' => $season,
                'exception' => $exception->getMessage(),
            ]);

            return $stats ?? $this->emptyTeamStats($localTeamId, $leagueId, $season);
        }
    }

    private function emptyTeamStats(int $teamId, int $leagueId, int $season): array
    {
        return [
            'team_id' => $teamId,
            'league_id' => $leagueId,
            'season' => $season,
            'matches_played' => 0,
            'goals_for_avg' => 0.0,
            'goals_against_avg' => 0.0,
            'over_1_5_rate' => 0.0,
            'over_2_5_rate' => 0.0,
            'btts_rate' => 0.0,
            'clean_sheet_rate' => 0.0,
            'failed_to_score_rate' => 0.0,
            'raw' => [],
        ];
    }

    private function buildMinimalMatchPage(array $match): array
    {
        $raw = json_decode((string) ($match['raw_data_json'] ?? '{}'), true) ?: [];
        $season = (int) ($raw['league']['season'] ?? date('Y'));
        $leagueId = (int) ($match['league_id'] ?? 0);
        $homeTeamId = (int) ($match['home_team_id'] ?? 0);
        $awayTeamId = (int) ($match['away_team_id'] ?? 0);

        $homeStats = $this->stats->findForTeamLeagueSeason($homeTeamId, $leagueId, $season)
            ?? $this->emptyTeamStats($homeTeamId, $leagueId, $season);
        $awayStats = $this->stats->findForTeamLeagueSeason($awayTeamId, $leagueId, $season)
            ?? $this->emptyTeamStats($awayTeamId, $leagueId, $season);

        return [
            'match' => $this->decorateMatch($match),
            'raw' => $raw,
            'home_stats' => $homeStats,
            'away_stats' => $awayStats,
            'trends' => $this->apiFootball->calculateGoalTrends($homeStats, $awayStats),
            'events' => [],
            'lineups' => [],
            'fixture_stats' => [],
            'fixture_context' => [],
            'home_schedule' => [],
            'away_schedule' => [],
            'round_odds_context' => [],
            'head_to_head' => [],
            'analysis' => $this->analyses->findByMatchId((int) ($match['id'] ?? 0)),
        ];
    }

    private function shouldRefreshTeamStats(array $stats): bool
    {
        $raw = is_array($stats['raw'] ?? null) ? $stats['raw'] : [];

        if (!isset($raw['advanced_metrics']) || !isset($raw['season_comparison'])) {
            return true;
        }

        $updatedAt = strtotime((string) ($stats['updated_at'] ?? ''));
        if ($updatedAt === false) {
            return true;
        }

        return $updatedAt < (time() - 4 * 3600);
    }

    private function buildSeasonContext(array $homeStats, array $awayStats): array
    {
        return [
            'home' => $this->compactSeasonContext($homeStats),
            'away' => $this->compactSeasonContext($awayStats),
        ];
    }

    private function compactSeasonContext(array $stats): array
    {
        $raw = is_array($stats['raw'] ?? null) ? $stats['raw'] : [];
        $form = is_array($raw['form'] ?? null) ? $raw['form'] : [];

        return [
            'team_id' => (int) ($stats['team_id'] ?? 0),
            'season' => (int) ($stats['season'] ?? 0),
            'form' => array_values(array_map('strval', $form)),
            'advanced_metrics' => is_array($raw['advanced_metrics'] ?? null) ? $raw['advanced_metrics'] : [],
            'season_comparison' => is_array($raw['season_comparison'] ?? null) ? $raw['season_comparison'] : [],
        ];
    }

    private function externalLeagueId(int $leagueId): int
    {
        $league = $this->leagues->findById($leagueId);
        return (int) ($league['external_id'] ?? $leagueId);
    }

    private function decorateMatch(array $match): array
    {
        $raw = json_decode((string) ($match['raw_data_json'] ?? '{}'), true) ?: [];
        $confidence = isset($match['confidence_score']) ? (int) $match['confidence_score'] : null;
        $risk = (string) ($match['risk_level'] ?? 'high');

        $match['league_name'] = trim((string) ($match['league_name'] ?? '')) !== ''
            ? (string) $match['league_name']
            : (string) ($raw['league']['name'] ?? 'Liga');
        $match['league_country'] = trim((string) ($match['league_country'] ?? '')) !== ''
            ? (string) $match['league_country']
            : (string) ($raw['league']['country'] ?? $raw['league']['country_name'] ?? '');
        $match['home_team_name'] = trim((string) ($match['home_team_name'] ?? '')) !== ''
            ? (string) $match['home_team_name']
            : $this->resolveTeamNameFromRaw($raw, 'home');
        $match['away_team_name'] = trim((string) ($match['away_team_name'] ?? '')) !== ''
            ? (string) $match['away_team_name']
            : $this->resolveTeamNameFromRaw($raw, 'away');

        if ($confidence !== null && $confidence >= 72 && $risk !== 'high') {
            $heat = 'Jogo quente';
        } elseif ($confidence !== null && $confidence >= 56) {
            $heat = 'Moderado';
        } else {
            $heat = 'Evitar';
        }

        $match['heat_label'] = $heat;
        $match['formatted_date'] = date('d/m H:i', strtotime((string) $match['date']));
        $match['scoreline'] = ($match['home_score'] !== null && $match['away_score'] !== null)
            ? $match['home_score'] . ' x ' . $match['away_score']
            : '--';

        $liveMeta = $this->extractLiveMetaFromRaw($raw, (string) ($match['status'] ?? ''));
        $match['live_clock'] = $liveMeta['clock'] ?? null;
        $match['live_period'] = $liveMeta['period'] ?? null;
        $match['live_round'] = $liveMeta['round'] ?? null;
        $match['live_last_event'] = $liveMeta['last_event'] ?? null;
        $match['is_live'] = $liveMeta['is_live'] ?? false;

        return $match;
    }

    private function resolveTeamNameFromRaw(array $raw, string $side): string
    {
        $directTeam = trim((string) ($raw['teams'][$side]['name'] ?? ''));
        if ($directTeam !== '') {
            return $directTeam;
        }

        $desiredLocation = $side === 'home' ? 'home' : 'away';
        foreach ((array) ($raw['participants'] ?? []) as $participant) {
            if (!is_array($participant)) {
                continue;
            }

            $location = (string) ($participant['meta']['location'] ?? '');
            if ($location !== $desiredLocation) {
                continue;
            }

            $name = trim((string) ($participant['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return $side === 'home' ? 'Mandante' : 'Visitante';
    }

    private function extractLiveMetaFromRaw(array $raw, string $status): array
    {
        $normalizedStatus = strtoupper($status);
        $periods = is_array($raw['periods'] ?? null) ? $raw['periods'] : [];
        $events = is_array($raw['events'] ?? null) ? $raw['events'] : [];
        $round = is_array($raw['round'] ?? null) ? $raw['round'] : [];

        $currentPeriod = null;
        foreach ($periods as $period) {
            if (is_array($period) && (bool) ($period['ticking'] ?? false)) {
                $currentPeriod = $period;
                break;
            }
        }

        if ($currentPeriod === null && $periods !== []) {
            usort($periods, fn (array $left, array $right): int => ((int) ($left['sort_order'] ?? 0)) <=> ((int) ($right['sort_order'] ?? 0)));
            $candidate = end($periods);
            $currentPeriod = is_array($candidate) ? $candidate : null;
        }

        $isLive = $currentPeriod !== null || in_array($normalizedStatus, ['LIVE', '1H', 'HT', '2H', 'ET', 'BT', 'INT'], true);
        if (!$isLive) {
            return ['is_live' => false];
        }

        $lastEvent = $this->formatLastLiveEvent($events, $raw['participants'] ?? []);
        $roundName = trim((string) ($round['name'] ?? ''));
        if ($roundName !== '' && !str_starts_with(strtolower($roundName), 'rodada')) {
            $roundName = 'Rodada ' . $roundName;
        }

        return [
            'is_live' => true,
            'clock' => $currentPeriod !== null ? sprintf('%d:%02d', (int) ($currentPeriod['minutes'] ?? 0), (int) ($currentPeriod['seconds'] ?? 0)) : null,
            'period' => $currentPeriod['description'] ?? 'Ao vivo',
            'round' => $roundName !== '' ? $roundName : null,
            'last_event' => $lastEvent,
        ];
    }

    private function formatLastLiveEvent(array $events, array $participants): ?string
    {
        if ($events === []) {
            return null;
        }

        $participantsById = [];
        foreach ($participants as $participant) {
            if (!is_array($participant)) {
                continue;
            }

            $participantsById[(int) ($participant['id'] ?? 0)] = (string) ($participant['name'] ?? '');
        }

        usort($events, function (array $left, array $right): int {
            $leftMinute = (int) ($left['minute'] ?? 0);
            $rightMinute = (int) ($right['minute'] ?? 0);

            if ($leftMinute === $rightMinute) {
                $leftExtra = (int) ($left['extra_minute'] ?? 0);
                $rightExtra = (int) ($right['extra_minute'] ?? 0);

                if ($leftExtra === $rightExtra) {
                    return (int) ($left['sort_order'] ?? 0) <=> (int) ($right['sort_order'] ?? 0);
                }

                return $leftExtra <=> $rightExtra;
            }

            return $leftMinute <=> $rightMinute;
        });

        $event = end($events);
        if (!is_array($event)) {
            return null;
        }

        $minute = (string) ($event['minute'] ?? '0');
        $extraMinute = (int) ($event['extra_minute'] ?? 0);
        if ($extraMinute > 0) {
            $minute .= '+' . $extraMinute;
        }

        $type = trim((string) ($event['type']['name'] ?? $event['addition'] ?? 'Evento'));
        $team = trim($participantsById[(int) ($event['participant_id'] ?? 0)] ?? '');
        $player = trim((string) ($event['player_name'] ?? ''));
        $result = trim((string) ($event['result'] ?? ''));

        $parts = array_filter([
            $minute . '\'',
            $type,
            $team !== '' ? $team : null,
            $player !== '' ? $player : null,
            $result !== '' ? $result : null,
        ], static fn (?string $value): bool => $value !== null && $value !== '');

        return $parts === [] ? null : implode(' • ', $parts);
    }

    private function performanceSummary(array $history): array
    {
        $summary = [
            'market' => ['over_1_5' => ['hits' => 0, 'total' => 0], 'over_2_5' => ['hits' => 0, 'total' => 0], 'btts' => ['hits' => 0, 'total' => 0]],
            'league' => [],
            'risk' => [],
        ];

        foreach ($history as $item) {
            if (($item['result_status'] ?? 'pending') !== 'settled') {
                continue;
            }

            foreach (['over_1_5', 'over_2_5', 'btts'] as $market) {
                $summary['market'][$market]['total']++;
                if (!empty($item[$market . '_hit'])) {
                    $summary['market'][$market]['hits']++;
                }
            }

            $league = (string) ($item['league_name'] ?? 'Liga');
            if (!isset($summary['league'][$league])) {
                $summary['league'][$league] = ['hits' => 0, 'total' => 0];
            }
            $summary['league'][$league]['total']++;
            if (!empty($item['over_1_5_hit']) || !empty($item['over_2_5_hit']) || !empty($item['btts_hit'])) {
                $summary['league'][$league]['hits']++;
            }

            $risk = (string) ($item['risk_level'] ?? 'medium');
            if (!isset($summary['risk'][$risk])) {
                $summary['risk'][$risk] = ['hits' => 0, 'total' => 0];
            }
            $summary['risk'][$risk]['total']++;
            if (!empty($item['over_1_5_hit']) || !empty($item['over_2_5_hit']) || !empty($item['btts_hit'])) {
                $summary['risk'][$risk]['hits']++;
            }
        }

        return $summary;
    }

    private function settleAnalysisIfPossible(array $analysis, array $match): void
    {
        $status = strtoupper((string) ($match['status'] ?? $match['match_status'] ?? ''));
        if (!in_array($status, ['FT', 'AET', 'PEN'], true)) {
            return;
        }

        $home = (int) ($match['home_score'] ?? 0);
        $away = (int) ($match['away_score'] ?? 0);

        $this->analyses->upsertResult((int) $analysis['id'], [
            'final_score' => $home . ' x ' . $away,
            'over_1_5_hit' => ($home + $away) >= 2 ? 1 : 0,
            'over_2_5_hit' => ($home + $away) >= 3 ? 1 : 0,
            'btts_hit' => ($home >= 1 && $away >= 1) ? 1 : 0,
            'result_status' => 'settled',
            'settled_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function assertPlanAllows(?array $user, string $feature): void
    {
        $plan = (string) ($user['plan'] ?? 'free');
        $userId = (int) ($user['id'] ?? 0);

        $limits = [
            'free' => ['match_analysis' => 3, 'slip_builder' => 0],
            'beta' => ['match_analysis' => -1, 'slip_builder' => -1],
            'pro' => ['match_analysis' => -1, 'slip_builder' => -1],
        ];

        $limit = $limits[$plan][$feature] ?? -1;
        if ($limit === -1 || $userId <= 0) {
            return;
        }

        $used = $this->logs->countFeatureUsageToday($userId, $feature);
        if ($used >= $limit) {
            throw new RuntimeException($feature === 'slip_builder'
                ? 'Seu plano atual nao inclui o Bilhete Inteligente.'
                : 'Voce atingiu o limite diario de analises do plano Free.');
        }
    }

    private function filterFixturesForSlip(array $fixtures, array $input): array
    {
        $leagueIds = array_map('intval', $input['leagueIds'] ?? []);
        if ($leagueIds !== []) {
            $fixtures = array_values(array_filter($fixtures, fn (array $fixture): bool => in_array((int) $fixture['league_id'], $leagueIds, true)));
        }

        return array_values(array_filter($fixtures, function (array $fixture) use ($input): bool {
            if (($input['status'] ?? 'NS') !== '' && ($fixture['status'] ?? 'NS') !== ($input['status'] ?? 'NS')) {
                return false;
            }

            return true;
        }));
    }

    private function preferredMarketFromAnalysis(array $analysis, string $marketFocus): string
    {
        if ($marketFocus === 'btts') {
            return 'Ambas marcam';
        }

        if ($marketFocus === 'goals') {
            return ($analysis['over_2_5_probability'] ?? 0) >= 55 ? 'Over 2.5 gols' : 'Over 1.5 gols';
        }

        $options = [
            'Over 1.5 gols' => (int) ($analysis['over_1_5_probability'] ?? 0),
            'Over 2.5 gols' => (int) ($analysis['over_2_5_probability'] ?? 0),
            'Ambas marcam' => (int) ($analysis['btts_probability'] ?? 0),
        ];
        arsort($options);

        return (string) array_key_first($options);
    }

    private function formScore(array $form): int
    {
        $score = 50;
        foreach ($form as $result) {
            if ($result === 'W') {
                $score += 10;
            } elseif ($result === 'D') {
                $score += 4;
            } elseif ($result === 'L') {
                $score -= 6;
            }
        }

        return max(0, min(100, $score));
    }

    private function normalizeDate(string $date): string
    {
        $timestamp = strtotime($date);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : date('Y-m-d');
    }

    private function safeContextFetch(callable $callback, array $fallback = []): array
    {
        try {
            $result = $callback();

            return is_array($result) ? $result : $fallback;
        } catch (Throwable $exception) {
            $this->logs->logApiError('goalvision', 'safeContextFetch', $exception->getMessage());

            return $fallback;
        }
    }
}
