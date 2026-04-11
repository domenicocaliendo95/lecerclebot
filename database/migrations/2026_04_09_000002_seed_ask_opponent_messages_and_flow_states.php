<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inserisce i nuovi template messaggi e gli stati di flusso necessari
 * per il flusso ASK_OPPONENT (associazione avversario nelle prenotazioni
 * "con_avversario") e per la conferma bidirezionale (CONFERMA_INVITO_OPP).
 *
 * Idempotente: usa upsert/insert con check di esistenza.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // ── bot_messages ─────────────────────────────────────────
        $messages = [
            ['key' => 'chiedi_avversario',          'category' => 'avversario', 'description' => 'Chiedi nome avversario (input libero)',          'text' => "Con chi giochi? Dimmi nome e cognome dell'avversario.\nSe non lo conosci o non è del circolo, scrivi \"salta\"."],
            ['key' => 'avversario_nome_corto',      'category' => 'avversario', 'description' => 'Nome troppo corto, richiedi',                    'text' => 'Mi serve almeno il nome. Puoi scriverlo per intero?'],
            ['key' => 'avversario_lista',           'category' => 'avversario', 'description' => 'Più match trovati, scegli',                       'text' => 'Ho trovato più giocatori con quel nome. Quale di questi è il tuo avversario?'],
            ['key' => 'avversario_conferma_uno',    'category' => 'avversario', 'description' => 'Match singolo, conferma ({name})',                'text' => 'Ho trovato {name}. È lui/lei il tuo avversario?'],
            ['key' => 'avversario_confermato',      'category' => 'avversario', 'description' => 'Avversario confermato ({name})',                  'text' => 'Perfetto, ho segnato {name} come tuo avversario! Ora dimmi quando vuoi giocare.'],
            ['key' => 'avversario_riprova',         'category' => 'avversario', 'description' => 'Conferma negata, ricerca daccapo',                'text' => "Ok, riproviamo. Dimmi nome e cognome dell'avversario."],
            ['key' => 'avversario_non_trovato',     'category' => 'avversario', 'description' => 'Nessun match nel circolo, salva libero ({name})', 'text' => '{name} non risulta tra i nostri tesserati. Lo segno comunque come avversario esterno. Quando vuoi giocare?'],
            ['key' => 'avversario_esterno',         'category' => 'avversario', 'description' => 'Esplicitamente esterno ({name})',                 'text' => 'Ok, segno {name} come avversario esterno. Quando vuoi giocare?'],
            ['key' => 'avversario_saltato',         'category' => 'avversario', 'description' => 'Skip avversario, no tracking',                    'text' => 'Nessun problema, prenotiamo senza nome avversario. Quando vuoi giocare?'],
            ['key' => 'opp_invite_richiesta',           'category' => 'avversario', 'description' => "Notifica all'avversario taggato ({challenger_name}, {slot})", 'text' => 'Ciao! {challenger_name} ti ha segnato come avversario per la partita di {slot}. Confermi?'],
            ['key' => 'opp_invite_confermato',          'category' => 'avversario', 'description' => 'Avversario conferma il link ({challenger_name}, {slot})',  'text' => 'Perfetto, confermato! Ci vediamo il {slot} con {challenger_name}. 🎾'],
            ['key' => 'opp_invite_rifiutato',           'category' => 'avversario', 'description' => 'Avversario nega il link',                        'text' => 'Ok, ho corretto la prenotazione. Grazie per avercelo detto!'],
            ['key' => 'opp_invite_non_capito',          'category' => 'avversario', 'description' => 'Conferma non capita ({challenger_name}, {slot})', 'text' => 'Scusa, non ho capito. {challenger_name} ti ha segnato come avversario per il {slot}. Confermi?'],
            ['key' => 'opp_invite_notify_challenger_ok','category' => 'avversario', 'description' => 'Notifica al challenger: avversario ha confermato', 'text' => '{opponent_name} ha confermato di essere il tuo avversario per il {slot}! ✅'],
            ['key' => 'opp_invite_notify_challenger_ko','category' => 'avversario', 'description' => 'Notifica al challenger: avversario ha negato',     'text' => '{opponent_name} ha detto di non essere il tuo avversario per il {slot}. La prenotazione resta valida ma senza tracking ELO.'],
        ];

        foreach ($messages as $m) {
            DB::table('bot_messages')->updateOrInsert(
                ['key' => $m['key']],
                array_merge($m, ['created_at' => $now, 'updated_at' => $now]),
            );
        }

        // ── bot_flow_states ──────────────────────────────────────
        $flowStates = [
            [
                'state'        => 'ASK_OPPONENT',
                'type'         => 'complex',
                'message_key'  => 'chiedi_avversario',
                'fallback_key' => 'avversario_nome_corto',
                'buttons'      => null, // Dynamic: lista risultati ricerca
                'category'     => 'avversario',
                'description'  => 'Ricerca avversario (fuzzy match utenti circolo)',
                'sort_order'   => 15,
            ],
            [
                'state'        => 'CONFERMA_INVITO_OPP',
                'type'         => 'simple',
                'message_key'  => 'opp_invite_richiesta',
                'fallback_key' => 'opp_invite_non_capito',
                'buttons'      => json_encode([
                    ['label' => 'Sì, confermo',  'target_state' => 'MENU', 'value' => 'confirm', 'side_effect' => 'opponentLinkConfirmed'],
                    ['label' => 'No, sbagliato', 'target_state' => 'MENU', 'value' => 'reject',  'side_effect' => 'opponentLinkRejected'],
                ]),
                'category'     => 'avversario',
                'description'  => 'Avversario taggato conferma o nega il link (bidirezionale)',
                'sort_order'   => 16,
            ],
        ];

        foreach ($flowStates as $s) {
            $payload = array_merge($s, ['created_at' => $now, 'updated_at' => $now]);
            // Le buttons vanno serializzate solo se non sono già una stringa JSON
            if (isset($payload['buttons']) && is_array($payload['buttons'])) {
                $payload['buttons'] = json_encode($payload['buttons']);
            }
            DB::table('bot_flow_states')->updateOrInsert(
                ['state' => $s['state']],
                $payload,
            );
        }
    }

    public function down(): void
    {
        DB::table('bot_flow_states')->whereIn('state', ['ASK_OPPONENT', 'CONFERMA_INVITO_OPP'])->delete();

        DB::table('bot_messages')->whereIn('key', [
            'chiedi_avversario',
            'avversario_nome_corto',
            'avversario_lista',
            'avversario_conferma_uno',
            'avversario_confermato',
            'avversario_riprova',
            'avversario_non_trovato',
            'avversario_esterno',
            'avversario_saltato',
            'opp_invite_richiesta',
            'opp_invite_confermato',
            'opp_invite_rifiutato',
            'opp_invite_non_capito',
            'opp_invite_notify_challenger_ok',
            'opp_invite_notify_challenger_ko',
        ])->delete();
    }
};
