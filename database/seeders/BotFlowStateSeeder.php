<?php

namespace Database\Seeders;

use App\Models\BotFlowState;
use Illuminate\Database\Seeder;

class BotFlowStateSeeder extends Seeder
{
    public function run(): void
    {
        $states = [
            // ── Saluti ──────────────────────────────────────────────
            [
                'state'        => 'NEW',
                'type'         => 'complex',
                'message_key'  => 'saluto_nuovo',
                'fallback_key' => null,
                'buttons'      => null,
                'category'     => 'saluti',
                'description'  => 'Primo contatto — saluto iniziale (nuovo utente: saluto_nuovo, utente registrato: saluto_ritorno)',
                'sort_order'   => 1,
            ],

            // ── Onboarding ──────────────────────────────────────────
            [
                'state'        => 'ONBOARD_NOME',
                'type'         => 'complex',
                'message_key'  => 'chiedi_fit',
                'fallback_key' => 'nome_non_valido',
                'buttons'      => null,
                'category'     => 'onboarding',
                'description'  => 'Validazione nome (lettere, 2-60 char, Title Case)',
                'sort_order'   => 2,
            ],
            [
                'state'        => 'ONBOARD_FIT',
                'type'         => 'simple',
                'message_key'  => 'chiedi_fit',
                'fallback_key' => 'fit_non_capito',
                'buttons'      => [
                    ['label' => 'Sì, sono tesserato', 'target_state' => 'ONBOARD_CLASSIFICA', 'value' => 'yes'],
                    ['label' => 'Non sono tesserato', 'target_state' => 'ONBOARD_LIVELLO', 'value' => 'no'],
                ],
                'category'     => 'onboarding',
                'description'  => 'Chiede se tesserato FIT',
                'sort_order'   => 3,
            ],
            [
                'state'        => 'ONBOARD_CLASSIFICA',
                'type'         => 'complex',
                'message_key'  => 'chiedi_classifica',
                'fallback_key' => 'classifica_non_valida',
                'buttons'      => null,
                'category'     => 'onboarding',
                'description'  => 'Parsing classifica FIT (4.1, NC, ecc.)',
                'sort_order'   => 4,
            ],
            [
                'state'        => 'ONBOARD_LIVELLO',
                'type'         => 'simple',
                'message_key'  => 'chiedi_livello',
                'fallback_key' => 'livello_non_valido',
                'buttons'      => [
                    ['label' => 'Neofita', 'target_state' => 'ONBOARD_ETA', 'value' => 'neofita'],
                    ['label' => 'Dilettante', 'target_state' => 'ONBOARD_ETA', 'value' => 'dilettante'],
                    ['label' => 'Avanzato', 'target_state' => 'ONBOARD_ETA', 'value' => 'avanzato'],
                ],
                'category'     => 'onboarding',
                'description'  => 'Livello autodichiarato (3 opzioni)',
                'sort_order'   => 5,
            ],
            [
                'state'        => 'ONBOARD_ETA',
                'type'         => 'complex',
                'message_key'  => 'chiedi_eta',
                'fallback_key' => 'eta_non_valida',
                'buttons'      => null,
                'category'     => 'onboarding',
                'description'  => 'Parsing età (numero 5-99)',
                'sort_order'   => 6,
            ],
            [
                'state'        => 'ONBOARD_SLOT_PREF',
                'type'         => 'simple',
                'message_key'  => 'chiedi_fascia_oraria',
                'fallback_key' => 'fascia_non_valida',
                'buttons'      => [
                    ['label' => 'Mattina', 'target_state' => 'ONBOARD_COMPLETO', 'value' => 'mattina'],
                    ['label' => 'Pomeriggio', 'target_state' => 'ONBOARD_COMPLETO', 'value' => 'pomeriggio'],
                    ['label' => 'Sera', 'target_state' => 'ONBOARD_COMPLETO', 'value' => 'sera'],
                ],
                'category'     => 'onboarding',
                'description'  => 'Fascia oraria preferita',
                'sort_order'   => 7,
            ],
            [
                'state'        => 'ONBOARD_COMPLETO',
                'type'         => 'simple',
                'message_key'  => 'registrazione_completa',
                'fallback_key' => 'menu_non_capito',
                'buttons'      => [
                    ['label' => 'Prenota campo', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'con_avversario'],
                    ['label' => 'Trovami avversario', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'matchmaking'],
                    ['label' => 'Sparapalline', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'sparapalline'],
                ],
                'category'     => 'onboarding',
                'description'  => 'Registrazione completata, mostra menu',
                'sort_order'   => 8,
            ],

            // ── Menu ────────────────────────────────────────────────
            [
                'state'        => 'MENU',
                'type'         => 'simple',
                'message_key'  => 'menu_ritorno',
                'fallback_key' => 'menu_non_capito',
                'buttons'      => [
                    ['label' => 'Prenota campo', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'con_avversario'],
                    ['label' => 'Trovami avversario', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'matchmaking'],
                    ['label' => 'Sparapalline', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'sparapalline'],
                ],
                'category'     => 'menu',
                'description'  => 'Menu principale — scelta azione',
                'sort_order'   => 10,
            ],

            // ── Avversario (flusso ASK_OPPONENT) ────────────────────
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
                'buttons'      => [
                    ['label' => 'Sì, confermo',  'target_state' => 'MENU', 'value' => 'confirm', 'side_effect' => 'opponentLinkConfirmed'],
                    ['label' => 'No, sbagliato', 'target_state' => 'MENU', 'value' => 'reject',  'side_effect' => 'opponentLinkRejected'],
                ],
                'category'     => 'avversario',
                'description'  => 'Avversario taggato conferma o nega il link (bidirezionale)',
                'sort_order'   => 16,
            ],

            // ── Prenotazione ────────────────────────────────────────
            [
                'state'        => 'SCEGLI_QUANDO',
                'type'         => 'complex',
                'message_key'  => 'chiedi_quando',
                'fallback_key' => 'data_non_capita',
                'buttons'      => null,
                'category'     => 'prenotazione',
                'description'  => 'Parsing data/ora in linguaggio naturale',
                'sort_order'   => 20,
            ],
            [
                'state'        => 'SCEGLI_DURATA',
                'type'         => 'complex',
                'message_key'  => 'chiedi_durata',
                'fallback_key' => 'durata_non_capita',
                'buttons'      => null, // Dynamic: generated from PricingRule
                'category'     => 'prenotazione',
                'description'  => 'Scelta durata (bottoni generati da regole prezzi)',
                'sort_order'   => 21,
            ],
            [
                'state'        => 'VERIFICA_SLOT',
                'type'         => 'complex',
                'message_key'  => 'verifico_disponibilita',
                'fallback_key' => 'errore_generico',
                'buttons'      => null,
                'category'     => 'prenotazione',
                'description'  => 'Verifica disponibilità via Google Calendar',
                'sort_order'   => 22,
            ],
            [
                'state'        => 'PROPONI_SLOT',
                'type'         => 'complex',
                'message_key'  => 'slot_disponibile',
                'fallback_key' => 'proposta_non_capita',
                'buttons'      => [
                    ['label' => 'Sì, prenota', 'target_state' => 'CONFERMA', 'value' => 'yes'],
                    ['label' => 'No, cambia orario', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'no'],
                ],
                'category'     => 'prenotazione',
                'description'  => 'Proposta slot — conferma o alternative',
                'sort_order'   => 23,
            ],

            // ── Conferma & Pagamento ────────────────────────────────
            [
                'state'        => 'CONFERMA',
                'type'         => 'complex',
                'message_key'  => 'riepilogo_prenotazione',
                'fallback_key' => 'conferma_non_capita',
                'buttons'      => [
                    ['label' => 'Paga online', 'target_state' => 'PAGAMENTO', 'value' => 'online', 'side_effect' => 'paymentRequired'],
                    ['label' => 'Pago di persona', 'target_state' => 'CONFERMATO', 'value' => 'in_loco', 'side_effect' => 'bookingToCreate'],
                    ['label' => 'Annulla', 'target_state' => 'MENU', 'value' => 'cancel'],
                ],
                'category'     => 'conferma',
                'description'  => 'Riepilogo e scelta pagamento (bottoni diversi per matchmaking)',
                'sort_order'   => 30,
            ],
            [
                'state'        => 'PAGAMENTO',
                'type'         => 'complex',
                'message_key'  => 'link_pagamento',
                'fallback_key' => null,
                'buttons'      => null,
                'category'     => 'conferma',
                'description'  => 'Pagamento online in corso',
                'sort_order'   => 31,
            ],
            [
                'state'        => 'CONFERMATO',
                'type'         => 'simple',
                'message_key'  => 'prenotazione_confermata',
                'fallback_key' => null,
                'buttons'      => [
                    ['label' => 'Prenota campo', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'con_avversario'],
                    ['label' => 'Trovami avversario', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'matchmaking'],
                    ['label' => 'Sparapalline', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'sparapalline'],
                ],
                'category'     => 'conferma',
                'description'  => 'Prenotazione confermata — qualsiasi input torna al menu',
                'sort_order'   => 32,
            ],

            // ── Matchmaking ─────────────────────────────────────────
            [
                'state'        => 'ATTESA_MATCH',
                'type'         => 'simple',
                'message_key'  => 'matchmaking_attesa',
                'fallback_key' => null,
                'buttons'      => null,
                'category'     => 'matchmaking',
                'description'  => 'In attesa risposta avversario (annulla → menu)',
                'sort_order'   => 40,
            ],
            [
                'state'        => 'RISPOSTA_MATCH',
                'type'         => 'simple',
                'message_key'  => 'invito_match',
                'fallback_key' => 'invito_match',
                'buttons'      => [
                    ['label' => 'Accetta', 'target_state' => 'CONFERMATO', 'value' => 'accept', 'side_effect' => 'matchAccepted'],
                    ['label' => 'Rifiuta', 'target_state' => 'MENU', 'value' => 'refuse', 'side_effect' => 'matchRefused'],
                ],
                'category'     => 'matchmaking',
                'description'  => 'Avversario decide se accettare la sfida',
                'sort_order'   => 41,
            ],

            // ── Gestione prenotazioni ───────────────────────────────
            [
                'state'        => 'GESTIONE_PRENOTAZIONI',
                'type'         => 'complex',
                'message_key'  => 'scegli_prenotazione',
                'fallback_key' => 'scegli_prenotazione',
                'buttons'      => null, // Dynamic: generated from user bookings
                'category'     => 'gestione',
                'description'  => 'Lista prenotazioni attive (bottoni dinamici)',
                'sort_order'   => 50,
            ],
            [
                'state'        => 'AZIONE_PRENOTAZIONE',
                'type'         => 'simple',
                'message_key'  => 'azione_prenotazione',
                'fallback_key' => 'azione_prenotazione',
                'buttons'      => [
                    ['label' => 'Modifica orario', 'target_state' => 'SCEGLI_QUANDO', 'value' => 'modify'],
                    ['label' => 'Cancella', 'target_state' => 'MENU', 'value' => 'cancel', 'side_effect' => 'bookingToCancel'],
                    ['label' => 'Torna al menu', 'target_state' => 'MENU', 'value' => 'back'],
                ],
                'category'     => 'gestione',
                'description'  => 'Azione su prenotazione selezionata',
                'sort_order'   => 51,
            ],

            // ── Modifica profilo ─────────────────────────────────────
            [
                'state'        => 'MODIFICA_PROFILO',
                'type'         => 'simple',
                'message_key'  => 'modifica_profilo_scelta',
                'fallback_key' => 'modifica_profilo_scelta',
                'buttons'      => [
                    ['label' => 'Stato FIT', 'target_state' => 'MODIFICA_RISPOSTA', 'value' => 'fit'],
                    ['label' => 'Livello gioco', 'target_state' => 'MODIFICA_RISPOSTA', 'value' => 'livello'],
                    ['label' => 'Fascia oraria', 'target_state' => 'MODIFICA_RISPOSTA', 'value' => 'slot'],
                ],
                'category'     => 'profilo',
                'description'  => 'Scelta campo da modificare',
                'sort_order'   => 60,
            ],
            [
                'state'        => 'MODIFICA_RISPOSTA',
                'type'         => 'complex',
                'message_key'  => 'modifica_profilo_scelta',
                'fallback_key' => null,
                'buttons'      => null, // Dynamic: depends on update_field
                'category'     => 'profilo',
                'description'  => 'Raccolta nuovo valore (parsing specifico per campo)',
                'sort_order'   => 61,
            ],

            // ── Risultati ───────────────────────────────────────────
            [
                'state'        => 'INSERISCI_RISULTATO',
                'type'         => 'complex',
                'message_key'  => 'chiedi_risultato',
                'fallback_key' => 'risultato_non_capito',
                'buttons'      => [
                    ['label' => 'Ho vinto', 'target_state' => 'FEEDBACK', 'value' => 'won', 'side_effect' => 'matchResultToSave'],
                    ['label' => 'Ho perso', 'target_state' => 'FEEDBACK', 'value' => 'lost', 'side_effect' => 'matchResultToSave'],
                    ['label' => 'Non giocata', 'target_state' => 'MENU', 'value' => 'no_show', 'side_effect' => 'matchResultToSave'],
                ],
                'category'     => 'risultati',
                'description'  => 'Risultato partita + parsing punteggio opzionale',
                'sort_order'   => 70,
            ],

            // ── Feedback ────────────────────────────────────────────
            [
                'state'        => 'FEEDBACK',
                'type'         => 'complex',
                'message_key'  => 'chiedi_feedback_rating',
                'fallback_key' => 'feedback_rating_non_valido',
                'buttons'      => [
                    ['label' => '1', 'target_state' => 'FEEDBACK_COMMENTO', 'value' => '1'],
                    ['label' => '2', 'target_state' => 'FEEDBACK_COMMENTO', 'value' => '2'],
                    ['label' => '3', 'target_state' => 'FEEDBACK_COMMENTO', 'value' => '3'],
                ],
                'category'     => 'feedback',
                'description'  => 'Rating 1-5 (max 3 bottoni WhatsApp, anche testo libero)',
                'sort_order'   => 80,
            ],
            [
                'state'        => 'FEEDBACK_COMMENTO',
                'type'         => 'simple',
                'message_key'  => 'chiedi_feedback_commento',
                'fallback_key' => null,
                'buttons'      => null,
                'category'     => 'feedback',
                'description'  => 'Commento opzionale (testo libero o "no" per saltare)',
                'sort_order'   => 81,
            ],
        ];

        foreach ($states as $state) {
            BotFlowState::updateOrCreate(
                ['state' => $state['state']],
                $state,
            );
        }
    }
}
