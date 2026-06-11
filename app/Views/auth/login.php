<?php

declare(strict_types=1);
?>
<section class="auth-shell">
    <div class="auth-copy glass-card">
        <p class="eyebrow">Entrar no Beta</p>
        <h1>Inteligencia esportiva para leitura de jogos com responsabilidade</h1>
        <p>Entre para acompanhar jogos do dia, gerar analises estruturadas e montar cenarios informativos com foco em probabilidade, tendencia e risco.</p>
        <?php view_partial('disclaimer-box', ['message' => 'Analises probabilisticas. Nao existe garantia de resultado. Uso responsavel. 18+.']); ?>
    </div>

    <div class="auth-grid">
        <article class="glass-card auth-card">
            <h2>Entrar</h2>
            <form method="POST" action="<?= e(route('/login')) ?>" class="stack-form">
                <?= csrf_input() ?>
                <label>
                    <span>Email</span>
                    <input type="email" name="email" value="<?= e(old('email')) ?>" placeholder="voce@exemplo.com" required>
                </label>
                <label>
                    <span>Senha</span>
                    <input type="password" name="password" placeholder="Sua senha" required>
                </label>
                <button type="submit" class="btn btn-primary btn-block">Acessar dashboard</button>
            </form>
        </article>

        <article class="glass-card auth-card">
            <h2>Criar conta</h2>
            <form method="POST" action="<?= e(route('/register')) ?>" class="stack-form">
                <?= csrf_input() ?>
                <label>
                    <span>Nome</span>
                    <input type="text" name="name" value="<?= e(old('register_name')) ?>" placeholder="Seu nome" required>
                </label>
                <label>
                    <span>Email</span>
                    <input type="email" name="email" value="<?= e(old('register_email')) ?>" placeholder="voce@exemplo.com" required>
                </label>
                <label>
                    <span>Senha</span>
                    <input type="password" name="password" placeholder="Minimo de 8 caracteres" required>
                </label>
                <label>
                    <span>Confirmar senha</span>
                    <input type="password" name="confirm_password" placeholder="Repita a senha" required>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="is_18_confirmed" value="1" required>
                    <span>Confirmo que tenho 18 anos ou mais.</span>
                </label>
                <label class="checkbox-row">
                    <input type="checkbox" name="accepted_terms" value="1" required>
                    <span>Aceito os termos e entendo que nao existe garantia de resultado.</span>
                </label>
                <button type="submit" class="btn btn-secondary btn-block">Criar conta Free</button>
            </form>
        </article>
    </div>
</section>
