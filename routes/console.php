<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Ogni 15 minuti: invia richiesta risultato ai giocatori
 * la cui partita è terminata da almeno 1 ora.
 *
 * Lanciabile manualmente:
 *   php artisan bot:send-result-requests
 *   php artisan bot:send-result-requests --dry-run
 */
Schedule::command('bot:send-result-requests')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Ogni 5 minuti: riprova matchmaking per chi è in attesa senza avversario.
 * Dopo 30 min senza trovare nessuno, avvisa il challenger e torna al menu.
 *
 * Lanciabile manualmente:
 *   php artisan bot:retry-matchmaking
 *   php artisan bot:retry-matchmaking --dry-run
 */
Schedule::command('bot:retry-matchmaking')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Ogni 15 minuti: invia promemoria per prenotazioni imminenti.
 * Orari configurabili da pannello admin (Impostazioni → Promemoria).
 *
 * Lanciabile manualmente:
 *   php artisan bot:send-reminders
 *   php artisan bot:send-reminders --dry-run
 */
Schedule::command('bot:send-reminders')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
