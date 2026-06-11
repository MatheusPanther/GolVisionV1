<?php

declare(strict_types=1);

$fixtures = $dashboard['fixtures'] ?? [];
$leagues = $dashboard['leagues'] ?? [];
$selectedDate = $dashboard['date'] ?? date('Y-m-d');
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Dashboard</p>
        <h1>Jogos do dia</h1>
        <p class="muted-copy">Filtre por data, liga, pais ou status e acompanhe as analises salvas por partida.</p>
    </div>
    <a href="<?= e(route('/dashboard/slip-builder', ['date' => $selectedDate])) ?>" class="btn btn-primary">Abrir Bilhete Inteligente</a>
</section>

<?php if (!empty($error)): ?>
    <?php view_partial('disclaimer-box', ['title' => 'Falha de carregamento', 'message' => $error, 'tone' => 'warning']); ?>
<?php endif; ?>

<?php view_partial('disclaimer-box', ['message' => 'Cenarios e probabilidades servem como apoio a decisao. GoalVision AI nao opera apostas, nao recebe apostas e nao promete lucro. 18+.']); ?>

<section class="glass-card filter-card">
    <form method="GET" action="<?= e(route('/dashboard')) ?>" class="filter-grid">
        <label>
            <span>Data</span>
            <input type="date" name="date" value="<?= e($selectedDate) ?>">
        </label>
        <label>
            <span>Liga</span>
            <select name="league_id">
                <option value="">Todas</option>
                <?php foreach ($leagues as $league): ?>
                    <option value="<?= (int) $league['id'] ?>" <?= selected_if($filters['league_id'] ?? '', $league['id']) ?>>
                        <?= e($league['country'] . ' • ' . $league['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>
            <span>Pais</span>
            <input type="text" name="country" value="<?= e((string) ($filters['country'] ?? '')) ?>" placeholder="Ex.: Brazil">
        </label>
        <label>
            <span>Status</span>
            <select name="status">
                <option value="">Todos</option>
                <option value="NS" <?= selected_if($filters['status'] ?? '', 'NS') ?>>Nao iniciado</option>
                <option value="1H" <?= selected_if($filters['status'] ?? '', '1H') ?>>Ao vivo 1T</option>
                <option value="2H" <?= selected_if($filters['status'] ?? '', '2H') ?>>Ao vivo 2T</option>
                <option value="FT" <?= selected_if($filters['status'] ?? '', 'FT') ?>>Finalizado</option>
            </select>
        </label>
        <button type="submit" class="btn btn-secondary">Aplicar filtros</button>
    </form>
</section>

<?php if ($fixtures === []): ?>
    <?php view_partial('empty-state', ['title' => 'Nenhum jogo encontrado', 'message' => 'Nao encontramos jogos importados para essa data no momento. Tente outra data ou aguarde a proxima sincronizacao.']); ?>
<?php else: ?>
    <section class="match-grid">
        <?php foreach ($fixtures as $match): ?>
            <?php view_partial('match-card', ['match' => $match]); ?>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
