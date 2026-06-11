<?php

declare(strict_types=1);
?>
<div class="modal-backdrop">
    <div class="modal-card">
        <p class="eyebrow">Acesso responsavel</p>
        <h2>Confirmacao de maioridade</h2>
        <p>Antes de acessar as analises, confirme que voce tem 18+ e que entendeu que o GoalVision AI oferece apenas cenarios informativos e apoio a decisao.</p>

        <form method="POST" action="<?= e(route('/dashboard/settings')) ?>" class="stack-form">
            <?= csrf_input() ?>
            <input type="hidden" name="return_to" value="<?= e($returnTo ?? '/dashboard') ?>">
            <input type="hidden" name="name" value="<?= e($user['name'] ?? '') ?>">

            <label class="checkbox-row">
                <input type="checkbox" name="is_18_confirmed" value="1" checked>
                <span>Confirmo que tenho 18 anos ou mais.</span>
            </label>

            <label class="checkbox-row">
                <input type="checkbox" name="accept_terms" value="1" checked>
                <span>Entendi que nao existe garantia de resultado e que devo usar com responsabilidade.</span>
            </label>

            <button type="submit" class="btn btn-primary btn-block">Continuar</button>
        </form>
    </div>
</div>
