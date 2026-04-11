<?php

namespace App\Services\Bot;

use App\Models\BotMessage;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

/**
 * UNICO punto di contatto con l'AI (Gemini).
 *
 * Responsabilità:
 * 1. Rendere i messaggi template dal DB (con variabili)
 * 2. Interpretare date/ore in linguaggio naturale
 *
 * NON decide MAI transizioni di stato o logica di business.
 */
class TextGenerator
{
    public function __construct(
        private readonly GeminiService $gemini,
    ) {}

    /**
     * Fallback hardcoded — usati SOLO se il DB non ha il messaggio.
     */
    private const FALLBACKS = [
        'nome_non_valido'        => 'Scusa, non ho capito il tuo nome. Puoi ripetermelo?',
        'chiedi_fit'             => 'Piacere {name}! Sei tesserato FIT?',
        'fit_non_capito'         => 'Scusa, non ho capito. Sei tesserato FIT oppure no?',
        'chiedi_classifica'      => 'Ottimo! Qual è la tua classifica FIT? (es. 4.1, 3.3, NC)',
        'classifica_non_valida'  => 'Non ho riconosciuto la classifica. Prova con il formato tipo 4.1, 3.3 oppure NC.',
        'chiedi_livello'         => 'Nessun problema! Come definiresti il tuo livello di gioco?',
        'livello_non_valido'     => 'Non ho capito il livello. Scegli tra Neofita, Dilettante o Avanzato.',
        'chiedi_eta'             => 'Quanti anni hai?',
        'eta_non_valida'         => 'Scusa, dimmi la tua età con un numero (es. 30).',
        'chiedi_fascia_oraria'   => 'Ultima cosa: in che fascia oraria preferisci giocare di solito?',
        'fascia_non_valida'      => 'Non ho capito. Preferisci mattina, pomeriggio o sera?',
        'registrazione_completa' => 'Ottimo {name}, sei nel sistema! 🎾',
        'menu_non_capito'        => 'Non ho capito la tua scelta.',
        'menu_ritorno'           => 'Cosa vuoi fare?',
        'chiedi_quando'          => 'Dimmi giorno e ora in cui vorresti giocare.',
        'chiedi_quando_match'    => 'Dimmi giorno e ora in cui saresti disponibile.',
        'chiedi_quando_sparapalline' => 'Dimmi giorno e ora in cui vorresti il campo.',
        'chiedi_durata'          => 'Per quanto tempo ti serve il campo?',
        'durata_non_capita'      => 'Non ho capito la durata. Scegli tra le opzioni qui sotto.',
        'data_nel_passato'       => 'La data che hai indicato è già passata! Scegli una data futura.',
        'data_non_capita'        => 'Non ho capito quando vorresti venire. Prova con "domani alle 17" o "sabato pomeriggio".',
        'verifico_disponibilita' => 'Un attimo, verifico la disponibilità... ⏳',
        'slot_disponibile'       => 'Il campo è libero! {slot} — Prezzo: €{price}. Vuoi prenotare?',
        'slot_non_disponibile'   => "Quell'orario è occupato. Ho trovato questi slot liberi:",
        'nessuna_alternativa'    => 'Mi dispiace, non ci sono slot liberi in quel giorno.',
        'proposta_non_capita'    => 'Non ho capito. Vuoi prenotare questo slot oppure cambiare orario?',
        'riepilogo_prenotazione' => 'Riepilogo: {slot} — €{price}. Come preferisci pagare?',
        'scegli_pagamento'       => 'Vuoi pagare online o di persona?',
        'conferma_non_capita'    => 'Scusa, non ho capito. Vuoi confermare, pagare online, o annullare?',
        'prenotazione_annullata' => 'Prenotazione annullata. Cosa vuoi fare?',
        'link_pagamento'         => 'Ecco il link per il pagamento.',
        'prenotazione_confermata' => 'Prenotazione confermata! ✅ {slot}. Ti aspettiamo!',
        'modifica_profilo_scelta' => 'Cosa vuoi modificare nel tuo profilo?',
        'profilo_aggiornato'     => 'Perfetto, profilo aggiornato! Cosa vuoi fare?',
        'chiedi_nome_nuovo'      => 'Come ti chiami?',
        'indietro_onboarding'    => 'Nessun problema, torniamo al passo precedente.',
        'nessuna_prenotazione'   => 'Non hai prenotazioni attive al momento.',
        'scegli_prenotazione'    => 'Ecco le tue prossime prenotazioni. Quale vuoi gestire?',
        'azione_prenotazione'    => 'Prenotazione: {slot}. Cosa vuoi fare?',
        'prenotazione_cancellata_ok' => 'Prenotazione annullata. A presto! 🎾',
        'prenotazione_modifica_quando' => 'Quando vorresti spostare la prenotazione?',
        'matchmaking_attesa'     => 'Sto cercando il tuo avversario ideale. Ti avviso! 🔍',
        'cerca_avversario'       => 'Cerco un avversario per {slot}. Ti scrivo appena lo trovo! 🔍',
        'nessun_avversario'      => 'Nessun avversario disponibile. Vuoi provare un altro orario?',
        'invito_match'           => 'Ciao {opponent_name}! {challenger_name} ti sfida il {slot}. Accetti?',
        'invito_match_disparita' => 'Ciao {opponent_name}! {challenger_name} ti sfida il {slot}. Differenza: {delta} ELO. Accetti?',
        'match_trovato_disparita' => 'Avversario trovato! Differenza: {delta} ELO. Invito inviato. ⚡',
        'match_accettato_challenger' => '{opponent_name} ha accettato! Confermata per {slot}. ✅',
        'match_rifiutato_challenger' => '{opponent_name} non è disponibile. Cerca un altro?',
        'match_accettato_opponent' => 'Hai accettato. Ci vediamo il {slot}! 🎾',
        'match_rifiutato_opponent' => 'Sfida rifiutata. A presto! 🎾',
        'chiedi_risultato'       => "Com'è andata la partita di {slot}? 🎾",
        'risultato_ricevuto'     => 'Risultato registrato. Ti avviso alla conferma.',
        'risultato_non_capito'   => 'Non ho capito. Hai vinto, hai perso, o non si è giocata?',
        'risultato_non_giocata'  => 'Ok, partita non giocata. A presto! 🎾',
        'risultato_discordante'  => "Risultato diverso dall'avversario. L'admin verificherà.",
        'elo_aggiornato_vinto'   => 'ELO aggiornato! Da {elo_before} a {elo_after} (+{delta}). 🏆',
        'elo_aggiornato_perso'   => 'ELO aggiornato. Da {elo_before} a {elo_after} ({delta}). 💪',
        'chiedi_feedback_rating' => 'Come valuteresti la tua esperienza? Voto da 1 a 5.',
        'chiedi_feedback_commento' => 'Grazie! Vuoi aggiungere un commento? Scrivi "no" per saltare.',
        'feedback_rating_non_valido' => 'Non ho capito. Dammi un voto da 1 a 5.',
        'feedback_ricevuto'      => 'Grazie per il feedback! 🙏',
        'feedback_dopo_partita'  => "Com'è andata? Lasciaci un voto da 1 a 5!",
        'reminder_giorno_prima'  => 'Promemoria: prenotazione domani — {slot}. Ti aspettiamo! 🎾',
        'reminder_ore_prima'     => 'Ci siamo! Prenotazione tra {hours} ore — {slot}. A tra poco! 🎾',
        'errore_generico'        => "Scusa, c'è stato un problema. Riprova!",

        // ── Avversario (flusso ASK_OPPONENT) ────────────────────────
        'chiedi_avversario'           => "Con chi giochi? Dimmi nome e cognome dell'avversario.\nSe non lo conosci o non è del circolo, scrivi \"salta\".",
        'avversario_nome_corto'       => 'Mi serve almeno il nome. Puoi scriverlo per intero?',
        'avversario_lista'            => 'Ho trovato più giocatori con quel nome. Quale di questi è il tuo avversario?',
        'avversario_conferma_uno'     => 'Ho trovato {name}. È lui/lei il tuo avversario?',
        'avversario_confermato'       => 'Perfetto, ho segnato {name} come tuo avversario! Ora dimmi quando vuoi giocare.',
        'avversario_riprova'          => "Ok, riproviamo. Dimmi nome e cognome dell'avversario.",
        'avversario_non_trovato'      => '{name} non risulta tra i nostri tesserati. Lo segno comunque come avversario esterno. Quando vuoi giocare?',
        'avversario_esterno'          => 'Ok, segno {name} come avversario esterno. Quando vuoi giocare?',
        'avversario_saltato'          => 'Nessun problema, prenotiamo senza nome avversario. Quando vuoi giocare?',

        // ── Conferma bidirezionale (lato avversario taggato) ────────
        'opp_invite_richiesta'        => "Ciao! {challenger_name} ti ha segnato come avversario per la partita di {slot}. Confermi?",
        'opp_invite_confermato'       => "Perfetto, confermato! Ci vediamo il {slot} con {challenger_name}. 🎾",
        'opp_invite_rifiutato'        => 'Ok, ho corretto la prenotazione. Grazie per avercelo detto!',
        'opp_invite_non_capito'       => "Scusa, non ho capito. {challenger_name} ti ha segnato come avversario per il {slot}. Confermi?",
        'opp_invite_notify_challenger_ok' => '{opponent_name} ha confermato di essere il tuo avversario per il {slot}! ✅',
        'opp_invite_notify_challenger_ko' => '{opponent_name} ha detto di non essere il tuo avversario per il {slot}. La prenotazione resta valida ma senza tracking ELO.',
    ];

    /**
     * Restituisce il messaggio template con variabili sostituite.
     *
     * Legge dal DB (con cache), fallback alla costante hardcoded.
     * NON usa più Gemini per riformulare — i messaggi sono quelli configurati nel pannello.
     */
    public function rephrase(string $templateId, string $persona, array $vars = []): string
    {
        return $this->renderTemplate($templateId, $vars);
    }

    /**
     * Interpreta una data/ora in linguaggio naturale.
     *
     * Strategia: parser locale deterministico PRIMA, Gemini solo come fallback.
     *
     * @return array{date: string, time: string, friendly: string}|null
     */
    public function parseDateTime(string $input): ?array
    {
        $result = $this->parseDateTimeLocal($input);

        if ($result !== null) {
            return $result;
        }

        // Fallback AI solo per input davvero complessi
        return $this->parseDateTimeWithAi($input);
    }

    /**
     * Classifica l'input utente rispetto ai bottoni disponibili usando Gemini.
     *
     * Usato come fallback quando il matching deterministico (keyword) fallisce.
     * Restituisce l'indice del bottone più probabile, o null se incerto.
     *
     * @param string $input Testo dell'utente
     * @param array  $buttonLabels Lista delle label dei bottoni
     * @return int|null Indice del bottone matchato, o null
     */
    public function classifyInput(string $input, array $buttonLabels): ?int
    {
        if (empty($buttonLabels)) {
            return null;
        }

        $options = '';
        foreach ($buttonLabels as $i => $label) {
            $options .= ($i + 1) . ". {$label}\n";
        }

        $prompt = <<<PROMPT
Sei un classificatore di intenti per un bot WhatsApp di un circolo tennis. L'utente ha scritto un messaggio e deve scegliere tra queste opzioni:

{$options}
Messaggio dell'utente: "{$input}"

Quale opzione intende l'utente? Rispondi SOLO con il numero dell'opzione (es. "1", "2", "3").
Se il messaggio non corrisponde chiaramente a nessuna opzione, rispondi "0".
Rispondi SOLO con un numero, nient'altro.
PROMPT;

        try {
            $reply = $this->gemini->generate($prompt);
            $number = (int) trim($reply);

            if ($number >= 1 && $number <= count($buttonLabels)) {
                Log::info('TextGenerator: AI classified input', [
                    'input'   => $input,
                    'matched' => $buttonLabels[$number - 1],
                    'index'   => $number - 1,
                ]);
                return $number - 1;
            }

            Log::info('TextGenerator: AI could not classify input', [
                'input' => $input,
                'reply' => $reply,
            ]);
            return null;
        } catch (\Throwable $e) {
            Log::warning('TextGenerator: AI classification failed', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Parser LOCALE deterministico per date/ore in italiano.
     * Gestisce: oggi, domani, dopodomani, giorni della settimana,
     * date esplicite (28 marzo, 28/03), orari (alle 15, alle 9:30).
     */
    private function parseDateTimeLocal(string $input): ?array
    {
        $clean = mb_strtolower(trim($input));
        $now   = now();

        $date = null;
        $time = null;

        /* ─── 1. Estrai la DATA ─── */

        // "oggi"
        if (preg_match('/\boggi\b/', $clean)) {
            $date = $now->copy();
        }
        // "domani"
        elseif (preg_match('/\bdomani\b/', $clean)) {
            $date = $now->copy()->addDay();
        }
        // "dopodomani"
        elseif (preg_match('/\bdopodomani\b/', $clean)) {
            $date = $now->copy()->addDays(2);
        }
        // Giorno della settimana: "lunedì", "martedì prossimo", ecc.
        else {
            $giorni = [
                'lunedi' => 1, 'lunedì' => 1,
                'martedi' => 2, 'martedì' => 2,
                'mercoledi' => 3, 'mercoledì' => 3,
                'giovedi' => 4, 'giovedì' => 4,
                'venerdi' => 5, 'venerdì' => 5,
                'sabato' => 6,
                'domenica' => 0,
            ];

            foreach ($giorni as $nome => $dayOfWeek) {
                if (str_contains($clean, $nome)) {
                    $date = $now->copy()->next($dayOfWeek);
                    // Se il giorno è oggi e l'utente non ha detto "prossimo",
                    // usa oggi (a meno che non sia già passato)
                    if ($now->dayOfWeek === $dayOfWeek && !str_contains($clean, 'prossim')) {
                        $date = $now->copy();
                    }
                    break;
                }
            }
        }

        // Data esplicita: "28 marzo", "28/03", "28-03", "28/3"
        if ($date === null) {
            $mesi = [
                'gennaio' => 1, 'febbraio' => 2, 'marzo' => 3, 'aprile' => 4,
                'maggio' => 5, 'giugno' => 6, 'luglio' => 7, 'agosto' => 8,
                'settembre' => 9, 'ottobre' => 10, 'novembre' => 11, 'dicembre' => 12,
            ];

            // "28 marzo" o "28 mar"
            foreach ($mesi as $nomeMese => $numMese) {
                $abbr = mb_substr($nomeMese, 0, 3);
                if (preg_match('/\b(\d{1,2})\s*(?:' . preg_quote($nomeMese) . '|' . preg_quote($abbr) . ')\b/', $clean, $m)) {
                    $day = (int) $m[1];
                    $year = $now->year;
                    $candidate = $now->copy()->setDate($year, $numMese, min($day, 31));
                    if ($candidate->lt($now->copy()->startOfDay())) {
                        $candidate->addYear();
                    }
                    $date = $candidate;
                    break;
                }
            }
        }

        // "28/03", "28-03", "28/3/2026"
        if ($date === null && preg_match('/\b(\d{1,2})[\/\-](\d{1,2})(?:[\/\-](\d{2,4}))?\b/', $clean, $m)) {
            $day   = (int) $m[1];
            $month = (int) $m[2];
            $year  = isset($m[3]) ? (int) $m[3] : $now->year;
            if ($year < 100) $year += 2000;

            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                $candidate = $now->copy()->setDate($year, $month, $day);
                if ($candidate->lt($now->copy()->startOfDay()) && !isset($m[3])) {
                    $candidate->addYear();
                }
                $date = $candidate;
            }
        }

        /* ─── 2. Estrai l'ORA ─── */

        // "alle 15", "alle 9", "alle 15:30", "ore 18", "h 10", "alle 9.30"
        if (preg_match('/(?:alle|ore|h|per le)\s*(\d{1,2})(?:[:.](\d{2}))?\b/', $clean, $m)) {
            $hour = (int) $m[1];
            $min  = isset($m[2]) ? (int) $m[2] : 0;

            if ($hour >= 0 && $hour <= 23 && $min >= 0 && $min <= 59) {
                $time = sprintf('%02d:%02d', $hour, $min);
            }
        }

        // Ora senza prefisso se c'è già una data: "domani 15", "sabato 18:00"
        if ($time === null && $date !== null) {
            if (preg_match('/\b(\d{1,2})(?:[:.](\d{2}))?\s*$/', $clean, $m)) {
                $hour = (int) $m[1];
                $min  = isset($m[2]) ? (int) $m[2] : 0;
                if ($hour >= 6 && $hour <= 23 && $min >= 0 && $min <= 59) {
                    $time = sprintf('%02d:%02d', $hour, $min);
                }
            }
        }

        // Fasce orarie generiche
        if ($time === null && $date !== null) {
            if (str_contains($clean, 'mattina') || str_contains($clean, 'mattino')) {
                $time = '09:00';
            } elseif (str_contains($clean, 'pranzo')) {
                $time = '13:00';
            } elseif (str_contains($clean, 'pomeriggio')) {
                $time = '15:00';
            } elseif (str_contains($clean, 'sera') || str_contains($clean, 'serale')) {
                $time = '19:00';
            }
        }

        /* ─── 3. Se non abbiamo almeno la data, fallisci ─── */
        if ($date === null) {
            return null;
        }

        /* ─── 4. Costruisci la risposta friendly ─── */
        $giorniIt = ['domenica', 'lunedì', 'martedì', 'mercoledì', 'giovedì', 'venerdì', 'sabato'];
        $mesiIt   = ['', 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno',
            'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

        $dayName   = $giorniIt[$date->dayOfWeek];
        $dayNum    = $date->day;
        $monthName = $mesiIt[$date->month];

        $friendly = "{$dayName} {$dayNum} {$monthName}";
        if ($time !== null) {
            $friendly .= " alle {$time}";
        }

        return [
            'date'     => $date->format('Y-m-d'),
            'time'     => $time,
            'friendly' => $friendly,
        ];
    }

    /**
     * Fallback AI per input che il parser locale non riesce a gestire.
     */
    private function parseDateTimeWithAi(string $input): ?array
    {
        $today     = now()->format('Y-m-d');
        $dayOfWeek = now()->locale('it')->dayName;

        $prompt = <<<PROMPT
Sei un parser di date. Oggi è {$dayOfWeek} {$today}.

L'utente ha scritto: "{$input}"

Rispondi SOLO con un JSON valido (senza markdown, senza ```):
{
  "date": "YYYY-MM-DD",
  "time": "HH:MM",
  "friendly": "descrizione leggibile in italiano"
}

Se non specifica l'ora, "time" deve essere null.
Se non riesci a interpretare, rispondi: {"error": true}
PROMPT;

        try {
            $reply  = $this->gemini->generate($prompt);
            $clean  = preg_replace('/```json\s*|\s*```/', '', trim($reply));
            $parsed = json_decode($clean, true);

            if (json_last_error() !== JSON_ERROR_NONE || !empty($parsed['error'])) {
                Log::info('TextGenerator: AI date parse returned error/invalid JSON', [
                    'input' => $input,
                    'reply' => $reply,
                ]);
                return null;
            }

            if (empty($parsed['date'])) {
                return null;
            }

            // Validazione formato data
            $dateObj = \DateTime::createFromFormat('Y-m-d', $parsed['date']);
            if (!$dateObj || $dateObj->format('Y-m-d') !== $parsed['date']) {
                return null;
            }

            // Non permettere date nel passato
            if ($dateObj < now()->startOfDay()) {
                return null;
            }

            // Validazione ora — accetta sia HH:MM che H:MM
            if (!empty($parsed['time'])) {
                if (preg_match('/^(\d{1,2}):(\d{2})$/', $parsed['time'], $m)) {
                    $parsed['time'] = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
                } else {
                    $parsed['time'] = null;
                }
            }

            return [
                'date'     => $parsed['date'],
                'time'     => $parsed['time'] ?? null,
                'friendly' => $parsed['friendly'] ?? $parsed['date'],
            ];
        } catch (\Throwable $e) {
            Log::warning('TextGenerator: AI date parse failed', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /* ───────── Metodi privati ───────── */

    private function renderTemplate(string $templateId, array $vars): string
    {
        $fallback = self::FALLBACKS[$templateId] ?? 'Mi scusi, qualcosa è andato storto.';
        $text = BotMessage::get($templateId, $fallback);

        foreach ($vars as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $text = str_replace("{{$key}}", (string) $value, $text);
            }
        }

        return $text;
    }
}
