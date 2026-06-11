<?php

declare(strict_types=1);

$percentage = max(0, min(100, (int) ($value ?? 0)));
?>
<div class="probability-block">
    <div class="probability-meta">
        <span><?= e($label ?? 'Probabilidade') ?></span>
        <strong><?= e(format_percent($percentage)) ?></strong>
    </div>
    <div class="probability-track">
        <span class="probability-fill" style="width: <?= $percentage ?>%"></span>
    </div>
</div>
