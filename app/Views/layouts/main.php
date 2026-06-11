<?php

declare(strict_types=1);

$user = current_user();
$flashSuccess = flash('success');
$flashError = flash('error');
$showSidebar = $showSidebar ?? false;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'GoalVision AI') ?></title>
    <meta name="description" content="Analises de futebol com IA antes da bola rolar. Inteligencia esportiva, cenarios informativos e apoio a decisao com uso responsavel.">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body>
    <?php view_partial('header', ['user' => $user, 'showSidebar' => $showSidebar]); ?>

    <div class="shell <?= $showSidebar ? 'shell-with-sidebar' : 'shell-landing' ?>">
        <?php if ($showSidebar): ?>
            <?php view_partial('sidebar', ['user' => $user]); ?>
        <?php endif; ?>

        <main class="content-area">
            <?php if ($flashSuccess): ?>
                <div class="flash flash-success"><?= e($flashSuccess) ?></div>
            <?php endif; ?>

            <?php if ($flashError): ?>
                <div class="flash flash-error"><?= e($flashError) ?></div>
            <?php endif; ?>

            <?= $content ?>
        </main>
    </div>

    <?php if ($showSidebar && $user !== null && empty($user['is_18_confirmed'])): ?>
        <?php view_partial('age-gate-modal', ['returnTo' => request_path(), 'user' => $user]); ?>
    <?php endif; ?>
</body>
</html>
