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
 * bot:retry-matchmaking è stato rimosso durante la migrazione al FlowRunner.
 * Il matchmaking sarà ricostruito come grafo di moduli nel nuovo editor.
 */

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
