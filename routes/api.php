<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\BookingController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BotSessionController;
use App\Http\Controllers\Api\MatchResultController;
use App\Http\Controllers\Api\PricingRuleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\BotMessageController;
use App\Http\Controllers\Api\FlowGraphController;
use App\Http\Controllers\Api\ModuleCatalogController;

// ── WhatsApp Webhook (nessuna auth) ──────────────────────────────────
Route::get('/webhook', [WhatsAppController::class, 'verify']);
Route::post('/webhook', [WhatsAppController::class, 'handle']);

// ── Auth ─────────────────────────────────────────────────────────────
Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/auth/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/auth/me', [AuthController::class, 'me'])->middleware(['auth', 'admin']);

// ── Admin Panel API (auth + admin required) ──────────────────────────
Route::prefix('admin')->middleware(['auth', 'admin'])->group(function () {
    // Dashboard
    Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    Route::get('/dashboard/weekly-chart', [DashboardController::class, 'weeklyChart']);

    // Prenotazioni
    Route::get('/bookings/today', [BookingController::class, 'today']);
    Route::get('/bookings/calendar', [BookingController::class, 'calendar']);
    Route::apiResource('bookings', BookingController::class);

    // Giocatori
    Route::get('/users/latest', [UserController::class, 'latest']);
    Route::get('/users/search', [UserController::class, 'search']);
    Route::apiResource('users', UserController::class)->except(['store']);

    // Sessioni Bot
    Route::get('/bot-sessions', [BotSessionController::class, 'index']);
    Route::get('/bot-sessions/{botSession}', [BotSessionController::class, 'show']);
    Route::delete('/bot-sessions/{botSession}', [BotSessionController::class, 'destroy']);

    // Match Results
    Route::get('/match-results', [MatchResultController::class, 'index']);
    Route::get('/match-results/{matchResult}', [MatchResultController::class, 'show']);

    // Pricing Rules
    Route::apiResource('pricing-rules', PricingRuleController::class);

    // Settings
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::get('/settings/env', [SettingsController::class, 'env']);
    Route::put('/settings/env', [SettingsController::class, 'updateEnv']);
    Route::get('/settings/{key}', [SettingsController::class, 'show']);
    Route::put('/settings/{key}', [SettingsController::class, 'update']);

    // Bot Messages
    Route::get('/bot-messages', [BotMessageController::class, 'index']);
    Route::post('/bot-messages', [BotMessageController::class, 'store']);
    Route::put('/bot-messages/{key}', [BotMessageController::class, 'update']);

    // Flow graph (nuovo runner a moduli)
    Route::get('/flow/modules',  [FlowGraphController::class, 'modules']);   // registry metadata
    Route::get('/flow/graph',    [FlowGraphController::class, 'graph']);     // nodes + edges
    Route::post('/flow/nodes',   [FlowGraphController::class, 'createNode']);
    Route::put('/flow/nodes/positions', [FlowGraphController::class, 'savePositions']);
    Route::put('/flow/nodes/{node}',    [FlowGraphController::class, 'updateNode']);
    Route::delete('/flow/nodes/{node}', [FlowGraphController::class, 'deleteNode']);
    Route::post('/flow/edges',   [FlowGraphController::class, 'createEdge']);
    Route::delete('/flow/edges/{edge}', [FlowGraphController::class, 'deleteEdge']);

    // Catalogo moduli: on/off + preset
    Route::get('/flow/catalog',        [ModuleCatalogController::class, 'catalog']);
    Route::put('/flow/catalog/toggles', [ModuleCatalogController::class, 'updateToggles']);
    Route::get('/flow/presets',        [ModuleCatalogController::class, 'listPresets']);
    Route::post('/flow/presets',       [ModuleCatalogController::class, 'createPreset']);
    Route::put('/flow/presets/{preset}',    [ModuleCatalogController::class, 'updatePreset']);
    Route::delete('/flow/presets/{preset}', [ModuleCatalogController::class, 'deletePreset']);
});
