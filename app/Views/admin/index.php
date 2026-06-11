<?php

declare(strict_types=1);

$usage = $overview['usage'] ?? ['totals' => [], 'by_feature' => []];
$summary = $overview['summary'] ?? [];
$focusDate = (string) ($overview['focus_date'] ?? date('Y-m-d'));
?>
<section class="page-head">
    <div>
        <p class="eyebrow">Admin</p>
        <h1>Painel operacional</h1>
        <p class="muted-copy">Sincronize fixtures, gere analises em lote, monitore usuarios, filas pendentes e uso operacional da IA.</p>
    </div>
    <form method="GET" action="<?= e(route('/admin')) ?>" class="admin-filter-form">
        <label>
            <span>Data operacional</span>
            <input type="date" name="date" value="<?= e($focusDate) ?>">
        </label>
        <button type="submit" class="btn btn-secondary">Aplicar</button>
    </form>
</section>

<?php if (!empty($error)): ?>
    <?php view_partial('disclaimer-box', ['title' => 'Atencao', 'message' => $error, 'tone' => 'warning']); ?>
<?php endif; ?>

<section class="stats-grid">
    <article class="glass-card stat-card">
        <p class="eyebrow">Usuarios</p>
        <h2><?= e((string) ($summary['total_users'] ?? 0)) ?></h2>
        <p><?= e((string) ($summary['new_users_today'] ?? 0)) ?> novo(s) hoje • Beta <?= e((string) ($summary['beta_users'] ?? 0)) ?> • Pro <?= e((string) ($summary['pro_users'] ?? 0)) ?></p>
    </article>
    <article class="glass-card stat-card">
        <p class="eyebrow">Jogos importados</p>
        <h2><?= e((string) ($summary['total_matches'] ?? 0)) ?></h2>
        <p><?= e((string) ($summary['matches_on_focus_date'] ?? 0)) ?> jogo(s) em <?= e(date('d/m', strtotime($focusDate))) ?> • Ao vivo <?= e((string) ($summary['live_matches'] ?? 0)) ?></p>
    </article>
    <article class="glass-card stat-card">
        <p class="eyebrow">Analises prontas</p>
        <h2><?= e((string) ($summary['analyzed_matches'] ?? 0)) ?></h2>
        <p><?= e((string) ($summary['analyzed_on_focus_date'] ?? 0)) ?> prontas na data foco</p>
    </article>
    <article class="glass-card stat-card">
        <p class="eyebrow">Fila pendente</p>
        <h2><?= e((string) ($summary['pending_analyses'] ?? 0)) ?></h2>
        <p><?= e((string) ($summary['pending_on_focus_date'] ?? 0)) ?> jogo(s) sem analise em <?= e(date('d/m', strtotime($focusDate))) ?></p>
    </article>
    <article class="glass-card stat-card">
        <p class="eyebrow">Ligas ativas</p>
        <h2><?= e((string) ($summary['enabled_leagues'] ?? 0)) ?></h2>
        <p>Total cadastradas <?= e((string) ($summary['total_leagues'] ?? 0)) ?></p>
    </article>
    <article class="glass-card stat-card">
        <p class="eyebrow">OpenAI e slips</p>
        <h2><?= e((string) ($summary['openai_calls'] ?? 0)) ?></h2>
        <p>USD <?= e(number_format((float) ($summary['openai_cost_usd'] ?? 0), 4)) ?> • slips <?= e((string) ($summary['total_slips'] ?? 0)) ?> total / <?= e((string) ($summary['slips_today'] ?? 0)) ?> hoje</p>
    </article>
</section>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Operacao</p>
                <h2>Sincronizacao e fila</h2>
            </div>
        </div>
        <div class="stack-form">
            <form method="POST" action="<?= e(route('/admin/fixtures/sync')) ?>" class="admin-action-form">
                <?= csrf_input() ?>
                <label>
                    <span>Sincronizar fixtures da data</span>
                    <input type="date" name="date" value="<?= e($focusDate) ?>">
                </label>
                <button type="submit" class="btn btn-primary btn-block">Sincronizar data</button>
            </form>

            <form method="POST" action="<?= e(route('/admin/matches/analyze-pending')) ?>" class="admin-action-form">
                <?= csrf_input() ?>
                <label>
                    <span>Gerar pendentes de</span>
                    <input type="date" name="date" value="<?= e($focusDate) ?>">
                </label>
                <label>
                    <span>Limite por lote</span>
                    <input type="number" name="limit" min="1" max="25" value="10">
                </label>
                <button type="submit" class="btn btn-secondary btn-block">Gerar analises pendentes</button>
            </form>

            <form method="POST" action="<?= e(route('/admin/live/sync')) ?>" class="admin-action-form">
                <?= csrf_input() ?>
                <input type="hidden" name="redirect_date" value="<?= e($focusDate) ?>">
                <button type="submit" class="btn btn-ghost btn-block">Sincronizar jogos ao vivo agora</button>
            </form>
        </div>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Uso por feature</p>
                <h2>Distribuicao OpenAI</h2>
            </div>
        </div>
        <?php if (($usage['by_feature'] ?? []) === []): ?>
            <?php view_partial('empty-state', ['title' => 'Sem telemetria ainda', 'message' => 'As chamadas da IA vao aparecer aqui conforme o uso crescer.']); ?>
        <?php else: ?>
            <div class="chart-stack">
                <?php foreach (($usage['by_feature'] ?? []) as $feature): ?>
                    <?php
                    $total = max(1, (int) ($usage['totals']['calls'] ?? 1));
                    $rate = (int) round(((int) ($feature['total'] ?? 0) / $total) * 100);
                    ?>
                    <?php view_partial('probability-bar', ['label' => (string) ($feature['feature'] ?? 'feature'), 'value' => $rate]); ?>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Fila da data</p>
                <h2>Jogos sem analise</h2>
            </div>
        </div>
        <?php if (($overview['pending_matches'] ?? []) === []): ?>
            <?php view_partial('empty-state', ['title' => 'Fila limpa', 'message' => 'Nao existem jogos pendentes de analise nessa data.']); ?>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach (($overview['pending_matches'] ?? []) as $match): ?>
                    <form method="POST" action="<?= e(route('/admin/matches/analyze')) ?>" class="admin-row">
                        <?= csrf_input() ?>
                        <input type="hidden" name="match_id" value="<?= (int) $match['id'] ?>">
                        <input type="hidden" name="redirect_date" value="<?= e($focusDate) ?>">
                        <div>
                            <strong><?= e($match['home_team_name'] . ' x ' . $match['away_team_name']) ?></strong>
                            <p class="muted-copy"><?= e($match['league_country'] . ' • ' . $match['league_name'] . ' • ' . date('d/m H:i', strtotime((string) $match['date']))) ?></p>
                        </div>
                        <div class="admin-row-actions">
                            <a href="<?= e(route('/dashboard/match/' . (int) $match['id'])) ?>" class="btn btn-ghost">Abrir</a>
                            <button type="submit" class="btn btn-primary">Gerar agora</button>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Ultimas analises</p>
                <h2>Saida recente da IA</h2>
            </div>
        </div>
        <?php if (($overview['recent_analyses'] ?? []) === []): ?>
            <?php view_partial('empty-state', ['title' => 'Sem analises recentes', 'message' => 'As proximas geracoes vao aparecer aqui para auditoria rapida.']); ?>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach (($overview['recent_analyses'] ?? []) as $analysis): ?>
                    <div class="admin-row">
                        <div>
                            <strong><?= e($analysis['home_team_name'] . ' x ' . $analysis['away_team_name']) ?></strong>
                            <p class="muted-copy"><?= e($analysis['league_name'] . ' • ' . date('d/m H:i', strtotime((string) $analysis['updated_at']))) ?></p>
                        </div>
                        <div class="admin-meta">
                            <?php view_partial('risk-badge', ['level' => $analysis['risk_level'] ?? 'medium']); ?>
                            <span>Conf. <?= e(format_percent((int) ($analysis['confidence_score'] ?? 0))) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Jogos importados</p>
                <h2>Ultimos sincronizados</h2>
            </div>
        </div>
        <?php if (($overview['matches'] ?? []) === []): ?>
            <?php view_partial('empty-state', ['title' => 'Sem jogos importados', 'message' => 'Rode uma sincronizacao para popular o ambiente.']); ?>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach (($overview['matches'] ?? []) as $match): ?>
                    <form method="POST" action="<?= e(route('/admin/matches/analyze')) ?>" class="admin-row">
                        <?= csrf_input() ?>
                        <input type="hidden" name="match_id" value="<?= (int) $match['id'] ?>">
                        <input type="hidden" name="redirect_date" value="<?= e($focusDate) ?>">
                        <div>
                            <strong><?= e($match['home_team_name'] . ' x ' . $match['away_team_name']) ?></strong>
                            <p class="muted-copy">
                                <?= e($match['league_name'] . ' • ' . date('d/m H:i', strtotime((string) $match['date']))) ?>
                                <?php if (!empty($match['analysis_id'])): ?>
                                    • analise em <?= e(date('d/m H:i', strtotime((string) $match['analysis_updated_at']))) ?>
                                <?php else: ?>
                                    • sem analise
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="admin-row-actions">
                            <a href="<?= e(route('/dashboard/match/' . (int) $match['id'])) ?>" class="btn btn-ghost">Abrir</a>
                            <button type="submit" class="btn btn-secondary"><?= !empty($match['analysis_id']) ? 'Regenerar' : 'Gerar' ?></button>
                        </div>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Usuarios</p>
                <h2>Cadastros recentes</h2>
            </div>
        </div>
        <?php if (($overview['recent_users'] ?? []) === []): ?>
            <?php view_partial('empty-state', ['title' => 'Sem usuarios recentes', 'message' => 'Os proximos cadastros vao aparecer aqui.']); ?>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach (($overview['recent_users'] ?? []) as $user): ?>
                    <div class="admin-row">
                        <div>
                            <strong><?= e((string) ($user['name'] ?: 'Sem nome')) ?></strong>
                            <p class="muted-copy"><?= e((string) ($user['email'] ?? '')) ?> • <?= e(date('d/m H:i', strtotime((string) $user['created_at']))) ?></p>
                        </div>
                        <span class="status-pill"><?= e(plan_label((string) ($user['plan'] ?? 'free'))) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>
</section>

<section class="two-column-grid">
    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Ligas</p>
                <h2>Ativar ou desativar</h2>
            </div>
        </div>
        <?php if (($overview['leagues'] ?? []) === []): ?>
            <?php view_partial('empty-state', ['title' => 'Nenhuma liga importada', 'message' => 'Sincronize fixtures para popular esta area.']); ?>
        <?php else: ?>
            <div class="admin-list">
                <?php foreach (($overview['leagues'] ?? []) as $league): ?>
                    <form method="POST" action="<?= e(route('/admin/leagues/toggle')) ?>" class="admin-row">
                        <?= csrf_input() ?>
                        <input type="hidden" name="league_id" value="<?= (int) $league['id'] ?>">
                        <input type="hidden" name="redirect_date" value="<?= e($focusDate) ?>">
                        <div>
                            <strong><?= e($league['country'] . ' • ' . $league['name']) ?></strong>
                            <p class="muted-copy">Season <?= e((string) $league['season']) ?></p>
                        </div>
                        <button type="submit" class="btn btn-secondary"><?= !empty($league['enabled']) ? 'Desativar' : 'Ativar' ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="glass-card">
        <div class="section-head">
            <div>
                <p class="eyebrow">Erros de API</p>
                <h2>Ultimas ocorrencias</h2>
            </div>
        </div>
        <?php if (($overview['api_errors'] ?? []) === []): ?>
            <?php view_partial('empty-state', ['title' => 'Sem erros recentes', 'message' => 'Nenhum erro de API foi registrado ate agora.']); ?>
        <?php else: ?>
            <ul class="timeline-list">
                <?php foreach (($overview['api_errors'] ?? []) as $errorItem): ?>
                    <li>
                        <strong><?= e((string) ($errorItem['service'] ?? 'servico')) ?></strong>
                        <span><?= e((string) ($errorItem['endpoint'] ?? 'endpoint')) ?> • <?= e((string) ($errorItem['message'] ?? '')) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </article>
</section>
