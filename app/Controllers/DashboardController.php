<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\GoalVisionService;
use RuntimeException;
use Throwable;

final class DashboardController extends Controller
{
    private ?GoalVisionService $service = null;

    public function index(): void
    {
        Auth::requireLogin();

        $date = (string) ($_GET['date'] ?? date('Y-m-d'));
        $filters = [
            'league_id' => $_GET['league_id'] ?? null,
            'country' => $_GET['country'] ?? null,
            'status' => $_GET['status'] ?? null,
        ];

        try {
            $data = $this->service()->dashboard($date, $filters);
            $error = is_string($data['api_warning'] ?? null) ? $data['api_warning'] : null;
        } catch (Throwable $exception) {
            app_log('error', 'Falha ao carregar dashboard.', [
                'date' => $date,
                'filters' => $filters,
                'user_id' => Auth::id(),
                'exception' => $exception->getMessage(),
            ]);
            $data = ['fixtures' => [], 'leagues' => [], 'date' => $date];
            $error = friendly_database_error($exception, 'Nao foi possivel carregar os jogos agora.');
        }

        $this->render('dashboard/index', [
            'pageTitle' => 'Dashboard | GoalVision AI',
            'showSidebar' => true,
            'dashboard' => $data,
            'filters' => $filters,
            'error' => $error,
        ]);
    }

    public function match(string $id): void
    {
        Auth::requireLogin();

        try {
            $data = $this->service()->getMatchPage((int) $id, false, false);
            $this->render('dashboard/match', [
                'pageTitle' => 'Partida | GoalVision AI',
                'showSidebar' => true,
                'data' => $data,
                'analysisHint' => $data['analysis'] === null
                    ? 'Clique em "Gerar analise" para montar a leitura completa desta partida.'
                    : null,
                'analysisGenerationFailed' => false,
                'analysisGenerationMessage' => null,
            ]);
        } catch (Throwable $exception) {
            app_log('error', 'Falha ao abrir a pagina da partida.', [
                'match_id' => (int) $id,
                'user_id' => Auth::id(),
                'exception' => $exception->getMessage(),
            ]);
            flash('error', friendly_database_error($exception, 'Nao foi possivel abrir a analise desta partida agora.'));
            $this->redirect('/dashboard');
        }
    }

    public function analyzeMatch(string $id): void
    {
        Auth::requireLogin();
        $this->requirePost();
        $this->verifyCsrf();

        try {
            $this->service()->generateMatchAnalysis((int) $id, Auth::id());
            flash('success', 'Analise gerada com sucesso.');
            $this->redirect('/dashboard/match/' . (int) $id);
        } catch (Throwable $exception) {
            app_log('error', 'Falha ao gerar analise da partida.', [
                'match_id' => (int) $id,
                'user_id' => Auth::id(),
                'exception' => $exception->getMessage(),
            ]);

            try {
                $data = $this->service()->getMatchPage((int) $id, false, false);
                $this->render('dashboard/match', [
                    'pageTitle' => 'Partida | GoalVision AI',
                    'showSidebar' => true,
                    'data' => $data,
                    'analysisHint' => $data['analysis'] === null
                        ? 'Clique em "Gerar analise" para montar a leitura completa desta partida.'
                        : null,
                    'analysisGenerationFailed' => true,
                    'analysisGenerationMessage' => friendly_database_error(
                        $exception,
                        'Nao foi possivel gerar a analise agora. Tente novamente em instantes.'
                    ),
                ]);
                return;
            } catch (Throwable $fallbackException) {
                app_log('error', 'Falha ao reabrir partida apos erro de geracao de analise.', [
                    'match_id' => (int) $id,
                    'user_id' => Auth::id(),
                    'exception' => $fallbackException->getMessage(),
                ]);

                flash('error', friendly_database_error($exception, 'Nao foi possivel gerar a analise agora.'));
                $this->redirect('/dashboard');
            }
        }
    }

    public function slipBuilder(): void
    {
        Auth::requireLogin();

        try {
            $userId = Auth::id();
            if ($userId === null) {
                throw new RuntimeException('Usuario nao autenticado.');
            }

            $settings = $this->service()->userSettings($userId);
            $dashboard = $this->service()->dashboard((string) ($_GET['date'] ?? date('Y-m-d')));
            $error = null;
        } catch (Throwable $exception) {
            $settings = ['user' => Auth::user(), 'preferences' => ['preferred_leagues' => [], 'preferred_markets' => [], 'excluded_leagues' => []], 'leagues' => []];
            $dashboard = ['fixtures' => [], 'leagues' => [], 'date' => date('Y-m-d')];
            $error = 'Nao foi possivel preparar o Bilhete Inteligente agora.';
        }

        $this->render('dashboard/slip-builder', [
            'pageTitle' => 'Bilhete Inteligente | GoalVision AI',
            'showSidebar' => true,
            'settings' => $settings,
            'dashboard' => $dashboard,
            'slipResult' => null,
            'error' => $error,
        ]);
    }

    public function generateSlip(): void
    {
        Auth::requireLogin();
        $this->requirePost();
        $this->verifyCsrf();

        $userId = Auth::id();
        if ($userId === null) {
            $this->redirect('/login');
        }

        $input = [
            'date' => (string) ($_POST['date'] ?? date('Y-m-d')),
            'riskProfile' => (string) ($_POST['risk_profile'] ?? 'balanced'),
            'marketFocus' => (string) ($_POST['market_focus'] ?? 'mixed'),
            'maxSelections' => max(1, min(5, (int) ($_POST['max_selections'] ?? 3))),
            'leagueIds' => array_map('intval', $_POST['league_ids'] ?? []),
            'excludeHighRisk' => isset($_POST['exclude_high_risk']),
        ];

        try {
            $slipResult = $this->service()->generateSlip($userId, $input);
            $settings = $this->service()->userSettings($userId);
            $dashboard = $this->service()->dashboard($input['date']);
            $error = null;
        } catch (Throwable $exception) {
            $slipResult = null;
            $error = friendly_database_error($exception, 'Nao foi possivel gerar o Bilhete Inteligente agora.');
            try {
                $settings = $this->service()->userSettings($userId);
                $dashboard = $this->service()->dashboard($input['date']);
            } catch (Throwable) {
                $settings = ['user' => Auth::user(), 'preferences' => ['preferred_leagues' => [], 'preferred_markets' => [], 'excluded_leagues' => []], 'leagues' => []];
                $dashboard = ['fixtures' => [], 'leagues' => [], 'date' => $input['date']];
            }
        }

        $this->render('dashboard/slip-builder', [
            'pageTitle' => 'Bilhete Inteligente | GoalVision AI',
            'showSidebar' => true,
            'settings' => $settings,
            'dashboard' => $dashboard,
            'slipResult' => $slipResult,
            'error' => $error,
            'formInput' => $input,
        ]);
    }

    public function history(): void
    {
        Auth::requireLogin();

        try {
            $history = $this->service()->history();
            $error = null;
        } catch (Throwable $exception) {
            $history = ['items' => [], 'summary' => ['market' => [], 'league' => [], 'risk' => []]];
            $error = 'Nao foi possivel carregar o historico agora.';
        }

        $this->render('dashboard/history', [
            'pageTitle' => 'Historico | GoalVision AI',
            'showSidebar' => true,
            'history' => $history,
            'error' => $error,
        ]);
    }

    public function settings(): void
    {
        Auth::requireLogin();

        try {
            $userId = Auth::id();
            if ($userId === null) {
                throw new RuntimeException('Usuario nao autenticado.');
            }

            $settings = $this->service()->userSettings($userId);
            $error = null;
        } catch (Throwable $exception) {
            $settings = ['user' => Auth::user(), 'preferences' => ['preferred_leagues' => [], 'preferred_markets' => [], 'excluded_leagues' => []], 'leagues' => []];
            $error = 'Nao foi possivel carregar as configuracoes.';
        }

        $this->render('dashboard/settings', [
            'pageTitle' => 'Configuracoes | GoalVision AI',
            'showSidebar' => true,
            'settings' => $settings,
            'error' => $error,
        ]);
    }

    public function updateSettings(): void
    {
        Auth::requireLogin();
        $this->requirePost();
        $this->verifyCsrf();

        $userId = Auth::id();
        if ($userId === null) {
            $this->redirect('/login');
        }

        $currentSettings = null;
        try {
            $currentSettings = $this->service()->userSettings($userId);
        } catch (Throwable) {
            $currentSettings = ['preferences' => ['preferred_leagues' => [], 'preferred_markets' => [], 'excluded_leagues' => []]];
        }

        $data = [
            'name' => trim((string) ($_POST['name'] ?? '')),
            'preferred_leagues' => array_key_exists('preferred_leagues', $_POST)
                ? array_map('intval', $_POST['preferred_leagues'] ?? [])
                : ($currentSettings['preferences']['preferred_leagues'] ?? []),
            'preferred_markets' => array_key_exists('preferred_markets', $_POST)
                ? array_values(array_map('strval', $_POST['preferred_markets'] ?? []))
                : ($currentSettings['preferences']['preferred_markets'] ?? []),
            'excluded_leagues' => array_key_exists('excluded_leagues', $_POST)
                ? array_map('intval', $_POST['excluded_leagues'] ?? [])
                : ($currentSettings['preferences']['excluded_leagues'] ?? []),
            'accept_terms' => isset($_POST['accept_terms']),
            'is_18_confirmed' => isset($_POST['is_18_confirmed']),
        ];

        try {
            $settings = $this->service()->updateUserSettings($userId, $data);
            Auth::login($settings['user']);
            flash('success', 'Configuracoes salvas.');
        } catch (Throwable $exception) {
            flash('error', 'Nao foi possivel salvar as configuracoes.');
        }

        $returnTo = (string) ($_POST['return_to'] ?? '/dashboard/settings');
        $this->redirect($returnTo !== '' ? $returnTo : '/dashboard/settings');
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
