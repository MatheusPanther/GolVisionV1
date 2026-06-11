<?php

declare(strict_types=1);

$value = strtolower((string) ($level ?? $label ?? 'medium'));
$label = $label ?? match ($value) {
    'low' => 'Risco baixo',
    'high' => 'Risco alto',
    default => 'Risco medio',
};
?>
<span class="risk-badge risk-<?= e($value) ?>"><?= e($label) ?></span>
