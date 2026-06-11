<?php

declare(strict_types=1);

$formInput = $formInput ?? [
    'date' => $dashboard['date'] ?? date('Y-m-d'),
    'riskProfile' => 'balanced',
    'marketFocus' => 'mixed',
    'maxSelections' => 3,
    'leagueIds' => [],
    'excludeHighRisk' => true,
];
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Bilhete Inteligente</p>
        <h1>Cenarios informativos com multiplos jogos</h1>
        <p class="muted-copy">Monte um cenario de leitura com foco em risco, mercado preferido e limite de selecoes.</p>
    </div>
</section>

<?php view_partial('disclaimer-box', [
    'title' => 'Alerta grande',
    'message' => 'Isto e uma analise informativa, nao uma garantia. Nao aposte valores que voce nao pode perder.',
    'tone' => 'warning',
]); ?>

<?php if (!empty($error)): ?>
    <?php view_partial('disclaimer-box', ['title' => 'Atencao', 'message' => $error, 'tone' => 'warning']); ?>
<?php endif; ?>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Parametros</p>
                <h2>Monte seu cenario</h2>
            </div>
        </div>

        <form method="POST" action="<?= e(route('/dashboard/slip-builder')) ?>" class="stack-form" id="slip-builder-form">
            <?= csrf_input() ?>
            <label>
                <span>Data</span>
                <input type="date" name="date" value="<?= e((string) ($formInput['date'] ?? date('Y-m-d'))) ?>">
            </label>

            <label>
                <span>Perfil de risco</span>
                <select name="risk_profile">
                    <option value="conservative" <?= selected_if($formInput['riskProfile'] ?? '', 'conservative') ?>>Conservador</option>
                    <option value="balanced" <?= selected_if($formInput['riskProfile'] ?? '', 'balanced') ?>>Equilibrado</option>
                    <option value="bold" <?= selected_if($formInput['riskProfile'] ?? '', 'bold') ?>>Ousado</option>
                </select>
            </label>

            <label>
                <span>Mercado preferido</span>
                <select name="market_focus">
                    <option value="goals" <?= selected_if($formInput['marketFocus'] ?? '', 'goals') ?>>Gols</option>
                    <option value="btts" <?= selected_if($formInput['marketFocus'] ?? '', 'btts') ?>>Ambas marcam</option>
                    <option value="mixed" <?= selected_if($formInput['marketFocus'] ?? '', 'mixed') ?>>Misto</option>
                </select>
            </label>

            <label>
                <span>Numero maximo de selecoes</span>
                <select name="max_selections">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?= $i ?>" <?= selected_if($formInput['maxSelections'] ?? 3, $i) ?>><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </label>

            <fieldset class="checkbox-list">
                <legend>Ligas permitidas</legend>
                <?php foreach (($settings['leagues'] ?? []) as $league): ?>
                    <label class="checkbox-row">
                        <input type="checkbox" name="league_ids[]" value="<?= (int) $league['id'] ?>" <?= checked_if(in_array((int) $league['id'], $formInput['leagueIds'] ?? [], true)) ?>>
                        <span><?= e($league['country'] . ' • ' . $league['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <label class="checkbox-row">
                <input type="checkbox" name="exclude_high_risk" value="1" <?= checked_if((bool) ($formInput['excludeHighRisk'] ?? false)) ?>>
                <span>Excluir jogos de risco alto</span>
            </label>

            <button type="submit" class="btn btn-primary btn-block" id="slip-builder-submit">Gerar cenario</button>
        </form>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Cobertura do dia</p>
                <h2>Jogos disponiveis</h2>
            </div>
        </div>
        <?php if (($dashboard['fixtures'] ?? []) === []): ?>
            <?php view_partial('empty-state', ['title' => 'Sem jogos mapeados', 'message' => 'Importe ou sincronize fixtures para montar cenarios com multiplos jogos.']); ?>
        <?php else: ?>
            <ul class="mini-stats">
                <?php foreach (($dashboard['fixtures'] ?? []) as $fixture): ?>
                    <li><?= e($fixture['league_name'] . ' • ' . $fixture['home_team_name'] . ' x ' . $fixture['away_team_name']) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
</section>

<?php if ($slipResult !== null): ?>
    <section class="section-grid">
        <div class="section-head">
            <div>
                <p class="eyebrow">Resultado</p>
                <h2>Selecoes sugeridas</h2>
            </div>
            <?php view_partial('risk-badge', ['level' => $slipResult['global_risk'] ?? 'medium', 'label' => 'Risco ' . ($slipResult['global_risk'] ?? 'medio')]); ?>
        </div>

        <div class="scenario-grid">
            <?php if (($slipResult['selections'] ?? []) === []): ?>
                <?php view_partial('empty-state', ['title' => 'Sem selecoes sugeridas', 'message' => 'A IA nao encontrou combinacoes seguras o bastante com os filtros atuais. Tente menos ligas, outra data ou permita jogos de risco maior.']); ?>
            <?php else: ?>
                <?php foreach (($slipResult['selections'] ?? []) as $selection): ?>
                    <?php view_partial('slip-suggestion-card', ['selection' => $selection]); ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <article class="glass-card">
            <p><strong>Confianca global:</strong> <?= e(format_percent((int) ($slipResult['global_confidence'] ?? 0))) ?></p>
            <p><?= e((string) ($slipResult['explanation'] ?? '')) ?></p>
        </article>
    </section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('slip-builder-form');
    const button = document.getElementById('slip-builder-submit');

    if (!form || !button) {
        return;
    }

    form.addEventListener('submit', function () {
        button.disabled = true;
        button.textContent = 'Gerando cenario...';
    });
});
</script>
