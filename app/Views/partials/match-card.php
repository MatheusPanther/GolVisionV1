<?php

declare(strict_types=1);
?>
<article class="glass-card match-card">
    <div class="match-card-top">
        <div>
            <p class="eyebrow"><?= e(($match['league_country'] ?? '') . ' • ' . ($match['league_name'] ?? 'Liga')) ?></p>
            <h3 class="match-title"><?= e(($match['home_team_name'] ?? '') . ' x ' . ($match['away_team_name'] ?? '')) ?></h3>
        </div>
        <span class="heat-pill heat-<?= strtolower(str_replace(' ', '-', (string) ($match['heat_label'] ?? 'moderado'))) ?>"><?= e($match['heat_label'] ?? 'Moderado') ?></span>
    </div>

    <div class="match-teams">
        <div class="team-chip">
            <span class="team-badge"><?= strtoupper(substr((string) ($match['home_team_name'] ?? 'H'), 0, 1)) ?></span>
            <span><?= e($match['home_team_name'] ?? '') ?></span>
        </div>
        <div class="match-score-wrap">
            <div class="match-time">
                <?= e(($match['is_live'] ?? false) ? (($match['live_clock'] ?? 'Ao vivo') . (!empty($match['live_period']) ? ' • ' . $match['live_period'] : '')) : ($match['formatted_date'] ?? '')) ?>
            </div>
            <strong class="match-score"><?= e($match['scoreline'] ?? '--') ?></strong>
            <span class="status-pill"><?= e($match['status'] ?? 'NS') ?></span>
        </div>
        <div class="team-chip team-chip-right">
            <span><?= e($match['away_team_name'] ?? '') ?></span>
            <span class="team-badge"><?= strtoupper(substr((string) ($match['away_team_name'] ?? 'A'), 0, 1)) ?></span>
        </div>
    </div>

    <?php if (($match['is_live'] ?? false) || !empty($match['live_round']) || !empty($match['live_last_event'])): ?>
        <div class="live-match-meta">
            <?php if (!empty($match['live_round'])): ?>
                <span><?= e($match['live_round']) ?></span>
            <?php endif; ?>
            <?php if (!empty($match['live_last_event'])): ?>
                <span><?= e($match['live_last_event']) ?></span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($match['confidence_score'])): ?>
        <div class="probability-stack">
            <?php view_partial('probability-bar', ['label' => 'Over 1.5', 'value' => (int) ($match['over_1_5_probability'] ?? 0)]); ?>
            <?php view_partial('probability-bar', ['label' => 'Over 2.5', 'value' => (int) ($match['over_2_5_probability'] ?? 0)]); ?>
            <?php view_partial('probability-bar', ['label' => 'BTTS', 'value' => (int) ($match['btts_probability'] ?? 0)]); ?>
            <div class="card-inline-note">
                <?php view_partial('risk-badge', ['level' => $match['risk_level'] ?? 'medium']); ?>
                <span>Confianca <?= e(format_percent((int) ($match['confidence_score'] ?? 0))) ?></span>
            </div>
        </div>
    <?php else: ?>
        <p class="muted-copy">Ainda sem analise salva. Abra a partida e clique em "Gerar analise" para montar a leitura completa.</p>
    <?php endif; ?>

    <div class="card-actions">
        <a href="<?= e(route('/dashboard/match/' . (int) ($match['id'] ?? 0))) ?>" class="btn btn-primary">Ver analise</a>
        <a href="<?= e(route('/dashboard/slip-builder', ['date' => date('Y-m-d', strtotime((string) ($match['date'] ?? 'now')))])) ?>" class="btn btn-secondary">Gerar cenario</a>
    </div>
</article>
