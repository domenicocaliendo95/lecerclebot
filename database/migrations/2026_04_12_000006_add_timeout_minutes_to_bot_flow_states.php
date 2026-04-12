<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            // Timeout in minuti. Se la sessione è in questo stato da più tempo,
            // viene resettata a MENU al prossimo messaggio.
            // NULL = usa il default globale da bot_settings (chiave 'session_timeout_minutes').
            // 0 = nessun timeout (es. onboarding).
            $table->unsignedInteger('timeout_minutes')->nullable()->after('on_enter_actions');
        });

        // Default globale: 120 minuti (2 ore)
        DB::table('bot_settings')->updateOrInsert(
            ['key' => 'session_timeout_minutes'],
            [
                'value'      => json_encode(120),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        // Onboarding: nessun timeout (l'utente deve completare la registrazione)
        DB::table('bot_flow_states')
            ->whereIn('state', [
                'NEW', 'ONBOARD_NOME', 'ONBOARD_FIT', 'ONBOARD_CLASSIFICA',
                'ONBOARD_LIVELLO', 'ONBOARD_ETA', 'ONBOARD_SLOT_PREF', 'ONBOARD_COMPLETO',
            ])
            ->update(['timeout_minutes' => 0]);
    }

    public function down(): void
    {
        Schema::table('bot_flow_states', function (Blueprint $table) {
            $table->dropColumn('timeout_minutes');
        });
        DB::table('bot_settings')->where('key', 'session_timeout_minutes')->delete();
    }
};
