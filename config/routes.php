<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;

$router->get('/', [HomeController::class, 'index']);

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/dashboard', [DashboardController::class, 'index']);
$router->get('/dashboard/match/{id}', [DashboardController::class, 'match']);
$router->post('/dashboard/match/{id}/analyze', [DashboardController::class, 'analyzeMatch']);
$router->get('/dashboard/slip-builder', [DashboardController::class, 'slipBuilder']);
$router->post('/dashboard/slip-builder', [DashboardController::class, 'generateSlip']);
$router->get('/dashboard/history', [DashboardController::class, 'history']);
$router->get('/dashboard/settings', [DashboardController::class, 'settings']);
$router->post('/dashboard/settings', [DashboardController::class, 'updateSettings']);

$router->get('/admin', [AdminController::class, 'index']);
$router->post('/admin/leagues/toggle', [AdminController::class, 'toggleLeague']);
$router->post('/admin/fixtures/sync', [AdminController::class, 'syncFixtures']);
$router->post('/admin/live/sync', [AdminController::class, 'syncLive']);
$router->post('/admin/matches/analyze', [AdminController::class, 'regenerateMatch']);
$router->post('/admin/matches/analyze-pending', [AdminController::class, 'analyzePending']);

$router->get('/api/football/fixtures', [ApiController::class, 'fixtures']);
$router->get('/api/football/live', [ApiController::class, 'live']);
$router->get('/api/football/match/{fixtureId}', [ApiController::class, 'match']);
$router->post('/api/ai/analyze-match', [ApiController::class, 'analyzeMatch']);
$router->post('/api/ai/generate-slip', [ApiController::class, 'generateSlip']);
$router->get('/api/history', [ApiController::class, 'history']);
