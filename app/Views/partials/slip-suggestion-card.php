<?php

declare(strict_types=1);
?>
<article class="scenario-card slip-card">
    <div class="scenario-meta">
        <span class="eyebrow">Selecao sugerida</span>
        <?php view_partial('risk-badge', ['level' => $selection['risk'] ?? 'medium', 'label' => 'Risco ' . ($selection['risk'] ?? 'medio')]); ?>
    </div>
    <h3><?= e((string) ($selection['game'] ?? 'Partida')) ?></h3>
    <p class="slip-market"><?= e((string) ($selection['market'] ?? 'Mercado')) ?></p>
    <p><?= e((string) ($selection['justification'] ?? '')) ?></p>
    <div class="scenario-meta">
        <span>Confianca <?= e(format_percent((int) ($selection['confidence'] ?? 0))) ?></span>
    </div>
</article>
