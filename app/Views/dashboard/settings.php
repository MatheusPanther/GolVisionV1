<?php

declare(strict_types=1);

$user = $settings['user'] ?? current_user() ?? [];
$preferences = $settings['preferences'] ?? ['preferred_leagues' => [], 'preferred_markets' => [], 'excluded_leagues' => []];
$leagues = $settings['leagues'] ?? [];
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Configuracoes</p>
        <h1>Perfil, plano e preferencias</h1>
        <p class="muted-copy">Ajuste ligas, mercados preferidos e seu aceite de uso responsavel.</p>
    </div>
</section>

<?php if (!empty($error)): ?>
    <?php view_partial('disclaimer-box', ['title' => 'Atencao', 'message' => $error, 'tone' => 'warning']); ?>
<?php endif; ?>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Perfil</p>
                <h2>Dados da conta</h2>
            </div>
        </div>

        <form method="POST" action="<?= e(route('/dashboard/settings')) ?>" class="stack-form">
            <?= csrf_input() ?>
            <label>
                <span>Nome</span>
                <input type="text" name="name" value="<?= e((string) ($user['name'] ?? '')) ?>">
            </label>

            <label>
                <span>Email</span>
                <input type="email" value="<?= e((string) ($user['email'] ?? '')) ?>" disabled>
            </label>

            <div class="plan-grid">
                <article class="plan-card">
                    <span class="eyebrow">Plano atual</span>
                    <h3><?= e(plan_label((string) ($user['plan'] ?? 'free'))) ?></h3>
                    <p>Pagamento mockado neste MVP. Estrutura pronta para evolucao.</p>
                </article>
            </div>

            <fieldset class="checkbox-list">
                <legend>Preferencia de mercados</legend>
                <?php foreach (['goals' => 'Gols', 'btts' => 'Ambas marcam', 'mixed' => 'Misto'] as $key => $label): ?>
                    <label class="checkbox-row">
                        <input type="checkbox" name="preferred_markets[]" value="<?= e($key) ?>" <?= checked_if(in_array($key, $preferences['preferred_markets'] ?? [], true)) ?>>
                        <span><?= e($label) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="checkbox-list">
                <legend>Preferencia de ligas</legend>
                <?php foreach ($leagues as $league): ?>
                    <label class="checkbox-row">
                        <input type="checkbox" name="preferred_leagues[]" value="<?= (int) $league['id'] ?>" <?= checked_if(in_array((int) $league['id'], $preferences['preferred_leagues'] ?? [], true)) ?>>
                        <span><?= e($league['country'] . ' • ' . $league['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <fieldset class="checkbox-list">
                <legend>Excluir ligas</legend>
                <?php foreach ($leagues as $league): ?>
                    <label class="checkbox-row">
                        <input type="checkbox" name="excluded_leagues[]" value="<?= (int) $league['id'] ?>" <?= checked_if(in_array((int) $league['id'], $preferences['excluded_leagues'] ?? [], true)) ?>>
                        <span><?= e($league['country'] . ' • ' . $league['name']) ?></span>
                    </label>
                <?php endforeach; ?>
            </fieldset>

            <label class="checkbox-row">
                <input type="checkbox" name="is_18_confirmed" value="1" <?= checked_if(!empty($user['is_18_confirmed'])) ?>>
                <span>Confirmo que tenho 18 anos ou mais.</span>
            </label>

            <label class="checkbox-row">
                <input type="checkbox" name="accept_terms" value="1" <?= checked_if(!empty($user['accepted_terms_at'])) ?>>
                <span>Aceito os termos e entendo que as analises nao garantem resultado.</span>
            </label>

            <button type="submit" class="btn btn-primary btn-block">Salvar configuracoes</button>
        </form>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Planos</p>
                <h2>Estrutura visual do MVP</h2>
            </div>
        </div>
        <div class="plan-grid">
            <article class="plan-card">
                <h3>Free</h3>
                <p>3 analises por dia</p>
            </article>
            <article class="plan-card">
                <h3>Beta</h3>
                <p>Analises ilimitadas + slip builder</p>
            </article>
            <article class="plan-card">
                <h3>Pro</h3>
                <p>Historico avancado + alertas futuros</p>
            </article>
        </div>
        <?php view_partial('disclaimer-box', ['message' => 'Pagamento mockado neste MVP. O foco atual e estrutura, produto e uso responsavel.']); ?>
    </article>
</section>
