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
     * @return array{date: string, time: string, friendly: string}|null
     */
    public function parseDateTime(string $input): ?array
    {
        $today = now()->format('Y-m-d');
        $dayOfWeek = now()->locale('it')->dayName;

        $prompt = <<<PROMPT
Sei un parser di date. Oggi è {$dayOfWeek} {$today}.

L'utente ha scritto: "{$input}"

Rispondi SOLO con un JSON valido (senza markdown, senza spiegazioni):
{
  "date": "YYYY-MM-DD",
  "time": "HH:MM",
  "friendly": "descrizione leggibile in italiano (es. 'sabato 28 marzo alle 18:00')"
}

Regole:
- Se l'utente dice "domani", calcola la data corretta.
- Se dice un giorno della settimana (es. "sabato"), usa il prossimo sabato.
- Se non specifica l'ora, usa null.
- Se non riesci a interpretare, rispondi: {"error": true}
PROMPT;

        try {
            $reply  = $this->gemini->generate($prompt);
            $clean  = preg_replace('/```json\s*|\s*```/', '', trim($reply));
            $parsed = json_decode($clean, true);

            if (json_last_error() !== JSON_ERROR_NONE || !empty($parsed['error'])) {
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

            // Validazione ora se presente
            if (!empty($parsed['time'])) {
                if (!preg_match('/^\d{2}:\d{2}$/', $parsed['time'])) {
                    return null;
                }
            }

            return [
                'date'     => $parsed['date'],
                'time'     => $parsed['time'] ?? null,
                'friendly' => $parsed['friendly'] ?? $parsed['date'],
            ];
        } catch (\Throwable $e) {
            Log::warning('TextGenerator: date parse failed', [
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
