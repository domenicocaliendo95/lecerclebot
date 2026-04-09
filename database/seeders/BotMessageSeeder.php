<?php

namespace Database\Seeders;

use App\Models\BotMessage;
use Illuminate\Database\Seeder;

class BotMessageSeeder extends Seeder
{
    public function run(): void
    {
        $messages = [
            // ── Onboarding ──────────────────────────────────────────
            ['key' => 'nome_non_valido',        'category' => 'onboarding', 'description' => 'Nome non riconosciuto, richiedi di nuovo',        'text' => 'Scusa, non ho capito il tuo nome. Puoi ripetermelo?'],
            ['key' => 'chiedi_fit',             'category' => 'onboarding', 'description' => 'Chiedi se tesserato FIT ({name})',                 'text' => 'Piacere {name}! Sei tesserato FIT? La tessera ci serve per calcolare il tuo livello di gioco.'],
            ['key' => 'fit_non_capito',         'category' => 'onboarding', 'description' => 'Risposta FIT non riconosciuta',                   'text' => 'Scusa, non ho capito. Sei tesserato FIT oppure no?'],
            ['key' => 'chiedi_classifica',      'category' => 'onboarding', 'description' => 'Chiedi classifica FIT',                           'text' => 'Ottimo! Qual è la tua classifica FIT? (es. 4.1, 3.3, NC — la trovi sulla tessera)'],
            ['key' => 'classifica_non_valida',  'category' => 'onboarding', 'description' => 'Classifica FIT non valida',                       'text' => 'Non ho riconosciuto la classifica. Prova con il formato tipo 4.1, 3.3 oppure NC.'],
            ['key' => 'chiedi_livello',         'category' => 'onboarding', 'description' => 'Chiedi livello autodichiarato',                   'text' => 'Nessun problema! Come definiresti il tuo livello di gioco?'],
            ['key' => 'livello_non_valido',     'category' => 'onboarding', 'description' => 'Livello non riconosciuto',                        'text' => 'Non ho capito il livello. Scegli tra Neofita, Dilettante o Avanzato.'],
            ['key' => 'chiedi_eta',             'category' => 'onboarding', 'description' => 'Chiedi età',                                     'text' => "Quanti anni hai? Ci serve per trovare avversari della tua fascia d'età."],
            ['key' => 'eta_non_valida',         'category' => 'onboarding', 'description' => 'Età non valida',                                 'text' => 'Scusa, dimmi la tua età con un numero (es. 30).'],
            ['key' => 'chiedi_fascia_oraria',   'category' => 'onboarding', 'description' => 'Chiedi fascia oraria preferita',                  'text' => 'Ultima cosa: in che fascia oraria preferisci giocare di solito?'],
            ['key' => 'fascia_non_valida',      'category' => 'onboarding', 'description' => 'Fascia oraria non riconosciuta',                  'text' => 'Non ho capito. Preferisci mattina, pomeriggio o sera?'],
            ['key' => 'registrazione_completa', 'category' => 'onboarding', 'description' => 'Registrazione completata ({name})',               'text' => 'Ottimo {name}, sei nel sistema! 🎾 Scrivi "menu" per il menu principale.'],
            ['key' => 'chiedi_nome_nuovo',      'category' => 'onboarding', 'description' => 'Chiedi nome (primo step)',                        'text' => 'Come ti chiami?'],
            ['key' => 'indietro_onboarding',    'category' => 'onboarding', 'description' => 'Conferma ritorno al passo precedente',            'text' => 'Nessun problema, torniamo al passo precedente.'],

            // ── Menu ────────────────────────────────────────────────
            ['key' => 'menu_non_capito',        'category' => 'menu',       'description' => 'Scelta menu non riconosciuta',                    'text' => "Non ho capito la tua scelta. Ecco cosa puoi fare:\n\n🎾 *Prenota campo* — hai già un compagno di gioco? Scegli data e ora e il campo è tuo.\n🔍 *Trovami avversario* — cerco io un avversario del tuo livello e organizzo tutto.\n🎯 *Sparapalline* — prenota il campo con la macchina sparapalline per allenarti da solo.\n\nOppure scrivi \"prenotazioni\" per gestire le tue prenotazioni o \"profilo\" per i tuoi dati."],
            ['key' => 'menu_ritorno',           'category' => 'menu',       'description' => 'Menu principale con opzioni',                     'text' => "Cosa vuoi fare?\n\n🎾 *Prenota campo* — hai già un compagno? Scegli data e ora.\n🔍 *Trovami avversario* — cerco io qualcuno del tuo livello.\n🎯 *Sparapalline* — allenamento da solo con la macchina.\n\nPuoi anche scrivere \"prenotazioni\" per le tue prenotazioni o \"profilo\" per modificare i tuoi dati."],

            // ── Prenotazione ────────────────────────────────────────
            ['key' => 'chiedi_quando',              'category' => 'prenotazione', 'description' => 'Chiedi data/ora per prenotazione',               'text' => "Ottimo, prenotiamo il campo!\n\nDimmi giorno e ora in cui vorresti giocare.\nEsempi: \"domani alle 18\", \"sabato mattina\", \"28 aprile alle 17\"."],
            ['key' => 'chiedi_quando_match',        'category' => 'prenotazione', 'description' => 'Chiedi data/ora per matchmaking',                'text' => "Perfetto, cerco un avversario del tuo livello!\n\nDimmi giorno e ora in cui saresti disponibile.\nEsempi: \"domani alle 18\", \"sabato pomeriggio\", \"28 aprile alle 17\"."],
            ['key' => 'chiedi_quando_sparapalline', 'category' => 'prenotazione', 'description' => 'Chiedi data/ora per sparapalline',               'text' => "Allenamento con lo sparapalline, ottima scelta!\n\nDimmi giorno e ora in cui vorresti il campo.\nEsempi: \"domani alle 18\", \"sabato mattina\", \"28 aprile alle 17\"."],
            ['key' => 'chiedi_durata',              'category' => 'prenotazione', 'description' => 'Chiedi durata campo ({tariffe})',                 'text' => "Per quanto tempo ti serve il campo?\n\n{tariffe}\n\nScegli la durata:"],
            ['key' => 'durata_non_capita',          'category' => 'prenotazione', 'description' => 'Durata non riconosciuta',                        'text' => 'Non ho capito la durata. Scegli tra le opzioni qui sotto.'],
            ['key' => 'data_nel_passato',           'category' => 'prenotazione', 'description' => 'Data nel passato',                               'text' => 'La data che hai indicato è già passata! Scegli una data futura.'],
            ['key' => 'data_non_capita',            'category' => 'prenotazione', 'description' => 'Data/ora non interpretabile',                    'text' => 'Non ho capito quando vorresti venire. Prova con qualcosa tipo "domani alle 17" o "sabato pomeriggio".'],
            ['key' => 'verifico_disponibilita',     'category' => 'prenotazione', 'description' => 'Messaggio di attesa verifica calendario',        'text' => 'Un attimo, verifico la disponibilità... ⏳'],
            ['key' => 'slot_disponibile',           'category' => 'prenotazione', 'description' => 'Slot libero ({slot}, {duration}, {price})',       'text' => "Il campo è libero!\n\n📅 {slot}\n⏱ Durata: {duration}\n💰 Prezzo: €{price}\n\nVuoi prenotare questo slot?"],
            ['key' => 'slot_non_disponibile',       'category' => 'prenotazione', 'description' => 'Slot occupato, mostra alternative',              'text' => "Quell'orario è occupato. Ho trovato questi slot liberi nello stesso giorno:"],
            ['key' => 'nessuna_alternativa',        'category' => 'prenotazione', 'description' => 'Nessuno slot libero nel giorno',                 'text' => 'Mi dispiace, non ci sono slot liberi in quel giorno. Vuoi provare un altro giorno?'],
            ['key' => 'proposta_non_capita',        'category' => 'prenotazione', 'description' => 'Risposta a proposta slot non capita',            'text' => 'Non ho capito. Vuoi prenotare questo slot oppure cambiare orario?'],

            // ── Conferma & Pagamento ────────────────────────────────
            ['key' => 'riepilogo_prenotazione',  'category' => 'conferma',   'description' => 'Riepilogo prenotazione ({slot}, {duration}, {price})', 'text' => "Riepilogo prenotazione:\n\n📅 {slot}\n⏱ Durata: {duration}\n💰 Prezzo: €{price}\n\nCome preferisci pagare?"],
            ['key' => 'scegli_pagamento',        'category' => 'conferma',   'description' => 'Scelta metodo di pagamento',                          'text' => 'Vuoi pagare online o di persona?'],
            ['key' => 'conferma_non_capita',     'category' => 'conferma',   'description' => 'Risposta conferma non capita',                        'text' => 'Scusa, non ho capito. Vuoi confermare, pagare online, o annullare?'],
            ['key' => 'prenotazione_annullata',  'category' => 'conferma',   'description' => 'Prenotazione annullata',                              'text' => 'Prenotazione annullata. Nessun problema! Cosa vuoi fare?'],
            ['key' => 'link_pagamento',          'category' => 'conferma',   'description' => 'Link pagamento online',                               'text' => 'Ecco il link per il pagamento. Una volta completato, la prenotazione sarà confermata!'],
            ['key' => 'prenotazione_confermata', 'category' => 'conferma',   'description' => 'Prenotazione confermata ({slot}, {duration})',         'text' => "Prenotazione confermata! ✅\n\n📅 {slot}\n⏱ Durata: {duration}\n\nTi aspettiamo al circolo!"],

            // ── Gestione prenotazioni ────────────────────────────────
            ['key' => 'nessuna_prenotazione',         'category' => 'gestione',    'description' => 'Nessuna prenotazione attiva',                    'text' => 'Non hai prenotazioni attive al momento. Cosa vuoi fare?'],
            ['key' => 'scegli_prenotazione',          'category' => 'gestione',    'description' => 'Lista prenotazioni da gestire',                  'text' => 'Ecco le tue prossime prenotazioni. Quale vuoi gestire?'],
            ['key' => 'azione_prenotazione',          'category' => 'gestione',    'description' => 'Azioni su prenotazione ({slot})',                'text' => 'Prenotazione: {slot}. Cosa vuoi fare?'],
            ['key' => 'prenotazione_cancellata_ok',   'category' => 'gestione',    'description' => 'Prenotazione cancellata con successo',           'text' => 'Prenotazione annullata. A presto in campo! 🎾 Cosa vuoi fare?'],
            ['key' => 'prenotazione_modifica_quando', 'category' => 'gestione',    'description' => 'Chiedi nuovo orario per modifica',              'text' => 'Ok! Quando vorresti spostare la prenotazione? Dimmi il nuovo giorno e orario.'],

            // ── Modifica profilo ─────────────────────────────────────
            ['key' => 'modifica_profilo_scelta', 'category' => 'profilo',    'description' => 'Scelta campo da modificare',                          'text' => 'Cosa vuoi modificare nel tuo profilo?'],
            ['key' => 'profilo_aggiornato',      'category' => 'profilo',    'description' => 'Profilo aggiornato con successo',                     'text' => 'Perfetto, profilo aggiornato! Cosa vuoi fare?'],

            // ── Matchmaking ─────────────────────────────────────────
            ['key' => 'matchmaking_attesa',              'category' => 'matchmaking', 'description' => 'In attesa di trovare avversario',              'text' => 'Sto cercando il tuo avversario ideale. Ti avviso appena trovo qualcuno! 🔍'],
            ['key' => 'cerca_avversario',                'category' => 'matchmaking', 'description' => 'Ricerca avversario avviata ({slot})',           'text' => 'Perfetto! Cerco un avversario per {slot}. Ti scrivo appena lo trovo! 🔍'],
            ['key' => 'nessun_avversario',               'category' => 'matchmaking', 'description' => 'Nessun avversario trovato',                    'text' => 'Non ho trovato avversari disponibili per questo slot. Vuoi provare un altro orario?'],
            ['key' => 'invito_match',                    'category' => 'matchmaking', 'description' => 'Invito partita ({opponent_name}, {challenger_name}, {slot})', 'text' => 'Ciao {opponent_name}! {challenger_name} ti sfida il {slot}. Accetti?'],
            ['key' => 'invito_match_disparita',          'category' => 'matchmaking', 'description' => 'Invito con disparità ELO ({delta})',            'text' => "Ciao {opponent_name}! {challenger_name} ti sfida il {slot}. Nota: c'è una differenza di livello ({delta} ELO). Accetti?"],
            ['key' => 'match_trovato_disparita',         'category' => 'matchmaking', 'description' => 'Avversario trovato con disparità ELO',         'text' => "Ho trovato un avversario! C'è una differenza di livello ({delta} ELO). Ti ho inviato l'invito. ⚡"],
            ['key' => 'match_accettato_challenger',      'category' => 'matchmaking', 'description' => 'Avversario ha accettato ({opponent_name}, {slot})', 'text' => '{opponent_name} ha accettato! Prenotazione confermata per {slot}. ✅'],
            ['key' => 'match_rifiutato_challenger',      'category' => 'matchmaking', 'description' => 'Avversario ha rifiutato ({opponent_name})',     'text' => '{opponent_name} non è disponibile. Cerca un altro avversario?'],
            ['key' => 'match_accettato_opponent',        'category' => 'matchmaking', 'description' => 'Conferma accettazione sfida ({slot})',          'text' => 'Perfetto! Hai accettato. Ci vediamo il {slot}! 🎾'],
            ['key' => 'match_rifiutato_opponent',        'category' => 'matchmaking', 'description' => 'Conferma rifiuto sfida',                       'text' => 'Ok, sfida rifiutata. A presto al circolo! 🎾'],

            // ── Risultati partita ────────────────────────────────────
            ['key' => 'chiedi_risultato',         'category' => 'risultati',  'description' => 'Chiedi risultato partita ({slot})',                  'text' => "Com'è andata la partita di {slot}? Inserisci il risultato! 🎾"],
            ['key' => 'risultato_ricevuto',       'category' => 'risultati',  'description' => 'Risultato registrato, attesa conferma avversario',   'text' => "Grazie! Risultato registrato. Ti avviso appena anche l'avversario conferma."],
            ['key' => 'risultato_non_capito',     'category' => 'risultati',  'description' => 'Risultato non interpretabile',                      'text' => 'Non ho capito. Hai vinto, hai perso, o la partita non si è giocata?'],
            ['key' => 'risultato_non_giocata',    'category' => 'risultati',  'description' => 'Partita segnata come non giocata',                  'text' => 'Ok, partita segnata come non giocata. A presto in campo! 🎾'],
            ['key' => 'risultato_discordante',    'category' => 'risultati',  'description' => 'Risultati discordanti tra i giocatori',             'text' => "Il tuo avversario ha dichiarato un risultato diverso. L'admin verificherà."],
            ['key' => 'elo_aggiornato_vinto',     'category' => 'risultati',  'description' => 'ELO aggiornato dopo vittoria ({elo_before}, {elo_after}, {delta})', 'text' => 'ELO aggiornato! Ottima vittoria. Eri a {elo_before}, ora sei a {elo_after} (+{delta}). 🏆'],
            ['key' => 'elo_aggiornato_perso',     'category' => 'risultati',  'description' => 'ELO aggiornato dopo sconfitta ({elo_before}, {elo_after}, {delta})', 'text' => 'ELO aggiornato. Eri a {elo_before}, ora sei a {elo_after} ({delta}). Alla prossima! 💪'],

            // ── Feedback ────────────────────────────────────────────
            ['key' => 'chiedi_feedback_rating',     'category' => 'feedback',   'description' => 'Chiedi rating 1-5',                                'text' => "Come valuteresti la tua esperienza al circolo? Dai un voto da 1 a 5.\n\n⭐ 1 = Pessima\n⭐⭐⭐ 3 = Nella media\n⭐⭐⭐⭐⭐ 5 = Ottima"],
            ['key' => 'chiedi_feedback_commento',   'category' => 'feedback',   'description' => 'Chiedi commento opzionale',                        'text' => 'Grazie! Vuoi aggiungere un commento? Scrivi pure, oppure scrivi "no" per saltare.'],
            ['key' => 'feedback_rating_non_valido', 'category' => 'feedback',   'description' => 'Rating non valido',                                'text' => 'Non ho capito. Dammi un voto da 1 a 5 (es. "4" o "quattro stelle").'],
            ['key' => 'feedback_ricevuto',          'category' => 'feedback',   'description' => 'Feedback salvato con successo',                    'text' => 'Grazie per il feedback! Ci aiuta a migliorare. 🙏'],
            ['key' => 'feedback_dopo_partita',      'category' => 'feedback',   'description' => 'Richiesta feedback dopo partita',                  'text' => "Com'è andata al circolo? Lasciaci un voto da 1 a 5! La tua opinione conta."],

            // ── Promemoria ──────────────────────────────────────────
            ['key' => 'reminder_giorno_prima',  'category' => 'promemoria', 'description' => 'Promemoria giorno prima ({slot})',                    'text' => 'Promemoria: hai una prenotazione domani — {slot}. Ti aspettiamo al circolo! 🎾'],
            ['key' => 'reminder_ore_prima',     'category' => 'promemoria', 'description' => 'Promemoria ore prima ({hours}, {slot})',              'text' => 'Ci siamo quasi! La tua prenotazione è tra {hours} ore — {slot}. A tra poco! 🎾'],

            // ── Errore ──────────────────────────────────────────────
            ['key' => 'errore_generico',        'category' => 'errore',     'description' => 'Errore generico, riprova',                            'text' => "Scusa, c'è stato un problema. Riproviamo: quando vorresti giocare?"],
        ];

        foreach ($messages as $msg) {
            BotMessage::updateOrCreate(
                ['key' => $msg['key']],
                $msg,
            );
        }
    }
}
