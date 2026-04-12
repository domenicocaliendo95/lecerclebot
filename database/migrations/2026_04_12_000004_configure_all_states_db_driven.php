<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Configura TUTTI gli stati migrabile con input_rules, transitions e on_enter_actions.
 *
 * Dopo questa migration, ogni stato ha la configurazione DB equivalente alla logica
 * PHP hardcoded. I handler PHP restano come fallback: se le rules vengono rimosse
 * dal pannello, il bot torna automaticamente alla logica originale.
 *
 * IMPORTANTE: questa migration NON cambia il comportamento del bot.
 * Aggiunge solo la configurazione parallela che permette l'editing dal pannello.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── ONBOARD_FIT ─────────────────────────────────
        // Input: "sì"/"no" + varianti. Transizione condizionale: FIT→classifica, non FIT→livello
        $this->update('ONBOARD_FIT', [
            'input_rules' => [
                // Negativo PRIMA (evita "non sono tesserato" matchato come positivo)
                [
                    'type'       => 'mapping',
                    'options'    => ['no: non sono, non ho, senza tessera, non tesserato, no'],
                    'save_to'    => 'profile.is_fit',
                    'transform'  => 'none',
                    'next_state' => 'ONBOARD_LIVELLO',
                    'error_key'  => 'fit_non_capito',
                ],
                [
                    'type'       => 'mapping',
                    'options'    => ['yes: sì, si, tesserato, ho la tessera, yes'],
                    'save_to'    => 'profile.is_fit',
                    'transform'  => 'none',
                    'next_state' => 'ONBOARD_CLASSIFICA',
                    'error_key'  => 'fit_non_capito',
                ],
            ],
        ]);

        // ── ONBOARD_LIVELLO ─────────────────────────────
        $this->update('ONBOARD_LIVELLO', [
            'input_rules' => [
                [
                    'type'       => 'mapping',
                    'options'    => [
                        'neofita: principiante, inizio, newbie',
                        'dilettante: intermedio, medio, amatore',
                        'avanzato: esperto, buono, forte, pro',
                    ],
                    'save_to'    => 'profile.self_level',
                    'next_state' => 'ONBOARD_ETA',
                    'error_key'  => 'livello_non_valido',
                ],
            ],
        ]);

        // ── ONBOARD_SLOT_PREF ───────────────────────────
        $this->update('ONBOARD_SLOT_PREF', [
            'input_rules' => [
                [
                    'type'       => 'mapping',
                    'options'    => [
                        'mattina: mattino, presto, di mattina',
                        'pomeriggio: primo pomeriggio, dopopranzo',
                        'sera: serale, tardi, dopo cena, di sera',
                    ],
                    'save_to'    => 'profile.slot',
                    'next_state' => 'ONBOARD_COMPLETO',
                    'error_key'  => 'fascia_non_valida',
                ],
            ],
        ]);

        // ── ONBOARD_COMPLETO ────────────────────────────
        // Salva il profilo e mostra le opzioni di prenotazione
        $this->update('ONBOARD_COMPLETO', [
            'on_enter_actions' => ['save_profile'],
        ]);

        // ── SCEGLI_DURATA ───────────────────────────────
        // Genera bottoni con le durate disponibili + prezzi
        $this->update('SCEGLI_DURATA', [
            'on_enter_actions' => ['gen_pricing_durations'],
        ]);

        // ── PROPONI_SLOT ────────────────────────────────
        // Se lo slot richiesto non è libero, mostra alternative come bottoni
        $this->update('PROPONI_SLOT', [
            'on_enter_actions' => ['gen_calendar_alternatives'],
        ]);

        // ── CONFERMA ────────────────────────────────────
        // Fork condizionale: matchmaking → cerca avversario, altrimenti pagamento/conferma
        $this->update('CONFERMA', [
            'transitions' => [
                ['if' => ['data.booking_type' => 'matchmaking'], 'then' => 'ATTESA_MATCH'],
            ],
        ]);

        // ── GESTIONE_PRENOTAZIONI ───────────────────────
        // Genera bottoni dalle prenotazioni dell'utente
        $this->update('GESTIONE_PRENOTAZIONI', [
            'on_enter_actions' => ['load_bookings', 'gen_user_bookings'],
        ]);

        // ── MODIFICA_RISPOSTA ───────────────────────────
        // Fork condizionale su update_field → parser diverso per ogni campo
        $this->update('MODIFICA_RISPOSTA', [
            'transitions' => [
                ['if' => ['data.update_field' => 'fit'],       'then' => 'MODIFICA_RISPOSTA'],
                ['if' => ['data.update_field' => 'classifica'],'then' => 'MODIFICA_RISPOSTA'],
                ['if' => ['data.update_field' => 'livello'],   'then' => 'MODIFICA_RISPOSTA'],
                ['if' => ['data.update_field' => 'slot'],      'then' => 'MODIFICA_RISPOSTA'],
            ],
        ]);

        // ── INSERISCI_RISULTATO ─────────────────────────
        $this->update('INSERISCI_RISULTATO', [
            'input_rules' => [
                [
                    'type'       => 'mapping',
                    'options'    => [
                        'won: vinto, ho vinto, ho vin',
                        'lost: perso, ho perso, ho pers',
                        'no_show: non giocata, annullata, non si è, non si e',
                    ],
                    'save_to'    => 'data.result_outcome',
                    'next_state' => 'FEEDBACK',
                    'error_key'  => 'risultato_non_capito',
                    'side_effect'=> 'save_match_result',
                ],
            ],
        ]);

        // ── FEEDBACK ────────────────────────────────────
        $this->update('FEEDBACK', [
            'input_rules' => [
                [
                    'type'       => 'integer_range',
                    'min'        => 1,
                    'max'        => 5,
                    'save_to'    => 'data.feedback_rating',
                    'next_state' => 'FEEDBACK_COMMENTO',
                    'error_key'  => 'feedback_rating_non_valido',
                ],
                [
                    'type'       => 'mapping',
                    'options'    => [
                        '1: uno, una',
                        '2: due',
                        '3: tre',
                        '4: quattro',
                        '5: cinque',
                    ],
                    'save_to'    => 'data.feedback_rating',
                    'transform'  => 'int',
                    'next_state' => 'FEEDBACK_COMMENTO',
                    'error_key'  => 'feedback_rating_non_valido',
                ],
            ],
        ]);

        // ── FEEDBACK_COMMENTO ───────────────────────────
        $this->update('FEEDBACK_COMMENTO', [
            'input_rules' => [
                // "skip" = niente commento
                [
                    'type'       => 'mapping',
                    'options'    => ['skip: no, skip, niente, nulla, passo, salta'],
                    'save_to'    => 'data.feedback_comment',
                    'next_state' => 'MENU',
                    'side_effect'=> 'save_feedback',
                ],
                // Qualsiasi altro testo = il commento
                [
                    'type'       => 'free_text',
                    'save_to'    => 'data.feedback_comment',
                    'next_state' => 'MENU',
                    'side_effect'=> 'save_feedback',
                ],
            ],
        ]);
    }

    public function down(): void
    {
        $states = [
            'ONBOARD_FIT', 'ONBOARD_LIVELLO', 'ONBOARD_SLOT_PREF',
            'ONBOARD_COMPLETO', 'SCEGLI_DURATA', 'PROPONI_SLOT',
            'CONFERMA', 'GESTIONE_PRENOTAZIONI', 'MODIFICA_RISPOSTA',
            'INSERISCI_RISULTATO', 'FEEDBACK', 'FEEDBACK_COMMENTO',
        ];

        DB::table('bot_flow_states')
            ->whereIn('state', $states)
            ->update([
                'input_rules'      => null,
                'transitions'      => null,
                'on_enter_actions' => null,
            ]);
    }

    private function update(string $state, array $data): void
    {
        $update = [];
        if (isset($data['input_rules']))      $update['input_rules']      = json_encode($data['input_rules']);
        if (isset($data['transitions']))       $update['transitions']      = json_encode($data['transitions']);
        if (isset($data['on_enter_actions']))  $update['on_enter_actions'] = json_encode($data['on_enter_actions']);

        if (!empty($update)) {
            DB::table('bot_flow_states')->where('state', $state)->update($update);
        }
    }
};
