<?php

namespace App\Services\Bot;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * UNICO punto di contatto con l'AI (Gemini).
 *
 * Responsabilità:
 * 1. Riformulare testi predefiniti in modo naturale e vario
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
     * Template dei messaggi con testo di fallback.
     * La chiave è l'ID del template; il valore è il testo base.
     */
    private const TEMPLATES = [
        // Onboarding
        'nome_non_valido'        => 'Scusa, non ho capito il tuo nome. Puoi ripetermelo?',
        'chiedi_fit'             => 'Piacere {name}! Sei tesserato FIT?',
        'fit_non_capito'         => 'Scusa, non ho capito. Sei tesserato FIT oppure no?',
        'chiedi_classifica'      => 'Ottimo! Qual è la tua classifica FIT? (es. 4.1, 3.3, NC)',
        'classifica_non_valida'  => 'Non ho riconosciuto la classifica. Prova con il formato tipo 4.1, 3.3 oppure NC.',
        'chiedi_livello'         => 'Nessun problema! Come definiresti il tuo livello?',
        'livello_non_valido'     => 'Non ho capito il livello. Scegli tra Neofita, Dilettante o Avanzato.',
        'chiedi_eta'             => 'Quanti anni hai?',
        'eta_non_valida'         => 'Scusa, dimmi la tua età con un numero (es. 30).',
        'chiedi_fascia_oraria'   => 'Ultima cosa: quando preferisci giocare di solito?',
        'fascia_non_valida'      => 'Non ho capito. Preferisci mattina, pomeriggio o sera?',
        'registrazione_completa' => 'Perfetto {name}, sei registrato! 🎉 Cosa vuoi fare?',

        // Menu
        'menu_non_capito'        => 'Scusa, non ho capito. Cosa preferisci fare?',
        'menu_ritorno'           => 'Ci sono per te! Cosa vuoi fare?',

        // Prenotazione
        'chiedi_quando'            => 'Quando vorresti giocare? Dimmi giorno e ora (es. domani alle 18, sabato mattina...).',
        'chiedi_quando_match'      => 'Quando saresti disponibile per una partita? Dimmi giorno e ora.',
        'chiedi_quando_sparapalline' => 'Quando vorresti usare lo sparapalline? Dimmi giorno e ora.',
        'data_non_capita'          => 'Non ho capito quando vorresti venire. Prova con qualcosa tipo "domani alle 17" o "sabato pomeriggio".',
        'verifico_disponibilita'   => 'Un attimo, verifico la disponibilità... ⏳',
        'slot_disponibile'         => 'Ottima notizia! {slot} è libero. Confermo la prenotazione?',
        'slot_non_disponibile'     => 'Purtroppo quell\'orario non è disponibile. Ho trovato queste alternative:',
        'nessuna_alternativa'      => 'Mi dispiace, non ci sono slot liberi in quel giorno. Vuoi provare un altro giorno?',
        'proposta_non_capita'      => 'Non ho capito. Vuoi prenotare questo slot oppure cambiare orario?',

        // Conferma
        'riepilogo_prenotazione'   => 'Riepilogo: prenotazione per {slot}. Come vuoi procedere?',
        'scegli_pagamento'         => 'Vuoi pagare online o di persona?',
        'conferma_non_capita'      => 'Scusa, non ho capito. Vuoi confermare, pagare online, o annullare?',
        'prenotazione_annullata'   => 'Prenotazione annullata. Nessun problema! Cosa vuoi fare?',
        'link_pagamento'           => 'Ecco il link per il pagamento. Una volta completato, la prenotazione sarà confermata!',
        'prenotazione_confermata'  => 'Prenotazione confermata per {slot}! ✅ Ti aspettiamo!',

        // Matchmaking
        'matchmaking_attesa'       => 'Sto cercando un avversario adatto a te. Ti avviso appena trovo qualcuno! 🔍',

        // Errore
        'errore_generico'          => 'Scusa, c\'è stato un problema. Riproviamo: quando vorresti giocare?',
    ];

    /**
     * Riformula un messaggio template in modo naturale.
     *
     * Usa l'AI per variare il tono mantenendo il significato.
     * Se l'AI fallisce, usa il template di fallback.
     */
    public function rephrase(string $templateId, string $persona, array $vars = []): string
    {
        $fallback = $this->renderTemplate($templateId, $vars);

        try {
            $prompt = $this->buildRephrasePrompt($persona, $fallback, $templateId);
            $reply  = $this->gemini->generate($prompt);
            $clean  = $this->cleanAiReply($reply);

            return !empty($clean) ? $clean : $fallback;
        } catch (\Throwable $e) {
            Log::warning('TextGenerator: AI rephrase fallback', [
                'template' => $templateId,
                'error'    => $e->getMessage(),
            ]);

            return $fallback;
        }
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
        $text = self::TEMPLATES[$templateId] ?? 'Mi scusi, qualcosa è andato storto.';

        foreach ($vars as $key => $value) {
            if (is_string($value) || is_numeric($value)) {
                $text = str_replace("{{$key}}", (string) $value, $text);
            }
        }

        return $text;
    }

    private function buildRephrasePrompt(string $persona, string $text, string $templateId): string
    {
        return <<<PROMPT
Sei {$persona}, assistente virtuale del circolo Le Cercle Tennis Club.
Tono: amichevole, diretto, sportivo. Parli in italiano.
Massimo 3 righe. No emoji eccessive (max 1).

Riformula questo messaggio mantenendo ESATTAMENTE lo stesso significato e le stesse informazioni.
Non aggiungere domande extra. Non cambiare il senso.

Messaggio originale: "{$text}"

Rispondi SOLO con il testo riformulato, senza virgolette né spiegazioni.
PROMPT;
    }

    private function cleanAiReply(string $reply): string
    {
        $clean = trim($reply);
        // Rimuovi virgolette esterne
        $clean = trim($clean, '"\'');
        // Rimuovi eventuali prefissi tipo "Ecco:" o "Risposta:"
        $clean = preg_replace('/^(ecco|risposta|riformulazione)\s*:\s*/i', '', $clean);

        return $clean;
    }
}
