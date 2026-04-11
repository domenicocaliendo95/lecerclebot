<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            // Nome libero dell'avversario quando NON è (o non vuole essere) tracciato
            // come utente del circolo. Usato per prenotazioni "con_avversario" in cui
            // il giocatore secondario non risulta nel DB users.
            $table->string('player2_name_text', 100)->nullable()->after('player2_id');

            // Timestamp di quando l'avversario tracciato (player2_id) ha confermato
            // di essere effettivamente l'avversario indicato dal challenger.
            // Se NULL → non ancora confermato. Se settato → conferma valida per ELO.
            $table->timestamp('player2_confirmed_at')->nullable()->after('player2_name_text');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn(['player2_name_text', 'player2_confirmed_at']);
        });
    }
};
