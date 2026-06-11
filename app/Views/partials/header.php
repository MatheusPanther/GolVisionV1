<?php

declare(strict_types=1);
?>
<header class="topbar">
    <div class="brand-wrap">
        <a href="<?= e(route($user ? '/dashboard' : '/')) ?>" class="brand-mark">GV</a>
        <div>
            <a href="<?= e(route($user ? '/dashboard' : '/')) ?>" class="brand-name">GoalVision AI</a>
            <p class="brand-subtitle">Analise probabilistica de futebol com IA</p>
        </div>
    </div>

    <nav class="topnav">
        <?php if ($user === null): ?>
            <a href="<?= e(route('/')) ?>#como-funciona" class="nav-link">Como funciona</a>
            <a href="<?= e(route('/')) ?>#recursos" class="nav-link">Recursos</a>
            <a href="<?= e(route('/login')) ?>" class="btn btn-primary">Entrar no Beta</a>
        <?php else: ?>
            <span class="user-pill"><?= e($user['name'] ?? $user['email'] ?? 'Usuario') ?></span>
            <span class="plan-pill"><?= e(plan_label((string) ($user['plan'] ?? 'free'))) ?></span>
            <?php if (\App\Core\Auth::isAdmin()): ?>
                <a href="<?= e(route('/admin')) ?>" class="nav-link">Admin</a>
            <?php endif; ?>
            <form method="POST" action="<?= e(route('/logout')) ?>" class="inline-form">
                <?= csrf_input() ?>
                <button type="submit" class="btn btn-ghost">Sair</button>
            </form>
        <?php endif; ?>
    </nav>
</header>
