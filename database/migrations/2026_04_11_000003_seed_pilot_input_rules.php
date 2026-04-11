<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Popola i 3 stati pilota con input_rules friendly.
 *
 * Dopo questa migration:
 *  - ONBOARD_NOME      → rule "name", salva in profile.name, transita a ONBOARD_FIT
 *  - ONBOARD_ETA       → rule "integer_range" 5-99, salva in profile.age, transita a ONBOARD_SLOT_PREF
 *  - ONBOARD_CLASSIFICA→ rule "regex" + "mapping" per categorie storiche
 *
 * Gli handler PHP continuano a esistere come fallback (se le rule vengono
 * cancellate dal pannello, il bot torna alla logica hardcoded).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->seedRule('ONBOARD_NOME', [
            [
                'type'        => 'name',
                'transform'   => 'title_case',
                'save_to'     => 'profile.name',
                'next_state'  => 'ONBOARD_FIT',
                'error_key'   => 'nome_non_valido',
            ],
        ]);

        $this->seedRule('ONBOARD_ETA', [
            [
                'type'        => 'integer_range',
                'min'         => 5,
                'max'         => 99,
                'save_to'     => 'profile.age',
                'next_state'  => 'ONBOARD_SLOT_PREF',
                'error_key'   => 'eta_non_valida',
            ],
        ]);

        $this->seedRule('ONBOARD_CLASSIFICA', [
            // Prima prova: pattern formale es. "4.1", "3.3"
            [
                'type'         => 'regex',
                'pattern'      => '^([1-4])[.,]([1-6])$',
                'capture_group'=> 0,
                'transform'    => 'lowercase',
                'save_to'      => 'profile.fit_rating',
                'next_state'   => 'ONBOARD_ETA',
                'error_key'    => 'classifica_non_valida',
            ],
            // Seconda prova: NC (non classificato)
            [
                'type'    => 'mapping',
                'options' => [
                    'NC: nc, non classificato, n.c.',
                ],
                'transform'  => 'uppercase',
                'save_to'    => 'profile.fit_rating',
                'next_state' => 'ONBOARD_ETA',
                'error_key'  => 'classifica_non_valida',
            ],
            // Terza prova: categorie storiche → mappa a 1.1/2.1/3.1/4.1
            [
                'type'    => 'mapping',
                'options' => [
                    '1.1: prima',
                    '2.1: seconda',
                    '3.1: terza',
                    '4.1: quarta',
                ],
                'save_to'    => 'profile.fit_rating',
                'next_state' => 'ONBOARD_ETA',
                'error_key'  => 'classifica_non_valida',
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('bot_flow_states')
            ->whereIn('state', ['ONBOARD_NOME', 'ONBOARD_ETA', 'ONBOARD_CLASSIFICA'])
            ->update(['input_rules' => null]);
    }

    private function seedRule(string $state, array $rules): void
    {
        DB::table('bot_flow_states')
            ->where('state', $state)
            ->update(['input_rules' => json_encode($rules)]);
    }
};
