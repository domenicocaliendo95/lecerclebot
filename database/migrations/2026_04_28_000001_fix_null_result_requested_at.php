<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: le prenotazioni processate prima del fix $fillable avevano
 * result_requested_at = NULL anche dopo l'invio. Il cron le rimandava
 * ogni 5 minuti. Questa migrazione marca come processate tutte le
 * prenotazioni passate che sono confirmed e con player2.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bookings')
            ->where('status', 'confirmed')
            ->where(function ($q) {
                $q->whereNotNull('player2_id')
                  ->orWhereNotNull('player2_name_text');
            })
            ->whereNull('result_requested_at')
            ->whereRaw("ADDTIME(CONCAT(booking_date, ' ', end_time), '01:00:00') <= NOW()")
            ->update(['result_requested_at' => now()]);
    }

    public function down(): void {}
};
