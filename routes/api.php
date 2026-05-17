<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\WebchatController;
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
use App\Http\Controllers\Api\FlowCompositeController;
use App\Http\Controllers\Api\ModuleCatalogController;
use App\Http\Controllers\Api\V1\App\AuthController as AppAuthController;
use App\Http\Controllers\Api\V1\App\BookingController as AppBookingController;
use App\Http\Controllers\Api\V1\App\ClubController as AppClubController;
use App\Http\Controllers\Api\V1\App\LeaderboardController as AppLeaderboardController;
use App\Http\Controllers\Api\V1\App\MeController as AppMeController;
use App\Http\Controllers\Api\V1\App\PricingRuleController as AppPricingRuleController;

// ── Canali (inbound/outbound, nessuna auth) ──────────────────────────
// WhatsApp (Meta Cloud API webhook)
Route::get('/webhook', [WhatsAppController::class, 'verify']);
Route::post('/webhook', [WhatsAppController::class, 'handle']);

// Cron via HTTP: per Plesk che non riesce a eseguire comandi artisan
// Protetto da token segreto per evitare esecuzioni non autorizzate
Route::get('/cron/{token}', function (string $token) {
    if ($token !== config('app.cron_token', 'lecercle_cron_2026')) {
        return response('Forbidden', 403);
    }
    \Illuminate\Support\Facades\Artisan::call('bot:send-reminders');
    \Illuminate\Support\Facades\Artisan::call('bot:send-result-requests');
    \Illuminate\Support\Facades\Artisan::call('bot:send-feedback-requests');
    return response('OK: reminders + results + feedback', 200);
});

// Webchat: inbound via POST, outbound via polling
Route::post('/webchat/message', [WebchatController::class, 'inbound']);
Route::get('/webchat/poll',     [WebchatController::class, 'poll']);

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

    // Feedback
    Route::get('/feedbacks', [\App\Http\Controllers\Api\FeedbackController::class, 'index']);
    Route::post('/feedbacks/{feedback}/read', [\App\Http\Controllers\Api\FeedbackController::class, 'markRead']);
    Route::post('/feedbacks/read-all', [\App\Http\Controllers\Api\FeedbackController::class, 'markAllRead']);

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
    Route::get('/flow/nodes/{node}', [FlowGraphController::class, 'showNode']);
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

    // Moduli compositi (sotto-grafi riusabili)
    Route::get('/flow/composites',                 [FlowCompositeController::class, 'index']);
    Route::post('/flow/composites',                [FlowCompositeController::class, 'store']);
    Route::put('/flow/composites/{composite}',     [FlowCompositeController::class, 'update']);
    Route::delete('/flow/composites/{composite}',  [FlowCompositeController::class, 'destroy']);

    // Sotto-grafo del composito (stesso pattern dei flow_nodes/edges principali)
    Route::get('/flow/composites/{composite}/graph',  [FlowCompositeController::class, 'graph']);
    Route::post('/flow/composites/{composite}/nodes', [FlowCompositeController::class, 'createNode']);
    Route::put('/flow/composites/{composite}/nodes/positions', [FlowCompositeController::class, 'savePositions']);
    Route::put('/flow/composites/{composite}/nodes/{node}',    [FlowCompositeController::class, 'updateNode']);
    Route::delete('/flow/composites/{composite}/nodes/{node}', [FlowCompositeController::class, 'deleteNode']);
    Route::post('/flow/composites/{composite}/edges',          [FlowCompositeController::class, 'createEdge']);
    Route::delete('/flow/composites/{composite}/edges/{edge}', [FlowCompositeController::class, 'deleteEdge']);
});

// ── Mobile App API (v1) — Sanctum bearer tokens ──────────────────────
// Separato dal pannello admin (che usa session auth).
Route::prefix('v1/app')->group(function () {

    // Pubblico (no auth)
    Route::get('/club',                       [AppClubController::class, 'show']);
    Route::get('/health',                     fn() => response()->json(['status' => 'ok', 'server_time' => now()->toIso8601String()]));
    Route::post('/auth/request-otp',          [AppAuthController::class, 'requestOtp']);
    Route::post('/auth/verify-otp',           [AppAuthController::class, 'verifyOtp']);
    Route::post('/auth/request-otp-email',    [AppAuthController::class, 'requestOtpEmail']);

    // Auth required (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/auth/logout',                          [AppAuthController::class, 'logout']);

        // Me / profile
        Route::get('/me',                                    [AppMeController::class, 'show']);
        Route::patch('/me',                                  [AppMeController::class, 'update']);
        Route::patch('/me/notification-preferences',         [AppMeController::class, 'updateNotificationPreferences']);
        Route::post('/me/complete-onboarding',               [AppMeController::class, 'completeOnboarding']);
        Route::delete('/me',                                 [AppMeController::class, 'destroy']);

        // Devices (push)
        Route::post('/me/devices',                           [AppMeController::class, 'registerDevice']);
        Route::delete('/me/devices/{token}',                 [AppMeController::class, 'unregisterDevice']);

        // Bookings
        Route::get('/bookings/next',                         [AppBookingController::class, 'next']);
        Route::get('/bookings/availability',                 [AppBookingController::class, 'availability']);
        Route::get('/bookings',                              [AppBookingController::class, 'index']);
        Route::post('/bookings',                             [AppBookingController::class, 'store']);
        Route::get('/bookings/{id}',                         [AppBookingController::class, 'show']);
        Route::delete('/bookings/{id}',                      [AppBookingController::class, 'destroy']);

        // Players search (per scelta avversario)
        Route::get('/players/search',                        [AppBookingController::class, 'searchPlayers']);

        // Leaderboard
        Route::get('/leaderboard',                           [AppLeaderboardController::class, 'index']);

        // Pricing (read-only)
        Route::get('/pricing-rules',                         [AppPricingRuleController::class, 'index']);
    });
});
