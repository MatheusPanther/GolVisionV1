<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Services\GoalVisionService;
use Throwable;

final class ApiController extends Controller
{
    private ?GoalVisionService $service = null;

    public function fixtures(): void
    {
        $date = (string) ($_GET['date'] ?? date('Y-m-d'));

        try {
            $payload = $this->service()->dashboard($date);
            $this->json(['ok' => true, 'data' => $payload]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'error' => $exception->getMessage()], 500);
        }
    }

    public function live(): void
    {
        try {
            $payload = $this->service()->syncLiveFixtures();
            $this->json(['ok' => true, 'data' => $payload]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'error' => $exception->getMessage()], 500);
        }
    }

    public function match(string $fixtureId): void
    {
        try {
            $payload = $this->service()->matchPayloadByFixtureId((int) $fixtureId);
            $this->json(['ok' => true, 'data' => $payload]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'error' => $exception->getMessage()], 500);
        }
    }

    public function analyzeMatch(): void
    {
        $payload = $this->requestJson();
        $fixtureId = (int) ($payload['fixtureId'] ?? $_POST['fixtureId'] ?? 0);

        try {
            $analysis = $this->service()->generateMatchAnalysisByFixtureId($fixtureId, Auth::id(), false);
            $this->json(['ok' => true, 'data' => $analysis]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    public function generateSlip(): void
    {
        Auth::requireLogin();

        $payload = $this->requestJson();
        $userId = Auth::id();
        if ($userId === null) {
            $this->json(['ok' => false, 'error' => 'Nao autenticado'], 401);
        }

        try {
            $slip = $this->service()->generateSlip($userId, [
                'date' => (string) ($payload['date'] ?? date('Y-m-d')),
                'riskProfile' => (string) ($payload['riskProfile'] ?? 'balanced'),
                'marketFocus' => (string) ($payload['marketFocus'] ?? 'mixed'),
                'maxSelections' => max(1, min(5, (int) ($payload['maxSelections'] ?? 3))),
                'leagueIds' => array_map('intval', $payload['leagueIds'] ?? []),
                'excludeHighRisk' => (bool) ($payload['excludeHighRisk'] ?? false),
            ]);

            $this->json(['ok' => true, 'data' => $slip]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'error' => $exception->getMessage()], 422);
        }
    }

    public function history(): void
    {
        Auth::requireLogin();

        try {
            $history = $this->service()->history();
            $this->json(['ok' => true, 'data' => $history]);
        } catch (Throwable $exception) {
            $this->json(['ok' => false, 'error' => $exception->getMessage()], 500);
        }
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
