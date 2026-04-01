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

    // Match Results
    Route::get('/match-results', [MatchResultController::class, 'index']);
    Route::get('/match-results/{matchResult}', [MatchResultController::class, 'show']);

    // Pricing Rules
    Route::apiResource('pricing-rules', PricingRuleController::class);

    // Settings
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::get('/settings/{key}', [SettingsController::class, 'show']);
    Route::put('/settings/{key}', [SettingsController::class, 'update']);
});
