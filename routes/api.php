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
    Route::get('/bookings', [BookingController::class, 'index']);
    Route::get('/bookings/today', [BookingController::class, 'today']);
    Route::get('/bookings/calendar', [BookingController::class, 'calendar']);
    Route::get('/bookings/{booking}', [BookingController::class, 'show']);

    // Giocatori
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/users/latest', [UserController::class, 'latest']);
    Route::get('/users/{user}', [UserController::class, 'show']);

    // Sessioni Bot
    Route::get('/bot-sessions', [BotSessionController::class, 'index']);
    Route::get('/bot-sessions/{botSession}', [BotSessionController::class, 'show']);

    // Match Results
    Route::get('/match-results', [MatchResultController::class, 'index']);
    Route::get('/match-results/{matchResult}', [MatchResultController::class, 'show']);

    // Pricing Rules
    Route::get('/pricing-rules', [PricingRuleController::class, 'index']);
});
