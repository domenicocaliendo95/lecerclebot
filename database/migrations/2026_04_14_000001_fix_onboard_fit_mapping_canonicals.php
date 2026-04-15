<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: i canonici della mapping di ONBOARD_FIT erano 'yes'/'no' (stringhe),
 * salvati in `profile.is_fit` e poi passati a MySQL (colonna BOOLEAN),
 * causando `Incorrect integer value: 'yes'` e fallimento del save utente.
 *
 * Riscrive le input_rules con canonici 'true'/'false' (UserProfileService::toBool
 * li normalizza a boolean).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('bot_flow_states')
            ->where('state', 'ONBOARD_FIT')
            ->update([
                'input_rules' => json_encode([
                    [
                        'type'       => 'mapping',
                        'options'    => ['false: non sono, non ho, senza tessera, non tesserato, no'],
                        'save_to'    => 'profile.is_fit',
                        'next_state' => 'ONBOARD_LIVELLO',
                        'error_key'  => 'fit_non_capito',
                    ],
                    [
                        'type'       => 'mapping',
                        'options'    => ['true: sì, si, tesserato, ho la tessera, yes'],
                        'save_to'    => 'profile.is_fit',
                        'next_state' => 'ONBOARD_CLASSIFICA',
                        'error_key'  => 'fit_non_capito',
                    ],
                ]),
            ]);
    }

    public function down(): void
    {
        DB::table('bot_flow_states')
            ->where('state', 'ONBOARD_FIT')
            ->update([
                'input_rules' => json_encode([
                    [
                        'type'       => 'mapping',
                        'options'    => ['no: non sono, non ho, senza tessera, non tesserato, no'],
                        'save_to'    => 'profile.is_fit',
                        'next_state' => 'ONBOARD_LIVELLO',
                        'error_key'  => 'fit_non_capito',
                    ],
                    [
                        'type'       => 'mapping',
                        'options'    => ['yes: sì, si, tesserato, ho la tessera, yes'],
                        'save_to'    => 'profile.is_fit',
                        'next_state' => 'ONBOARD_CLASSIFICA',
                        'error_key'  => 'fit_non_capito',
                    ],
                ]),
            ]);
    }
};
