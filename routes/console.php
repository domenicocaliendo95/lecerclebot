<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Ogni 5 minuti: invia promemoria per prenotazioni imminenti.
 * Dedup su DB (bookings.reminders_sent) — no lock necessario.
 */
Schedule::command('bot:send-reminders')
    ->everyFiveMinutes();

/*
 * Ogni 15 minuti: invia richiesta risultato + feedback post-partita.
 */
Schedule::command('bot:send-result-requests')
    ->everyFifteenMinutes();

Schedule::command('bot:send-feedback-requests')
    ->everyFifteenMinutes();
