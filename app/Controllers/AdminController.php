<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\GoalVisionService;
use Throwable;

final class AdminController extends Controller
{
    private ?GoalVisionService $service = null;

    public function index(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            $this->redirect('/dashboard');
        }

        $focusDate = (string) ($_GET['date'] ?? date('Y-m-d'));

        try {
            $overview = $this->service()->adminOverview($focusDate);
            $error = null;
        } catch (Throwable $exception) {
            $overview = [
                'focus_date' => $focusDate,
                'summary' => [],
                'leagues' => [],
                'matches' => [],
                'pending_matches' => [],
                'recent_users' => [],
                'recent_analyses' => [],
                'usage' => ['totals' => [], 'by_feature' => []],
                'api_errors' => [],
            ];
            $error = 'Nao foi possivel carregar o painel admin.';
        }

        $this->render('admin/index', [
            'pageTitle' => 'Admin | GoalVision AI',
            'showSidebar' => true,
            'overview' => $overview,
            'error' => $error,
        ]);
    }

    public function toggleLeague(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            $this->redirect('/dashboard');
        }

        $this->requirePost();
        $this->verifyCsrf();

        try {
            $this->service()->toggleLeague((int) ($_POST['league_id'] ?? 0));
            flash('success', 'Liga atualizada.');
        } catch (Throwable $exception) {
            flash('error', 'Nao foi possivel atualizar a liga.');
        }

        $this->redirect('/admin?date=' . urlencode((string) ($_POST['redirect_date'] ?? date('Y-m-d'))));
    }

    public function syncFixtures(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            $this->redirect('/dashboard');
        }

        $this->requirePost();
        $this->verifyCsrf();

        $focusDate = (string) ($_POST['date'] ?? date('Y-m-d'));

        try {
            $result = $this->service()->syncAdminFixtures($focusDate);
            flash(
                'success',
                'Sincronizacao concluida para ' . $result['date'] . ': '
                . $result['synced'] . ' jogo(s) atualizado(s), '
                . $result['matches_on_focus_date'] . ' no total e '
                . $result['pending_on_focus_date'] . ' ainda pendente(s) de analise.'
            );
        } catch (Throwable $exception) {
            flash('error', 'Nao foi possivel sincronizar os fixtures desta data.');
        }

        $this->redirect('/admin?date=' . urlencode($focusDate));
    }

    public function syncLive(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            $this->redirect('/dashboard');
        }

        $this->requirePost();
        $this->verifyCsrf();

        $focusDate = (string) ($_POST['redirect_date'] ?? date('Y-m-d'));

        try {
            $result = $this->service()->syncAdminLive();
            flash('success', 'Sincronizacao ao vivo executada: ' . $result['synced'] . ' fixture(s) atualizada(s).');
        } catch (Throwable $exception) {
            flash('error', 'Nao foi possivel sincronizar os jogos ao vivo.');
        }

        $this->redirect('/admin?date=' . urlencode($focusDate));
    }

    public function regenerateMatch(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            $this->redirect('/dashboard');
        }

        $this->requirePost();
        $this->verifyCsrf();

        try {
            $this->service()->generateMatchAnalysis((int) ($_POST['match_id'] ?? 0), Auth::id(), true);
            flash('success', 'Analise regenerada manualmente.');
        } catch (Throwable $exception) {
            flash('error', 'Nao foi possivel regenerar a analise.');
        }

        $this->redirect('/admin?date=' . urlencode((string) ($_POST['redirect_date'] ?? date('Y-m-d'))));
    }

    public function analyzePending(): void
    {
        Auth::requireLogin();
        if (!Auth::isAdmin()) {
            $this->redirect('/dashboard');
        }

        $this->requirePost();
        $this->verifyCsrf();

        $focusDate = (string) ($_POST['date'] ?? date('Y-m-d'));
        $limit = (int) ($_POST['limit'] ?? 10);

        try {
            $result = $this->service()->generatePendingAnalyses($focusDate, $limit);
            flash(
                'success',
                'Fila processada em ' . $result['date'] . ': '
                . $result['generated'] . ' analise(s) gerada(s), '
                . $result['failed'] . ' falha(s) e '
                . $result['remaining'] . ' jogo(s) ainda pendente(s).'
            );
        } catch (Throwable $exception) {
            flash('error', 'Nao foi possivel gerar as analises pendentes.');
        }

        $this->redirect('/admin?date=' . urlencode($focusDate));
    }

    private function service(): GoalVisionService
    {
        if ($this->service instanceof GoalVisionService) {
            return $this->service;
        }

        $this->service = new GoalVisionService();

        return $this->service;
    }
}
