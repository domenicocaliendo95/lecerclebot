<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Ogni 5 minuti: invia promemoria per prenotazioni imminenti.
 * Dedup su DB (bookings.reminders_sent), no cache.
 * Configurazione: Impostazioni → Promemoria.
 * Flusso risposta (es. disdetta): editabile da /panel/flusso.
 */
Schedule::command('bot:send-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Ogni 15 minuti: invia richiesta risultato ai giocatori
 * la cui partita è terminata da almeno 1 ora.
 */
Schedule::command('bot:send-result-requests')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();

/*
 * Ogni 15 minuti: invia richiesta feedback ai giocatori
 * X ore dopo la richiesta risultato. Configurabile in Impostazioni.
 */
Schedule::command('bot:send-feedback-requests')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
