<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Configura VERIFICA_SLOT come primo stato "action-driven".
 *
 * Note: il handler PHP dedicato (handleVerificaSlot) resta attivo
 * e ha la priorità. Questo seed documenta il pattern nel flow editor
 * e prepara la strada per la rimozione futura dell'handler.
 *
 * Configurazione: on_enter_actions = [check_calendar] + transitions
 * condizionali su data.calendar_available.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bot_flow_states')
            ->where('state', 'VERIFICA_SLOT')
            ->update([
                'on_enter_actions' => json_encode(['check_calendar']),
                'transitions'      => json_encode([
                    ['if' => ['calendar_available' => true],  'then' => 'PROPONI_SLOT'],
                    ['then' => 'SCEGLI_QUANDO'],  // else: slot non disponibile
                ]),
            ]);

        // Configura anche SCEGLI_QUANDO con parse_date come on_enter
        DB::table('bot_flow_states')
            ->where('state', 'SCEGLI_QUANDO')
            ->update([
                'on_enter_actions' => json_encode(['parse_date']),
            ]);
    }

    public function down(): void
    {
        DB::table('bot_flow_states')
            ->whereIn('state', ['VERIFICA_SLOT', 'SCEGLI_QUANDO'])
            ->update([
                'on_enter_actions' => null,
                'transitions'      => null,
            ]);
    }
};
