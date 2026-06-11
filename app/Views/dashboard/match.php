<?php

declare(strict_types=1);

$ensureMap = static fn (mixed $value): array => is_array($value) ? $value : [];
$ensureList = static fn (mixed $value): array => is_array($value) ? array_values($value) : [];
$ensureListOfArrays = static function (mixed $value) use ($ensureList): array {
    return array_values(array_filter($ensureList($value), static fn (mixed $item): bool => is_array($item)));
};
$match = $ensureMap($data['match'] ?? []);
$analysis = is_array($data['analysis'] ?? null) ? $data['analysis'] : null;
$homeStats = $ensureMap($data['home_stats'] ?? []);
$awayStats = $ensureMap($data['away_stats'] ?? []);
$trends = $ensureMap($data['trends'] ?? []);
$fixtureContext = $ensureMap($data['fixture_context'] ?? []);
$homeSchedule = $ensureMap($data['home_schedule'] ?? []);
$awaySchedule = $ensureMap($data['away_schedule'] ?? []);
$fixtureXg = $ensureMap($fixtureContext['xg'] ?? []);
$fixtureScoreboard = $ensureMap($fixtureContext['scoreboard'] ?? []);
$lineupInsights = $ensureMap($fixtureContext['lineup_insights'] ?? []);
$fixtureWeather = $ensureMap($fixtureContext['weather'] ?? []);
$fixtureSidelined = $ensureListOfArrays($fixtureContext['sidelined'] ?? []);
$fixturePredictions = $ensureListOfArrays($fixtureContext['predictions'] ?? []);
$featuredPlayers = $ensureListOfArrays($fixtureContext['featured_players'] ?? []);
$events = $ensureListOfArrays($data['events'] ?? []);
$lineups = $ensureListOfArrays($data['lineups'] ?? []);
$headToHead = $ensureListOfArrays($data['head_to_head'] ?? []);
$analysisHint = is_string($analysisHint ?? null) ? $analysisHint : null;
$analysisGenerationFailed = !empty($analysisGenerationFailed);
$analysisGenerationMessage = is_string($analysisGenerationMessage ?? null) ? $analysisGenerationMessage : null;
$leagueParts = array_values(array_filter([
    trim((string) ($match['league_country'] ?? '')),
    trim((string) ($match['league_name'] ?? 'Liga')),
], static fn (string $value): bool => $value !== ''));
$matchTitleHome = trim((string) ($match['home_team_name'] ?? ''));
$matchTitleAway = trim((string) ($match['away_team_name'] ?? ''));
$matchTitle = trim($matchTitleHome . ($matchTitleHome !== '' && $matchTitleAway !== '' ? ' x ' : '') . $matchTitleAway);
if ($matchTitle === '') {
    $matchTitle = 'Partida em analise';
}
$formatScheduleDate = static function (?string $date): string {
    if (!is_string($date) || trim($date) === '') {
        return '--';
    }

    $timestamp = strtotime($date);

    return $timestamp !== false ? date('d/m H:i', $timestamp) : $date;
};
$formatScheduleFixture = static function (array $fixture) use ($formatScheduleDate, $ensureMap): string {
    $opponentData = $ensureMap($fixture['opponent'] ?? []);
    $opponent = (string) ($opponentData['name'] ?? 'Adversario');
    $location = (string) ($fixture['location'] ?? '');
    $prefix = $location === 'away' ? 'fora vs ' : 'casa x ';
    $score = is_string($fixture['score'] ?? null) ? ' • ' . $fixture['score'] : '';
    $competition = trim((string) ($fixture['competition'] ?? ''));
    $competitionLabel = $competition !== '' ? ' • ' . $competition : '';

    return $formatScheduleDate($fixture['date'] ?? null) . ' • ' . $prefix . $opponent . $score . $competitionLabel;
};
?>
<section class="page-head">
    <div>
        <p class="eyebrow"><?= e($leagueParts !== [] ? implode(' • ', $leagueParts) : 'Liga') ?></p>
        <h1><?= e($matchTitle) ?></h1>
        <p class="muted-copy">
            <?= e(($match['is_live'] ?? false) ? ('Ao vivo ' . ($match['live_clock'] ?? '--') . (!empty($match['live_period']) ? ' • ' . $match['live_period'] : '')) : ('Horario ' . ($match['formatted_date'] ?? ''))) ?>
            • Status <?= e($match['status'] ?? 'NS') ?>
            <?php if (!empty($match['live_round'])): ?>
                • <?= e($match['live_round']) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <?php view_partial('risk-badge', ['label' => $match['heat_label'] ?? 'Moderado', 'level' => $analysis['risk_level'] ?? 'medium']); ?>
        <form method="POST" action="<?= e(route('/dashboard/match/' . (int) ($match['id'] ?? 0) . '/analyze')) ?>">
            <?= csrf_input() ?>
            <button type="submit" class="btn btn-primary"><?= $analysis !== null ? 'Regenerar analise' : 'Gerar analise' ?></button>
        </form>
    </div>
</section>

<?php view_partial('disclaimer-box', ['message' => 'Analise probabilistica e informativa. Nao existe garantia de resultado. Use com responsabilidade. 18+.']); ?>

<?php if ($analysis === null && ($analysisHint !== null || $analysisGenerationMessage !== null)): ?>
    <div class="glass-card" style="margin-bottom: 1.5rem;">
        <p class="muted-copy" style="margin:0;">
            <?= e($analysisGenerationMessage ?? $analysisHint ?? 'Clique em "Gerar analise" para montar a leitura completa desta partida.') ?>
        </p>
    </div>
<?php endif; ?>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Resumo do jogo</p>
                <h2>Indicadores centrais</h2>
            </div>
            <span class="status-pill"><?= e($match['scoreline'] ?? '--') ?></span>
        </div>
        <div class="probability-stack">
            <?php view_partial('probability-bar', ['label' => 'Over 1.5 historico', 'value' => $trends['over_1_5_probability'] ?? 0]); ?>
            <?php view_partial('probability-bar', ['label' => 'Over 2.5 historico', 'value' => $trends['over_2_5_probability'] ?? 0]); ?>
            <?php view_partial('probability-bar', ['label' => 'BTTS historico', 'value' => $trends['btts_probability'] ?? 0]); ?>
        </div>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Forma recente</p>
                <h2>Mandante x visitante</h2>
            </div>
        </div>
        <div class="stats-compare">
            <div class="stat-team">
                <h3><?= e($match['home_team_name'] ?? '') ?></h3>
                <ul class="mini-stats">
                    <li>Gols pro: <?= e(number_format((float) ($homeStats['goals_for_avg'] ?? 0), 2)) ?></li>
                    <li>Gols contra: <?= e(number_format((float) ($homeStats['goals_against_avg'] ?? 0), 2)) ?></li>
                    <li>Over 1.5: <?= e(format_percent($homeStats['over_1_5_rate'] ?? 0)) ?></li>
                    <li>Over 2.5: <?= e(format_percent($homeStats['over_2_5_rate'] ?? 0)) ?></li>
                    <li>BTTS: <?= e(format_percent($homeStats['btts_rate'] ?? 0)) ?></li>
                </ul>
            </div>
            <div class="stat-team">
                <h3><?= e($match['away_team_name'] ?? '') ?></h3>
                <ul class="mini-stats">
                    <li>Gols pro: <?= e(number_format((float) ($awayStats['goals_for_avg'] ?? 0), 2)) ?></li>
                    <li>Gols contra: <?= e(number_format((float) ($awayStats['goals_against_avg'] ?? 0), 2)) ?></li>
                    <li>Over 1.5: <?= e(format_percent($awayStats['over_1_5_rate'] ?? 0)) ?></li>
                    <li>Over 2.5: <?= e(format_percent($awayStats['over_2_5_rate'] ?? 0)) ?></li>
                    <li>BTTS: <?= e(format_percent($awayStats['btts_rate'] ?? 0)) ?></li>
                </ul>
            </div>
        </div>
    </article>
</section>

<?php if ($analysis !== null): ?>
    <?php view_partial('analysis-card', ['analysis' => $analysis]); ?>
<?php else: ?>
    <?php view_partial('empty-state', [
        'title' => $analysisGenerationFailed ? 'Analise ainda nao gerada' : 'Analise pronta para gerar',
        'message' => $analysisGenerationMessage
            ?? $analysisHint
            ?? 'Clique em "Gerar analise" para montar a leitura completa desta partida com base nos dados ja importados.',
    ]); ?>
<?php endif; ?>

<?php if ($fixtureContext !== []): ?>
    <section class="two-column-grid">
        <article class="glass-card">
            <div class="section-head">
                <div>
                    <p class="eyebrow">xG Match</p>
                    <h2>Leitura da partida</h2>
                </div>
            </div>
            <div class="stats-compare">
                <?php foreach (['home', 'away'] as $side): ?>
                    <?php $teamXg = $ensureMap($fixtureXg[$side] ?? []); ?>
                    <?php $teamScore = $ensureMap($fixtureScoreboard[$side] ?? []); ?>
                    <?php if ($teamXg === [] && $teamScore === []): ?>
                        <?php continue; ?>
                    <?php endif; ?>
                    <div class="stat-team">
                        <h3><?= e((string) ($teamXg['team'] ?? $teamScore['team'] ?? 'Time')) ?></h3>
                        <ul class="mini-stats">
                            <li>Gols: <?= e((string) ($teamScore['current'] ?? '--')) ?></li>
                            <li>xG: <?= e(($teamXg['xg'] ?? null) !== null ? number_format((float) $teamXg['xg'], 2) : '--') ?></li>
                            <li>xGoT: <?= e(($teamXg['xgot'] ?? null) !== null ? number_format((float) $teamXg['xgot'], 2) : '--') ?></li>
                            <li>xPTS: <?= e(($teamXg['xpts'] ?? null) !== null ? number_format((float) $teamXg['xpts'], 2) : '--') ?></li>
                            <li>xGA: <?= e(($teamXg['xga'] ?? null) !== null ? number_format((float) $teamXg['xga'], 2) : '--') ?></li>
                        </ul>
                    </div>
                <?php endforeach; ?>
            </div>
        </article>

        <article class="glass-card">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Destaques individuais</p>
                    <h2>Rating e presenca ofensiva</h2>
                </div>
            </div>
            <?php if ($lineupInsights === []): ?>
                <p class="muted-copy">Lineups detalhadas ainda nao disponiveis para esta partida.</p>
            <?php else: ?>
                <div class="context-stack">
                    <?php foreach (['home', 'away'] as $side): ?>
                        <?php $teamInsight = $ensureMap($lineupInsights[$side] ?? []); ?>
                        <?php if ($teamInsight === []): ?>
                            <?php continue; ?>
                        <?php endif; ?>
                        <?php $topRatedPlayers = $ensureListOfArrays($teamInsight['top_rated_players'] ?? []); ?>
                        <?php $topXgPlayers = $ensureListOfArrays($teamInsight['top_xg_players'] ?? []); ?>
                        <div>
                            <h3><?= e((string) ($teamInsight['team'] ?? 'Time')) ?> • Formacao <?= e((string) ($teamInsight['formation'] ?? 'N/A')) ?></h3>
                            <ul class="mini-stats">
                                <?php foreach (array_slice($topRatedPlayers, 0, 2) as $player): ?>
                                    <li>
                                        <?= e((string) ($player['name'] ?? 'Jogador')) ?>
                                        • Rating <?= e(number_format((float) ($player['rating'] ?? 0), 2)) ?>
                                        • G/A <?= e((string) ($player['goals'] ?? 0)) ?>/<?= e((string) ($player['assists'] ?? 0)) ?>
                                    </li>
                                <?php endforeach; ?>
                                <?php $xgLeader = ($topXgPlayers[0] ?? null); ?>
                                <?php if (is_array($xgLeader)): ?>
                                    <li>
                                        xG lider: <?= e((string) ($xgLeader['name'] ?? 'Jogador')) ?>
                                        • xG <?= e(($xgLeader['xg'] ?? null) !== null ? number_format((float) $xgLeader['xg'], 2) : '--') ?>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </article>
    </section>
<?php endif; ?>

<?php if ($fixtureWeather !== [] || $fixtureSidelined !== [] || $fixturePredictions !== [] || $featuredPlayers !== []): ?>
    <section class="two-column-grid">
        <article class="glass-card">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Match centre</p>
                    <h2>Clima e desfalques</h2>
                </div>
            </div>
            <div class="context-stack">
                <?php if ($fixtureWeather !== []): ?>
                    <div>
                        <h3>Weather report</h3>
                        <ul class="mini-stats">
                            <?php if (!empty($fixtureWeather['description'])): ?>
                                <li>Condicao: <?= e((string) $fixtureWeather['description']) ?></li>
                            <?php endif; ?>
                            <?php if (isset($fixtureWeather['temperature_c'])): ?>
                                <li>Temperatura: <?= e(number_format((float) $fixtureWeather['temperature_c'], 1)) ?> C</li>
                            <?php endif; ?>
                            <?php if (isset($fixtureWeather['humidity'])): ?>
                                <li>Umidade: <?= e(number_format((float) $fixtureWeather['humidity'], 0)) ?>%</li>
                            <?php endif; ?>
                            <?php if (isset($fixtureWeather['wind_kph'])): ?>
                                <li>Vento: <?= e(number_format((float) $fixtureWeather['wind_kph'], 1)) ?> km/h</li>
                            <?php endif; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($fixtureSidelined !== []): ?>
                    <div>
                        <h3>Sidelined</h3>
                        <ul class="mini-stats">
                            <?php foreach (array_slice($fixtureSidelined, 0, 4) as $teamUnavailable): ?>
                                <li>
                                    <?= e((string) ($teamUnavailable['team'] ?? 'Time')) ?>
                                    • <?= e((string) ($teamUnavailable['count'] ?? 0)) ?> ausencia(s)
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </article>

        <article class="glass-card">
            <div class="section-head">
                <div>
                    <p class="eyebrow">Modelo e jogadores</p>
                    <h2>Leituras extras</h2>
                </div>
            </div>
            <div class="context-stack">
                <?php if ($fixturePredictions !== []): ?>
                    <div>
                        <h3>Prediction model</h3>
                        <ul class="mini-stats">
                            <?php foreach (array_slice($fixturePredictions, 0, 3) as $prediction): ?>
                                <?php
                                $pairs = [];
                                $probabilities = $ensureMap($prediction['probabilities'] ?? []);
                                foreach ($probabilities as $label => $value) {
                                    $pairs[] = strtoupper((string) $label) . ': ' . (is_numeric($value) ? number_format((float) $value, 1) . '%' : (string) $value);
                                }
                                ?>
                                <li><?= e((string) ($prediction['market'] ?? 'Prediction')) ?> • <?= e(implode(' | ', $pairs)) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?php if ($featuredPlayers !== []): ?>
                    <div>
                        <h3>Perfis em foco</h3>
                        <ul class="mini-stats">
                            <?php foreach (array_slice($featuredPlayers, 0, 4) as $player): ?>
                                <?php $currentTeam = $ensureMap($player['current_team'] ?? []); ?>
                                <?php $matchFocus = $ensureMap($player['match_focus'] ?? []); ?>
                                <li>
                                    <?= e((string) ($player['name'] ?? 'Jogador')) ?>
                                    • <?= e((string) ($player['position'] ?? 'Posicao n/d')) ?>
                                    <?php if (!empty($currentTeam['team'])): ?>
                                        • <?= e((string) $currentTeam['team']) ?>
                                    <?php endif; ?>
                                    <?php if (isset($matchFocus['rating'])): ?>
                                        • Rating <?= e(number_format((float) $matchFocus['rating'], 2)) ?>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        </article>
    </section>
<?php endif; ?>

<?php if ($homeSchedule !== [] || $awaySchedule !== []): ?>
    <section class="two-column-grid">
        <?php foreach ([
            ['label' => $match['home_team_name'] ?? 'Mandante', 'context' => $homeSchedule],
            ['label' => $match['away_team_name'] ?? 'Visitante', 'context' => $awaySchedule],
        ] as $scheduleCard): ?>
            <?php
            $schedule = is_array($scheduleCard['context'] ?? null) ? $scheduleCard['context'] : [];
            $summary = is_array($schedule['summary'] ?? null) ? $schedule['summary'] : [];
            $recentFixtures = $ensureListOfArrays($schedule['recent_fixtures'] ?? []);
            $upcomingFixtures = $ensureListOfArrays($schedule['upcoming_fixtures'] ?? []);
            $alerts = $ensureList($schedule['alerts'] ?? []);
            $recentForm = is_array($summary['recent_form'] ?? null) ? $summary['recent_form'] : [];
            ?>
            <article class="glass-card">
                <div class="section-head">
                    <div>
                        <p class="eyebrow">Calendario do time</p>
                        <h2><?= e((string) ($scheduleCard['label'] ?? 'Time')) ?></h2>
                    </div>
                </div>
                <?php if ($schedule === []): ?>
                    <p class="muted-copy">Calendario ainda indisponivel para este time.</p>
                <?php else: ?>
                    <div class="context-stack">
                        <div>
                            <ul class="mini-stats">
                                <li>Forma recente: <?= e(implode('-', array_map('strval', $recentForm)) ?: '--') ?></li>
                                <li>Descanso antes do jogo: <?= e(isset($summary['days_since_previous']) ? ((string) $summary['days_since_previous'] . ' dia(s)') : '--') ?></li>
                                <li>Proximo jogo apos este: <?= e(isset($summary['days_until_next']) ? ((string) $summary['days_until_next'] . ' dia(s)') : '--') ?></li>
                                <li>Jogos nos 14 dias anteriores: <?= e((string) ($summary['matches_last_14_days'] ?? '--')) ?></li>
                                <li>Jogos nos proximos 14 dias: <?= e((string) ($summary['matches_next_14_days'] ?? '--')) ?></li>
                                <li>Competicoes ativas: <?= e((string) ($summary['competitions_count'] ?? '--')) ?></li>
                            </ul>
                        </div>
                        <?php if ($alerts !== []): ?>
                            <div>
                                <h3>Alertas de sequencia</h3>
                                <ul class="mini-stats">
                                    <?php foreach (array_slice($alerts, 0, 3) as $alert): ?>
                                        <li><?= e((string) $alert) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        <div>
                            <h3>Ultimos jogos</h3>
                            <ul class="mini-stats">
                                <?php foreach (array_slice($recentFixtures, 0, 3) as $fixture): ?>
                                    <li><?= e($formatScheduleFixture($fixture)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <div>
                            <h3>Proximos jogos</h3>
                            <ul class="mini-stats">
                                <?php foreach (array_slice($upcomingFixtures, 0, 3) as $fixture): ?>
                                    <li><?= e($formatScheduleFixture($fixture)) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Eventos ao vivo</p>
                <h2>Se disponiveis</h2>
            </div>
        </div>
        <?php if ($events === []): ?>
            <p class="muted-copy">Nenhum evento disponivel no momento.</p>
        <?php else: ?>
            <ul class="timeline-list">
                <?php foreach ($events as $event): ?>
                    <?php $eventTime = $ensureMap($event['time'] ?? []); ?>
                    <li>
                        <strong><?= e((string) ($eventTime['elapsed'] ?? '--')) ?>'</strong>
                        <span><?= e((string) ($event['type'] ?? 'Evento')) ?> • <?= e((string) ($event['detail'] ?? '')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Lineups e H2H</p>
                <h2>Contexto adicional</h2>
            </div>
        </div>
        <div class="context-stack">
            <div>
                <h3>Lineups</h3>
                <ul class="mini-stats">
                    <?php foreach ($lineups as $lineup): ?>
                        <?php $lineupTeam = $ensureMap($lineup['team'] ?? []); ?>
                        <li><?= e((string) ($lineupTeam['name'] ?? 'Time')) ?> • Formacao <?= e((string) ($lineup['formation'] ?? 'N/A')) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div>
                <h3>Head to head recente</h3>
                <ul class="mini-stats">
                    <?php foreach ($headToHead as $h2h): ?>
                        <li>Placar <?= e((string) ($h2h['score'] ?? '--')) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </article>
</section>
