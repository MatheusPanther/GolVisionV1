<?php

declare(strict_types=1);
?>
<section class="hero-section">
    <div class="hero-copy">
        <p class="eyebrow">GoalVision AI</p>
        <h1>Análises de futebol com IA antes da bola rolar</h1>
        <p class="hero-text">O GoalVision AI cruza estatisticas, momento das equipes e padroes de jogo para gerar tendencias, riscos e cenarios possiveis.</p>
        <div class="hero-actions">
            <a href="<?= e(route($user ? '/dashboard' : '/login')) ?>" class="btn btn-primary">Entrar no Beta</a>
            <a href="<?= e(route('/')) ?>#recursos" class="btn btn-secondary">Ver recursos</a>
        </div>
        <?php view_partial('disclaimer-box', ['message' => 'Analises probabilisticas. Nao existe garantia de resultado. Use com responsabilidade. 18+.']); ?>
    </div>

    <div class="hero-demo-grid">
        <?php foreach ($demoMatches as $demoMatch): ?>
            <article class="glass-card landing-match-card">
                <div class="match-card-top">
                    <div>
                        <p class="eyebrow"><?= e($demoMatch['league']) ?></p>
                        <h3 class="match-title"><?= e($demoMatch['home'] . ' x ' . $demoMatch['away']) ?></h3>
                    </div>
                    <span class="heat-pill"><?= e($demoMatch['heat']) ?></span>
                </div>
                <div class="metric-row">
                    <span>Horario</span>
                    <strong><?= e($demoMatch['time']) ?></strong>
                </div>
                <?php view_partial('probability-bar', ['label' => 'Over 1.5', 'value' => $demoMatch['over15']]); ?>
                <?php view_partial('probability-bar', ['label' => 'Ambas marcam', 'value' => $demoMatch['btts']]); ?>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section id="como-funciona" class="section-grid">
    <div class="section-head">
        <p class="eyebrow">Como funciona</p>
        <h2>Analise enxuta, clara e orientada por contexto</h2>
    </div>
    <div class="feature-grid">
        <article class="glass-card feature-card">
            <span class="feature-number">1</span>
            <h3>Dados reais dos jogos</h3>
            <p>Fixtures, head-to-head, eventos, estatisticas e sinais de forma vindos da SportMonks e organizados para leitura rapida.</p>
        </article>
        <article class="glass-card feature-card">
            <span class="feature-number">2</span>
            <h3>IA analisa contexto e estatisticas</h3>
            <p>A camada de IA trabalha apenas com o payload fornecido e responde em formato estruturado, sem promessas de lucro.</p>
        </article>
        <article class="glass-card feature-card">
            <span class="feature-number">3</span>
            <h3>Voce recebe tendencias e cenarios</h3>
            <p>Probabilidades, fatores-chave, alertas de risco e cenarios conservador, equilibrado e ousado para apoio a decisao.</p>
        </article>
    </div>
</section>

<section id="recursos" class="section-grid">
    <div class="section-head">
        <p class="eyebrow">Recursos</p>
        <h2>Ferramentas pensadas para leitura de jogo, nao para operacao de apostas</h2>
    </div>
    <div class="feature-grid">
        <?php foreach ([
            'Radar de gols',
            'Probabilidade Over 1.5',
            'Probabilidade Over 2.5',
            'Ambas marcam',
            'Cenarios conservador/equilibrado/ousado',
            'Historico de analises',
        ] as $feature): ?>
            <article class="glass-card feature-inline-card">
                <span class="feature-dot"></span>
                <h3><?= e($feature) ?></h3>
            </article>
        <?php endforeach; ?>
    </div>
</section>

<section class="section-grid">
    <?php view_partial('disclaimer-box', [
        'title' => 'Aviso legal',
        'message' => 'GoalVision AI oferece analises probabilisticas, inteligencia esportiva e cenarios informativos. Nao recebe apostas, nao processa pagamentos e nao promete retorno financeiro.',
        'tone' => 'warning',
    ]); ?>
</section>
