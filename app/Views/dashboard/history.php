<?php

declare(strict_types=1);

$items = $history['items'] ?? [];
$summary = $history['summary'] ?? ['market' => [], 'league' => [], 'risk' => []];
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Historico</p>
        <h1>Analises passadas e desempenho por mercado</h1>
        <p class="muted-copy">Acompanhe o que foi sugerido, o resultado final e como os mercados se comportaram ao longo do tempo.</p>
    </div>
</section>

<?php if (!empty($error)): ?>
    <?php view_partial('disclaimer-box', ['title' => 'Atencao', 'message' => $error, 'tone' => 'warning']); ?>
<?php endif; ?>

<?php view_partial('disclaimer-box', ['message' => 'Historico para leitura de performance e calibragem. Resultados passados nao garantem comportamento futuro.']); ?>

<section class="stats-grid">
    <?php foreach (($summary['market'] ?? []) as $market => $marketData): ?>
        <?php
        $total = (int) ($marketData['total'] ?? 0);
        $hits = (int) ($marketData['hits'] ?? 0);
        $rate = $total > 0 ? (int) round(($hits / $total) * 100) : 0;
        ?>
        <article class="glass-card stat-card">
            <p class="eyebrow"><?= e(strtoupper(str_replace('_', ' ', $market))) ?></p>
            <h2><?= e(format_percent($rate)) ?></h2>
            <p><?= e($hits . ' acertos em ' . $total . ' liquidados') ?></p>
            <?php view_partial('probability-bar', ['label' => 'Taxa', 'value' => $rate]); ?>
        </article>
    <?php endforeach; ?>
</section>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Taxa por liga</p>
                <h2>Resumo simples</h2>
            </div>
        </div>
        <div class="chart-stack">
            <?php foreach (($summary['league'] ?? []) as $league => $leagueData): ?>
                <?php
                $total = max(1, (int) ($leagueData['total'] ?? 0));
                $rate = (int) round(((int) ($leagueData['hits'] ?? 0) / $total) * 100);
                ?>
                <?php view_partial('probability-bar', ['label' => $league, 'value' => $rate]); ?>
            <?php endforeach; ?>
        </div>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Taxa por risco</p>
                <h2>Perfil de leitura</h2>
            </div>
        </div>
        <div class="chart-stack">
            <?php foreach (($summary['risk'] ?? []) as $risk => $riskData): ?>
                <?php
                $total = max(1, (int) ($riskData['total'] ?? 0));
                $rate = (int) round(((int) ($riskData['hits'] ?? 0) / $total) * 100);
                ?>
                <?php view_partial('probability-bar', ['label' => ucfirst($risk), 'value' => $rate]); ?>
            <?php endforeach; ?>
        </div>
    </article>
</section>

<?php if ($items === []): ?>
    <?php view_partial('empty-state', ['title' => 'Sem historico ainda', 'message' => 'Assim que as analises forem salvas e os jogos forem liquidados, os resultados aparecem aqui.']); ?>
<?php else: ?>
    <section class="history-table-wrap glass-card">
        <table class="history-table">
            <thead>
                <tr>
                    <th>Jogo</th>
                    <th>Liga</th>
                    <th>Resumo</th>
                    <th>Resultado</th>
                    <th>Mercados</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                    <tr>
                        <td><?= e($item['home_team_name'] . ' x ' . $item['away_team_name']) ?></td>
                        <td><?= e($item['league_name'] ?? '') ?></td>
                        <td><?= e($item['summary'] ?? '') ?></td>
                        <td><?= e($item['final_score'] ?? ($item['home_score'] . ' x ' . $item['away_score'])) ?></td>
                        <td>
                            O1.5 <?= !empty($item['over_1_5_hit']) ? 'OK' : 'NO' ?> •
                            O2.5 <?= !empty($item['over_2_5_hit']) ? 'OK' : 'NO' ?> •
                            BTTS <?= !empty($item['btts_hit']) ? 'OK' : 'NO' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </section>
<?php endif; ?>
