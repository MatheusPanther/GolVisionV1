<?php

declare(strict_types=1);

$oddValue = is_numeric($selection['odd'] ?? null) ? (float) $selection['odd'] : null;
$riskLabel = match ($selection['risk'] ?? 'medium') {
    'low' => 'Baixo',
    'high' => 'Alto',
    default => 'Medio',
};
?>
<article class="scenario-card slip-card">
    <div class="scenario-meta">
        <span class="eyebrow">Selecao sugerida</span>
        <?php view_partial('risk-badge', ['level' => $selection['risk'] ?? 'medium', 'label' => 'Risco ' . $riskLabel]); ?>
    </div>
    <h3><?= e((string) ($selection['game'] ?? 'Partida')) ?></h3>
    <p class="slip-market"><?= e((string) ($selection['market'] ?? 'Mercado')) ?></p>
    <?php if ($oddValue !== null): ?>
        <div class="slip-odd-row">
            <span class="slip-odd-label">Odd estimada</span>
            <span class="slip-odd-value"><?= e(number_format($oddValue, 2)) ?></span>
        </div>
    <?php endif; ?>
    <p><?= e((string) ($selection['justification'] ?? '')) ?></p>
    <div class="scenario-meta">
        <span>Confianca <?= e(format_percent((int) ($selection['confidence'] ?? 0))) ?></span>
        <?php if ($oddValue !== null): ?>
            <span class="slip-odd-badge">@ <?= e(number_format($oddValue, 2)) ?></span>
        <?php endif; ?>
    </div>
</article>
