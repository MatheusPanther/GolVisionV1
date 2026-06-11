<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;

final class HomeController extends Controller
{
    public function index(): void
    {
        $this->render('home', [
            'pageTitle' => 'GoalVision AI',
            'showSidebar' => false,
            'user' => Auth::user(),
            'demoMatches' => [
                [
                    'league' => 'Brasileirao Serie A',
                    'time' => '19:00',
                    'home' => 'Palmeiras',
                    'away' => 'Flamengo',
                    'heat' => 'Jogo quente',
                    'over15' => 81,
                    'btts' => 62,
                ],
                [
                    'league' => 'Premier League',
                    'time' => '21:30',
                    'home' => 'Liverpool',
                    'away' => 'Tottenham',
                    'heat' => 'Moderado',
                    'over15' => 78,
                    'btts' => 58,
                ],
                [
                    'league' => 'La Liga',
                    'time' => '16:00',
                    'home' => 'Real Sociedad',
                    'away' => 'Villarreal',
                    'heat' => 'Evitar',
                    'over15' => 59,
                    'btts' => 44,
                ],
            ],
        ]);
    }
}
