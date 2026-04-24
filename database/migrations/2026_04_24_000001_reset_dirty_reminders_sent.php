<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reset reminders_sent sporcati dal bug dry-run che marcava come inviato
 * senza mandare nulla. Pulisce tutte le prenotazioni future.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->where('booking_date', '>=', now()->format('Y-m-d'))
            ->whereNotNull('reminders_sent')
            ->update(['reminders_sent' => null]);
    }

    public function down(): void {}
};
