<?php

declare(strict_types=1);

$analysis = is_array($analysis ?? null) ? $analysis : [];
$keyFactors = is_array($analysis['key_factors'] ?? null) ? array_values($analysis['key_factors']) : [];
$redFlags = is_array($analysis['red_flags'] ?? null) ? array_values($analysis['red_flags']) : [];
$scenarioGroups = [
    'Conservador' => is_array($analysis['conservative_scenario'] ?? null) ? $analysis['conservative_scenario'] : [],
    'Equilibrado' => is_array($analysis['balanced_scenario'] ?? null) ? $analysis['balanced_scenario'] : [],
    'Ousado' => is_array($analysis['bold_scenario'] ?? null) ? $analysis['bold_scenario'] : [],
];
?>
<section class="glass-card analysis-card">
    <div class="section-head">
        <div>
            <p class="eyebrow">Analise da IA</p>
            <h2>Leitura estruturada da partida</h2>
        </div>
        <?php view_partial('risk-badge', ['level' => $analysis['risk_level'] ?? 'medium']); ?>
    </div>

    <div class="analysis-grid">
        <div class="analysis-main">
            <h3>Tendencia principal</h3>
            <p><?= e($analysis['main_tendency'] ?? '') ?></p>
            <div class="probability-stack">
                <?php view_partial('probability-bar', ['label' => 'Over 1.5', 'value' => (int) ($analysis['over_1_5_probability'] ?? 0)]); ?>
                <?php view_partial('probability-bar', ['label' => 'Over 2.5', 'value' => (int) ($analysis['over_2_5_probability'] ?? 0)]); ?>
                <?php view_partial('probability-bar', ['label' => 'Ambas marcam', 'value' => (int) ($analysis['btts_probability'] ?? 0)]); ?>
            </div>
        </div>

        <div class="analysis-list">
            <h3>Fatores-chave</h3>
            <ul class="tag-list">
                <?php foreach ($keyFactors as $factor): ?>
                    <li><?= e((string) $factor) ?></li>
                <?php endforeach; ?>
            </ul>

            <h3>Alertas de risco</h3>
            <ul class="tag-list tag-list-warning">
                <?php foreach ($redFlags as $flag): ?>
                    <li><?= e((string) $flag) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>

    <div class="scenario-grid">
        <?php foreach ($scenarioGroups as $scenarioLabel => $scenario): ?>
            <article class="scenario-card">
                <p class="eyebrow"><?= e($scenarioLabel) ?></p>
                <h3><?= e((string) ($scenario['market'] ?? 'Mercado')) ?></h3>
                <p><?= e((string) ($scenario['explanation'] ?? '')) ?></p>
                <div class="scenario-meta">
                    <span>Confianca <?= e(format_percent((int) ($scenario['confidence'] ?? 0))) ?></span>
                    <span>Risco <?= e((string) ($scenario['risk'] ?? 'medium')) ?></span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="analysis-summary">
        <p><?= e($analysis['summary'] ?? '') ?></p>
        <?php view_partial('disclaimer-box', ['message' => $analysis['disclaimer'] ?? 'Analise probabilistica. Nao existe garantia de resultado. Use com responsabilidade. 18+.']); ?>
    </div>
</section>
