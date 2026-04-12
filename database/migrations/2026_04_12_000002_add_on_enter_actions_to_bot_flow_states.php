<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            // Azioni atomiche eseguite PRIMA di mostrare il messaggio,
            // all'ingresso dello stato. I risultati finiscono in session.data
            // e sono leggibili da transitions e input_rules.
            //
            // Formato: ["parse_date", "check_calendar"] — array di stringhe
            // dalla whitelist di ActionExecutor.
            $table->json('on_enter_actions')->nullable()->after('transitions');
        });
    }

    public function down(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            $table->dropColumn('on_enter_actions');
        });
    }
};
