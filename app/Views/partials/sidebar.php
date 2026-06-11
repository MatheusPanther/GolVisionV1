<?php

declare(strict_types=1);
?>
<aside class="sidebar">
    <div class="sidebar-panel">
        <div class="sidebar-title">Workspace</div>
        <p class="sidebar-copy">Cenarios informativos, leitura de risco e historico para apoiar sua decisao com responsabilidade.</p>
    </div>

    <nav class="sidebar-nav">
        <a href="<?= e(route('/dashboard')) ?>" class="sidebar-link <?= is_active_path('/dashboard') && !is_active_path('/dashboard/match') && !is_active_path('/dashboard/slip-builder') && !is_active_path('/dashboard/history') && !is_active_path('/dashboard/settings') ? 'active' : '' ?>">Jogos do dia</a>
        <a href="<?= e(route('/dashboard/slip-builder')) ?>" class="sidebar-link <?= is_active_path('/dashboard/slip-builder') ? 'active' : '' ?>">Bilhete Inteligente</a>
        <a href="<?= e(route('/dashboard/history')) ?>" class="sidebar-link <?= is_active_path('/dashboard/history') ? 'active' : '' ?>">Historico</a>
        <a href="<?= e(route('/dashboard/settings')) ?>" class="sidebar-link <?= is_active_path('/dashboard/settings') ? 'active' : '' ?>">Configuracoes</a>
        <?php if (\App\Core\Auth::isAdmin()): ?>
            <a href="<?= e(route('/admin')) ?>" class="sidebar-link <?= is_active_path('/admin') ? 'active' : '' ?>">Admin</a>
        <?php endif; ?>
    </nav>

    <div class="sidebar-panel sidebar-plan">
        <div class="mini-label">Plano atual</div>
        <strong><?= e(plan_label((string) ($user['plan'] ?? 'free'))) ?></strong>
        <p class="sidebar-copy">Free: 3 analises/dia. Beta: analises ilimitadas + slip builder. Pro: historico avancado + alertas futuros.</p>
    </div>
</aside>
