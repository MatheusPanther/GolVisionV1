<?php

declare(strict_types=1);

$tone = $tone ?? 'default';
?>
<div class="disclaimer-box disclaimer-<?= e($tone) ?>">
    <strong><?= e($title ?? 'Uso responsavel') ?></strong>
    <p><?= e($message ?? 'Analises probabilisticas. Nao existe garantia de resultado. Use com responsabilidade. 18+.') ?></p>
</div>
