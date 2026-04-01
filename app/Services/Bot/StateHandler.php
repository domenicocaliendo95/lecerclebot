<?php

namespace App\Services\Bot;

use App\Models\Booking;
use App\Models\BotSession;
use App\Models\PricingRule;
use App\Models\User;
use App\Services\CalendarService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Macchina a stati deterministica.
 *
 * REGOLA D'ORO: la logica di transizione è TUTTA qui.
 * L'AI (Gemini) viene invocata SOLO per generare il testo della risposta
 * e interpretare input ambigui (es. date in linguaggio naturale).
 *
 * Ogni metodo handle* restituisce un BotResponse.
 */
class StateHandler
{
    public function __construct(
        private readonly CalendarService $calendar,
        private readonly TextGenerator   $textGenerator,
    ) {}

    /**
     * Punto di ingresso: smista al metodo corretto in base allo stato.
     */
    public function handle(BotSession $session, string $input, ?User $user): BotResponse
    {
        $state      = BotState::from($session->state);
        $normalized = mb_strtolower(trim($input));

        // ── Parole chiave globali (solo utenti non in onboarding) ──────────
        if (!$state->isOnboarding()) {
            if ($this->isMenuKeyword($normalized)) {
                return BotResponse::make(
                    $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
                    BotState::MENU,
                    ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
                );
            }

            if ($user !== null && $this->isPrenotazioniKeyword($normalized)) {
                return $this->handleMostraPrenotazioni($session, $user);
            }

            if ($user !== null && $this->isProfiloKeyword($normalized)) {
                return BotResponse::make(
                    $this->textGenerator->rephrase('modifica_profilo_scelta', $session->persona()),
                    BotState::MODIFICA_PROFILO,
                    ['Stato FIT', 'Livello gioco', 'Fascia oraria'],
                );
            }
        }

        // ── "Indietro" durante l'onboarding ────────────────────────────────
        if ($state->isOnboarding() && $state !== BotState::ONBOARD_NOME && $this->isIndietroKeyword($normalized)) {
            return $this->handleIndietroOnboarding($session, $state);
        }

        return match ($state) {
            BotState::NEW                => $this->handleNew($session, $input),
            BotState::ONBOARD_NOME       => $this->handleOnboardNome($session, $input),
            BotState::ONBOARD_FIT        => $this->handleOnboardFit($session, $input),
            BotState::ONBOARD_CLASSIFICA => $this->handleOnboardClassifica($session, $input),
            BotState::ONBOARD_LIVELLO    => $this->handleOnboardLivello($session, $input),
            BotState::ONBOARD_ETA        => $this->handleOnboardEta($session, $input),
            BotState::ONBOARD_SLOT_PREF  => $this->handleOnboardSlotPref($session, $input),
            BotState::ONBOARD_COMPLETO   => $this->handleOnboardCompleto($session, $input),
            BotState::MENU               => $this->handleMenu($session, $input, $user),
            BotState::SCEGLI_QUANDO      => $this->handleScegliQuando($session, $input),
            BotState::SCEGLI_DURATA      => $this->handleScegliDurata($session, $input),
            BotState::VERIFICA_SLOT      => $this->handleVerificaSlot($session, $input),
            BotState::PROPONI_SLOT       => $this->handleProponiSlot($session, $input),
            BotState::CONFERMA           => $this->handleConferma($session, $input),
            BotState::PAGAMENTO          => $this->handlePagamento($session, $input),
            BotState::CONFERMATO              => $this->handleConfermato($session, $input),
            BotState::ATTESA_MATCH            => $this->handleAttesaMatch($session, $input),
            BotState::RISPOSTA_MATCH          => $this->handleRispostaMatch($session, $input),
            BotState::GESTIONE_PRENOTAZIONI   => $this->handleSelezionaPrenotazione($session, $input, $user),
            BotState::AZIONE_PRENOTAZIONE     => $this->handleAzionePrenotazione($session, $input),
            BotState::MODIFICA_PROFILO        => $this->handleModificaProfilo($session, $input, $user),
            BotState::MODIFICA_RISPOSTA       => $this->handleModificaRisposta($session, $input),
            BotState::INSERISCI_RISULTATO     => $this->handleInserisciRisultato($session, $input),
            BotState::FEEDBACK                => $this->handleFeedback($session, $input),
        };
    }

    /* ═══════════════════════════════════════════════════════════════
     *  ONBOARDING — Raccolta dati deterministici
     * ═══════════════════════════════════════════════════════════════ */

    private function handleNew(BotSession $session, string $input): BotResponse
    {
        // Lo stato NEW invia il saluto iniziale (già fatto in BotOrchestrator).
        // Se siamo qui, l'utente ha risposto al saluto → è il nome.
        return $this->handleOnboardNome($session, $input);
    }

    private function handleOnboardNome(BotSession $session, string $input): BotResponse
    {
        $name = $this->sanitizeName($input);

        if (empty($name)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('nome_non_valido', $session->persona()),
                BotState::ONBOARD_NOME,
            );
        }

        $session->mergeProfile(['name' => $name]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_fit', $session->persona(), ['name' => $name]),
            BotState::ONBOARD_FIT,
            ["Sì, sono tesserato", "Non sono tesserato"],
        );
    }

    private function handleOnboardFit(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // Controllo negativo PRIMA per evitare falsi positivi su "non sono tesserato"
        $isNotFit = $this->matchesNo($normalized)
            || str_contains($normalized, 'non sono')
            || str_contains($normalized, 'non ho')
            || str_contains($normalized, 'senza tessera')
            || str_contains($normalized, 'non tesserato');

        $isFit = !$isNotFit && ($this->matchesYes($normalized) || str_contains($normalized, 'tesserato'));

        if (!$isFit && !$isNotFit) {
            return BotResponse::make(
                $this->textGenerator->rephrase('fit_non_capito', $session->persona()),
                BotState::ONBOARD_FIT,
                ["Sì, sono tesserato", "Non sono tesserato"],
            );
        }

        $session->mergeProfile(['is_fit' => $isFit]);

        if ($isFit) {
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_classifica', $session->persona()),
                BotState::ONBOARD_CLASSIFICA,
            );
        }

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_livello', $session->persona()),
            BotState::ONBOARD_LIVELLO,
            ['Neofita', 'Dilettante', 'Avanzato'],
        );
    }

    private function handleOnboardClassifica(BotSession $session, string $input): BotResponse
    {
        $rating = $this->parseClassificaFit($input);

        if ($rating === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('classifica_non_valida', $session->persona()),
                BotState::ONBOARD_CLASSIFICA,
            );
        }

        $session->mergeProfile(['fit_rating' => $rating]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_eta', $session->persona()),
            BotState::ONBOARD_ETA,
        );
    }

    private function handleOnboardLivello(BotSession $session, string $input): BotResponse
    {
        $level = $this->parseLivello($input);

        if ($level === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('livello_non_valido', $session->persona()),
                BotState::ONBOARD_LIVELLO,
                ['Neofita', 'Dilettante', 'Avanzato'],
            );
        }

        $session->mergeProfile(['self_level' => $level]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_eta', $session->persona()),
            BotState::ONBOARD_ETA,
        );
    }

    private function handleOnboardEta(BotSession $session, string $input): BotResponse
    {
        $age = $this->parseAge($input);

        if ($age === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('eta_non_valida', $session->persona()),
                BotState::ONBOARD_ETA,
            );
        }

        $session->mergeProfile(['age' => $age]);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_fascia_oraria', $session->persona()),
            BotState::ONBOARD_SLOT_PREF,
            ['Mattina', 'Pomeriggio', 'Sera'],
        );
    }

    private function handleOnboardSlotPref(BotSession $session, string $input): BotResponse
    {
        $slot = $this->parseFasciaOraria($input);

        if ($slot === null) {
            return BotResponse::make(
                $this->textGenerator->rephrase('fascia_non_valida', $session->persona()),
                BotState::ONBOARD_SLOT_PREF,
                ['Mattina', 'Pomeriggio', 'Sera'],
            );
        }

        $session->mergeProfile(['slot' => $slot]);

        $profile = $session->profile();

        return BotResponse::make(
            $this->textGenerator->rephrase('registrazione_completa', $session->persona(), [
                'name' => $profile['name'] ?? 'Giocatore',
            ]),
            BotState::ONBOARD_COMPLETO,
            ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
        )->withProfileToSave($profile);
    }

    private function handleOnboardCompleto(BotSession $session, string $input): BotResponse
    {
        // L'utente ha appena completato la registrazione e sceglie un'azione.
        // Reindirizziamo al menu.
        return $this->handleMenuChoice($session, $input);
    }

    /* ═══════════════════════════════════════════════════════════════
     *  MENU — Scelta azione
     * ═══════════════════════════════════════════════════════════════ */

    private function handleMenu(BotSession $session, string $input, ?User $user): BotResponse
    {
        return $this->handleMenuChoice($session, $input);
    }

    private function handleMenuChoice(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // ⚠️ "Sparapalline" PRIMA di "trova" — evita che "trovare sparapalline" attivi matchmaking
        if (str_contains($normalized, 'sparapalline') || str_contains($normalized, 'spara palline')
            || str_contains($normalized, 'macchina') || str_contains($normalized, 'da solo')
            || str_contains($normalized, 'allenamento') || str_contains($normalized, 'allenarmi')) {
            $session->mergeData(['booking_type' => 'sparapalline']);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando_sparapalline', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if (str_contains($normalized, 'avversario') && str_contains($normalized, 'già')
            || str_contains($normalized, 'prenota campo') || str_contains($normalized, 'prenota')
            || str_contains($normalized, 'ho un compagno') || str_contains($normalized, 'con un amico')) {
            $session->mergeData(['booking_type' => 'con_avversario']);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if (str_contains($normalized, 'trovami') || str_contains($normalized, 'trova')
            || str_contains($normalized, 'cerca') || str_contains($normalized, 'matchmaking')
            || str_contains($normalized, 'avversario')) {
            $session->mergeData(['booking_type' => 'matchmaking']);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando_match', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        // Input non riconosciuto: riproponi il menu
        return BotResponse::make(
            $this->textGenerator->rephrase('menu_non_capito', $session->persona()),
            BotState::MENU,
            ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  PRENOTAZIONE — Scelta data/ora e verifica
     * ═══════════════════════════════════════════════════════════════ */

    private function handleScegliQuando(BotSession $session, string $input): BotResponse
    {
        // Parser locale deterministico + fallback AI
        $parsed = $this->textGenerator->parseDateTime($input);

        if ($parsed === null) {
            Log::info('Date parse failed for input', ['input' => $input, 'state' => 'SCEGLI_QUANDO']);

            return BotResponse::make(
                $this->textGenerator->rephrase('data_non_capita', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        Log::info('Date parsed successfully', ['input' => $input, 'result' => $parsed]);

        $session->mergeData([
            'requested_date'     => $parsed['date'],
            'requested_time'     => $parsed['time'],
            'requested_raw'      => $input,
            'requested_friendly' => $parsed['friendly'],
        ]);

        // Controlla che la data non sia nel passato
        $requestedDt = \Carbon\Carbon::parse(
            $parsed['date'] . ' ' . ($parsed['time'] ?? '23:59'),
            'Europe/Rome'
        );

        if ($requestedDt->isPast()) {
            return BotResponse::make(
                $this->textGenerator->rephrase('data_nel_passato', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        $durations = PricingRule::availableDurations();
        $durationButtons = array_map(
            fn(int $m) => PricingRule::durationLabel($m),
            $durations
        );

        // Calcola tariffe per ogni durata disponibile
        $startTime = \Carbon\Carbon::parse(
            $session->getData('requested_date') . ' ' . ($session->getData('requested_time') ?? '08:00'),
            'Europe/Rome'
        );
        $tariffLines = [];
        foreach ($durations as $min) {
            $price = PricingRule::getPriceForSlot($startTime, $min);
            $tariffLines[] = '• ' . PricingRule::durationLabel($min) . ' → €' . number_format($price, 0);
        }
        $tariffe = implode("\n", $tariffLines);

        return BotResponse::make(
            $this->textGenerator->rephrase('chiedi_durata', $session->persona(), [
                'tariffe' => $tariffe,
            ]),
            BotState::SCEGLI_DURATA,
            $durationButtons,
        );
    }

    private function handleScegliDurata(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));
        $available  = PricingRule::availableDurations();

        $parsed = null;
        if (preg_match('/\b1[,.]5\b|\bun\'ora e mezzo\b|\bora e mezza\b|\bun ora e mezzo\b/', $normalized)) {
            $parsed = 90;
        } elseif (preg_match('/\b2\s*ore\b|\bdue ore\b/', $normalized)) {
            $parsed = 120;
        } elseif (preg_match('/\b3\s*ore\b|\btre ore\b/', $normalized)) {
            $parsed = 180;
        } elseif (preg_match('/\b1\s*ora\b|\bun\'ora\b|\bun ora\b|\b1h\b/', $normalized)) {
            $parsed = 60;
        }

        $durationButtons = array_map(
            fn(int $m) => PricingRule::durationLabel($m),
            $available
        );

        // Rifiuta durate non configurate
        if ($parsed === null || !in_array($parsed, $available, true)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('durata_non_capita', $session->persona()),
                BotState::SCEGLI_DURATA,
                $durationButtons,
            );
        }

        $session->mergeData(['requested_duration_minutes' => $parsed]);

        return BotResponse::make(
            $this->textGenerator->rephrase('verifico_disponibilita', $session->persona()),
            BotState::VERIFICA_SLOT,
        )->withCalendarCheck(true);
    }

    private function handleVerificaSlot(BotSession $session, string $input): BotResponse
    {
        // Questo stato viene raggiunto dall'orchestrator dopo il check calendar.
        // I dati di disponibilità sono in session->data['calendar_result'].
        $calendarResult = $session->getData('calendar_result');

        if ($calendarResult === null) {
            Log::error('VERIFICA_SLOT senza calendar_result', ['session' => $session->id]);

            return BotResponse::make(
                $this->textGenerator->rephrase('errore_generico', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if ($calendarResult['available']) {
            $friendly      = $session->getData('requested_friendly') ?? 'l\'orario richiesto';
            $duration      = $session->getData('requested_duration_minutes') ?? 60;
            $price         = \App\Models\PricingRule::getPriceForSlot(
                \Carbon\Carbon::parse($session->getData('requested_date') . ' ' . ($session->getData('requested_time') ?? '08:00'), 'Europe/Rome'),
                $duration,
            );
            $durationLabel = \App\Models\PricingRule::durationLabel($duration);

            return BotResponse::make(
                $this->textGenerator->rephrase('slot_disponibile', $session->persona(), [
                    'slot'     => $friendly,
                    'duration' => $durationLabel,
                    'price'    => number_format($price, 0),
                ]),
                BotState::PROPONI_SLOT,
                ['Sì, prenota', 'No, cambia orario'],
            );
        }

        // Non disponibile: mostra alternative
        $alternatives = $calendarResult['alternatives'] ?? [];

        if (empty($alternatives)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('nessuna_alternativa', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        $altLabels = array_map(
            fn($a) => $a['label'] ?? "{$a['time']} (€{$a['price']})",
            array_slice($alternatives, 0, 3)  // Max 3 pulsanti WhatsApp
        );

        $session->mergeData(['alternatives' => $alternatives]);

        return BotResponse::make(
            $this->textGenerator->rephrase('slot_non_disponibile', $session->persona(), [
                'alternatives' => $altLabels,
            ]),
            BotState::PROPONI_SLOT,
            $altLabels,
        );
    }

    private function handleProponiSlot(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // "Sì, prenota" → conferma
        if ($this->matchesYes($normalized) || str_contains($normalized, 'prenota')) {
            $friendly    = $session->getData('requested_friendly') ?? 'l\'orario selezionato';
            $bookingType = $session->getData('booking_type') ?? 'con_avversario';

            $buttons = $bookingType === 'matchmaking'
                ? ['Cerca avversario', 'Annulla']
                : ['Paga online', 'Pago di persona', 'Annulla'];

            $duration      = $session->getData('requested_duration_minutes') ?? 60;
            $price         = \App\Models\PricingRule::getPriceForSlot(
                \Carbon\Carbon::parse(
                    ($session->getData('requested_date') ?? now()->format('Y-m-d')) . ' ' . ($session->getData('requested_time') ?? '08:00'),
                    'Europe/Rome'
                ),
                $duration,
            );
            $durationLabel = \App\Models\PricingRule::durationLabel($duration);

            return BotResponse::make(
                $this->textGenerator->rephrase('riepilogo_prenotazione', $session->persona(), [
                    'slot'         => $friendly,
                    'duration'     => $durationLabel,
                    'price'        => number_format($price, 0),
                    'booking_type' => $bookingType,
                ]),
                BotState::CONFERMA,
                $buttons,
            );
        }

        // Scelta alternativa
        $alternatives = $session->getData('alternatives') ?? [];
        foreach ($alternatives as $alt) {
            $label = mb_strtolower($alt['label'] ?? $alt['time'] ?? '');
            if (str_contains($normalized, $label) || str_contains($normalized, $alt['time'] ?? '---')) {
                $session->mergeData([
                    'requested_date'     => $alt['date'] ?? $session->getData('requested_date'),
                    'requested_time'     => $alt['time'],
                    'requested_friendly' => $alt['label'] ?? $alt['time'],
                ]);

                $friendly = $alt['label'] ?? $alt['time'];

                return BotResponse::make(
                    $this->textGenerator->rephrase('slot_disponibile', $session->persona(), [
                        'slot' => $friendly,
                    ]),
                    BotState::PROPONI_SLOT,
                    ['Sì, prenota', 'No, cambia orario'],
                );
            }
        }

        // "No" o "cambia" → torna a scegli quando
        if ($this->matchesNo($normalized) || str_contains($normalized, 'cambia') || str_contains($normalized, 'altro')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        // Non capito
        return BotResponse::make(
            $this->textGenerator->rephrase('proposta_non_capita', $session->persona()),
            BotState::PROPONI_SLOT,
            ['Sì, prenota', 'No, cambia orario'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  CONFERMA & PAGAMENTO
     * ═══════════════════════════════════════════════════════════════ */

    private function handleConferma(BotSession $session, string $input): BotResponse
    {
        $normalized  = mb_strtolower(trim($input));
        $bookingType = $session->getData('booking_type') ?? 'con_avversario';

        // ── Matchmaking branch ──────────────────────────────────────────
        if ($bookingType === 'matchmaking') {
            if (str_contains($normalized, 'annulla') || str_contains($normalized, 'indietro')) {
                return BotResponse::make(
                    $this->textGenerator->rephrase('prenotazione_annullata', $session->persona()),
                    BotState::MENU,
                    ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
                );
            }

            // "Cerca avversario" o qualsiasi conferma → avvia matchmaking
            return BotResponse::make(
                $this->textGenerator->rephrase('cerca_avversario', $session->persona(), [
                    'slot' => $session->getData('requested_friendly') ?? '',
                ]),
                BotState::ATTESA_MATCH,
            )->withMatchmakingSearch(true);
        }

        // ── Flusso normale (con_avversario / sparapalline) ───────────────
        if (str_contains($normalized, 'annulla') || str_contains($normalized, 'indietro')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_annullata', $session->persona()),
                BotState::MENU,
                ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
            );
        }

        if (str_contains($normalized, 'online') || str_contains($normalized, 'paga')) {
            $session->mergeData(['payment_method' => 'online']);

            return BotResponse::make(
                $this->textGenerator->rephrase('link_pagamento', $session->persona()),
                BotState::PAGAMENTO,
            )->withPaymentRequired(true);
        }

        if (str_contains($normalized, 'persona') || str_contains($normalized, 'di persona')) {
            $session->mergeData(['payment_method' => 'in_loco']);

            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_confermata', $session->persona(), [
                    'slot' => $session->getData('requested_friendly') ?? '',
                ]),
                BotState::CONFERMATO,
            )->withBookingToCreate(true);
        }

        // Conferma generica
        if ($this->matchesYes($normalized) || str_contains($normalized, 'conferma')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('scegli_pagamento', $session->persona()),
                BotState::CONFERMA,
                ['Paga online', 'Pago di persona', 'Annulla'],
            );
        }

        return BotResponse::make(
            $this->textGenerator->rephrase('conferma_non_capita', $session->persona()),
            BotState::CONFERMA,
            ['Paga online', 'Pago di persona', 'Annulla'],
        );
    }

    private function handlePagamento(BotSession $session, string $input): BotResponse
    {
        // In un flusso reale, qui verificheresti il callback di pagamento.
        // Per ora gestiamo la conferma manuale.
        return BotResponse::make(
            $this->textGenerator->rephrase('prenotazione_confermata', $session->persona(), [
                'slot' => $session->getData('requested_friendly') ?? '',
            ]),
            BotState::CONFERMATO,
        )->withBookingToCreate(true);
    }

    private function handleConfermato(BotSession $session, string $input): BotResponse
    {
        // Qualunque messaggio dopo la conferma riporta al menu
        return BotResponse::make(
            $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
            BotState::MENU,
            ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  INDIETRO ONBOARDING
     * ═══════════════════════════════════════════════════════════════ */

    private function handleIndietroOnboarding(BotSession $session, BotState $current): BotResponse
    {
        $persona = $session->persona();
        $profile = $session->profile();

        $prefix = $this->textGenerator->rephrase('indietro_onboarding', $persona) . "\n\n";

        return match ($current) {
            BotState::ONBOARD_FIT =>
                BotResponse::make(
                    $prefix . $this->textGenerator->rephrase('chiedi_nome_nuovo', $persona),
                    BotState::ONBOARD_NOME,
                ),

            BotState::ONBOARD_CLASSIFICA,
            BotState::ONBOARD_LIVELLO =>
                BotResponse::make(
                    $prefix . $this->textGenerator->rephrase('chiedi_fit', $persona, [
                        'name' => $profile['name'] ?? '',
                    ]),
                    BotState::ONBOARD_FIT,
                    ['Sì, sono tesserato', 'Non sono tesserato'],
                ),

            BotState::ONBOARD_ETA =>
                ($profile['is_fit'] ?? false)
                    ? BotResponse::make(
                        $prefix . $this->textGenerator->rephrase('chiedi_classifica', $persona),
                        BotState::ONBOARD_CLASSIFICA,
                    )
                    : BotResponse::make(
                        $prefix . $this->textGenerator->rephrase('chiedi_livello', $persona),
                        BotState::ONBOARD_LIVELLO,
                        ['Neofita', 'Dilettante', 'Avanzato'],
                    ),

            BotState::ONBOARD_SLOT_PREF =>
                BotResponse::make(
                    $prefix . $this->textGenerator->rephrase('chiedi_eta', $persona),
                    BotState::ONBOARD_ETA,
                ),

            default =>
                BotResponse::make(
                    $prefix . $this->textGenerator->rephrase('chiedi_nome_nuovo', $persona),
                    BotState::ONBOARD_NOME,
                ),
        };
    }

    /* ═══════════════════════════════════════════════════════════════
     *  MODIFICA PROFILO (utenti registrati)
     * ═══════════════════════════════════════════════════════════════ */

    private function handleModificaProfilo(BotSession $session, string $input, ?User $user): BotResponse
    {
        $normalized = mb_strtolower(trim($input));
        $persona    = $session->persona();

        if (str_contains($normalized, 'fit') || str_contains($normalized, 'tessera')) {
            $session->mergeData(['update_field' => 'fit']);
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_fit', $persona, [
                    'name' => $user?->name ?? '',
                ]),
                BotState::MODIFICA_RISPOSTA,
                ['Sì, sono tesserato', 'Non sono tesserato'],
            );
        }

        if (str_contains($normalized, 'livello') || str_contains($normalized, 'gioco')) {
            if ($user?->is_fit) {
                $session->mergeData(['update_field' => 'classifica']);
                return BotResponse::make(
                    $this->textGenerator->rephrase('chiedi_classifica', $persona),
                    BotState::MODIFICA_RISPOSTA,
                );
            }
            $session->mergeData(['update_field' => 'livello']);
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_livello', $persona),
                BotState::MODIFICA_RISPOSTA,
                ['Neofita', 'Dilettante', 'Avanzato'],
            );
        }

        if (str_contains($normalized, 'fascia') || str_contains($normalized, 'orario') || str_contains($normalized, 'slot')) {
            $session->mergeData(['update_field' => 'slot']);
            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_fascia_oraria', $persona),
                BotState::MODIFICA_RISPOSTA,
                ['Mattina', 'Pomeriggio', 'Sera'],
            );
        }

        // Non riconosciuto: riproponi le opzioni
        return BotResponse::make(
            $this->textGenerator->rephrase('modifica_profilo_scelta', $persona),
            BotState::MODIFICA_PROFILO,
            ['Stato FIT', 'Livello gioco', 'Fascia oraria'],
        );
    }

    private function handleModificaRisposta(BotSession $session, string $input): BotResponse
    {
        $updateField = $session->getData('update_field');
        $persona     = $session->persona();
        $profileUpdate = [];

        switch ($updateField) {
            case 'fit':
                $normalized = mb_strtolower(trim($input));
                $isNotFit = $this->matchesNo($normalized)
                    || str_contains($normalized, 'non sono')
                    || str_contains($normalized, 'non ho');
                $isFit = !$isNotFit && ($this->matchesYes($normalized) || str_contains($normalized, 'tesserato'));

                if (!$isFit && !$isNotFit) {
                    return BotResponse::make(
                        $this->textGenerator->rephrase('fit_non_capito', $persona),
                        BotState::MODIFICA_RISPOSTA,
                        ['Sì, sono tesserato', 'Non sono tesserato'],
                    );
                }
                $profileUpdate = ['is_fit' => $isFit, 'fit_rating' => null, 'self_level' => null];
                break;

            case 'classifica':
                $rating = $this->parseClassificaFit($input);
                if ($rating === null) {
                    return BotResponse::make(
                        $this->textGenerator->rephrase('classifica_non_valida', $persona),
                        BotState::MODIFICA_RISPOSTA,
                    );
                }
                $profileUpdate = ['fit_rating' => $rating];
                break;

            case 'livello':
                $level = $this->parseLivello($input);
                if ($level === null) {
                    return BotResponse::make(
                        $this->textGenerator->rephrase('livello_non_valido', $persona),
                        BotState::MODIFICA_RISPOSTA,
                        ['Neofita', 'Dilettante', 'Avanzato'],
                    );
                }
                $profileUpdate = ['self_level' => $level];
                break;

            case 'slot':
                $slot = $this->parseFasciaOraria($input);
                if ($slot === null) {
                    return BotResponse::make(
                        $this->textGenerator->rephrase('fascia_non_valida', $persona),
                        BotState::MODIFICA_RISPOSTA,
                        ['Mattina', 'Pomeriggio', 'Sera'],
                    );
                }
                $profileUpdate = ['slot' => $slot];
                break;

            default:
                return BotResponse::make(
                    $this->textGenerator->rephrase('menu_ritorno', $persona),
                    BotState::MENU,
                    ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
                );
        }

        // Merge con il profilo in sessione e salva nel DB
        $merged = array_merge($session->profile(), $profileUpdate);
        $session->mergeData(['update_field' => null]);

        return BotResponse::make(
            $this->textGenerator->rephrase('profilo_aggiornato', $persona),
            BotState::MENU,
            ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
        )->withProfileToSave($merged);
    }

    /* ═══════════════════════════════════════════════════════════════
     *  GESTIONE PRENOTAZIONI
     * ═══════════════════════════════════════════════════════════════ */

    /**
     * Chiamato dal keyword "prenotazioni": carica le prossime prenotazioni e le mostra.
     */
    private function handleMostraPrenotazioni(BotSession $session, User $user): BotResponse
    {
        $bookings = Booking::where('player1_id', $user->id)
            ->where('booking_date', '>=', now()->format('Y-m-d'))
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->take(3)
            ->get();

        if ($bookings->isEmpty()) {
            return BotResponse::make(
                $this->textGenerator->rephrase('nessuna_prenotazione', $session->persona()),
                BotState::MENU,
                ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
            );
        }

        $dayNames   = ['Dom', 'Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab'];
        $monthNames = ['', 'gen', 'feb', 'mar', 'apr', 'mag', 'giu', 'lug', 'ago', 'set', 'ott', 'nov', 'dic'];

        $bookingsList = $bookings->map(function ($b) use ($dayNames, $monthNames) {
            $date  = Carbon::parse($b->booking_date);
            $label = $dayNames[$date->dayOfWeek] . ' ' . $date->day . ' ' . $monthNames[$date->month]
                   . ' ' . mb_substr($b->start_time, 0, 5);

            return [
                'id'    => $b->id,
                'date'  => $b->booking_date instanceof \Carbon\Carbon
                    ? $b->booking_date->format('Y-m-d')
                    : (string) $b->booking_date,
                'time'  => mb_substr($b->start_time, 0, 5),
                'gcal_id' => $b->gcal_event_id,
                'label' => $label,
            ];
        })->toArray();

        $session->mergeData(['bookings_list' => $bookingsList]);

        $buttons = array_map(fn($b) => mb_substr($b['label'], 0, 20), $bookingsList);

        return BotResponse::make(
            $this->textGenerator->rephrase('scegli_prenotazione', $session->persona()),
            BotState::GESTIONE_PRENOTAZIONI,
            $buttons,
        );
    }

    /**
     * Utente è in GESTIONE_PRENOTAZIONI: ha selezionato una prenotazione dalla lista.
     */
    private function handleSelezionaPrenotazione(BotSession $session, string $input, ?User $user): BotResponse
    {
        $normalized   = mb_strtolower(trim($input));
        $bookingsList = $session->getData('bookings_list') ?? [];

        // Cerca la prenotazione selezionata per label
        $selected = null;
        foreach ($bookingsList as $b) {
            if (str_contains($normalized, mb_strtolower($b['label'])) ||
                str_contains(mb_strtolower($b['label']), $normalized)) {
                $selected = $b;
                break;
            }
        }

        // Fallback: se non trova per label, prova a matchare per orario (es. "17:00")
        if ($selected === null) {
            foreach ($bookingsList as $b) {
                if (str_contains($normalized, $b['time'])) {
                    $selected = $b;
                    break;
                }
            }
        }

        if ($selected === null) {
            // Non capito — ripropone la lista
            $buttons = array_map(fn($b) => mb_substr($b['label'], 0, 20), array_slice($bookingsList, 0, 3));

            return BotResponse::make(
                $this->textGenerator->rephrase('scegli_prenotazione', $session->persona()),
                BotState::GESTIONE_PRENOTAZIONI,
                $buttons,
            );
        }

        $session->mergeData(['selected_booking_id' => $selected['id']]);

        $slotFriendly = $selected['label'];

        return BotResponse::make(
            $this->textGenerator->rephrase('azione_prenotazione', $session->persona(), [
                'slot' => $slotFriendly,
            ]),
            BotState::AZIONE_PRENOTAZIONE,
            ['Modifica orario', 'Cancella', 'Torna al menu'],
        );
    }

    /**
     * Utente è in AZIONE_PRENOTAZIONE: sceglie cosa fare con la prenotazione selezionata.
     */
    private function handleAzionePrenotazione(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        if (str_contains($normalized, 'modifica') || str_contains($normalized, 'sposta') || str_contains($normalized, 'cambia')) {
            // Salva l'ID della prenotazione da modificare e vai a SCEGLI_QUANDO
            $bookingId = $session->getData('selected_booking_id');
            $session->mergeData(['editing_booking_id' => $bookingId]);

            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_modifica_quando', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if (str_contains($normalized, 'cancella') || str_contains($normalized, 'elimina') || str_contains($normalized, 'annulla')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_cancellata_ok', $session->persona()),
                BotState::MENU,
                ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
            )->withBookingToCancel(true);
        }

        // "Torna al menu" o qualsiasi altro input
        return BotResponse::make(
            $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
            BotState::MENU,
            ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  MATCHMAKING
     * ═══════════════════════════════════════════════════════════════ */

    private function handleAttesaMatch(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));

        // L'utente può annullare l'attesa
        if (str_contains($normalized, 'annulla') || $this->isMenuKeyword($normalized)) {
            return BotResponse::make(
                $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
                BotState::MENU,
                ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
            );
        }

        // Qualsiasi altro messaggio: ricorda che stiamo aspettando
        return BotResponse::make(
            $this->textGenerator->rephrase('matchmaking_attesa', $session->persona()),
            BotState::ATTESA_MATCH,
        );
    }

    private function handleRispostaMatch(BotSession $session, string $input): BotResponse
    {
        $normalized      = mb_strtolower(trim($input));
        $invitedSlot     = $session->getData('invited_slot') ?? '';
        $challengerName  = $session->getData('invited_by_name') ?? 'Un giocatore';

        if ($this->matchesYes($normalized) || str_contains($normalized, 'accett')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('match_accettato_opponent', $session->persona(), [
                    'slot' => $invitedSlot,
                ]),
                BotState::CONFERMATO,
            )->withMatchAccepted(true);
        }

        if ($this->matchesNo($normalized) || str_contains($normalized, 'rifiut') || str_contains($normalized, 'non posso')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('match_rifiutato_opponent', $session->persona()),
                BotState::MENU,
                ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
            )->withMatchRefused(true);
        }

        // Non capito: riproponi l'invito
        return BotResponse::make(
            $this->textGenerator->rephrase('invito_match', $session->persona(), [
                'opponent_name'   => '',
                'challenger_name' => $challengerName,
                'slot'            => $invitedSlot,
            ]),
            BotState::RISPOSTA_MATCH,
            ['Accetta', 'Rifiuta'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  RISULTATI & FEEDBACK
     * ═══════════════════════════════════════════════════════════════ */

    private function handleInserisciRisultato(BotSession $session, string $input): BotResponse
    {
        $normalized = mb_strtolower(trim($input));
        $slot       = $session->getData('result_slot') ?? 'la partita';

        // Partita non giocata
        if (
            str_contains($normalized, 'non giocata') ||
            str_contains($normalized, 'annullata') ||
            str_contains($normalized, 'non si è') ||
            str_contains($normalized, 'non si e')
        ) {
            $session->mergeData(['result_outcome' => 'no_show', 'result_score' => null]);

            return BotResponse::make(
                $this->textGenerator->rephrase('risultato_non_giocata', $session->persona()),
                BotState::MENU,
                ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
            )->withMatchResultToSave(true);
        }

        $won  = str_contains($normalized, 'vinto') || str_contains($normalized, 'ho vin');
        $lost = str_contains($normalized, 'perso') || str_contains($normalized, 'ho pers');

        if (!$won && !$lost) {
            return BotResponse::make(
                $this->textGenerator->rephrase('risultato_non_capito', $session->persona()),
                BotState::INSERISCI_RISULTATO,
                ['Ho vinto', 'Ho perso', 'Non giocata'],
            );
        }

        // Estrai punteggio se presente (es. "6-4 6-2", "7-6 3-6 6-4")
        preg_match_all('/\b(\d{1,2})[-\/](\d{1,2})\b/', $input, $m);
        $score = !empty($m[0]) ? implode(' ', $m[0]) : null;

        $session->mergeData([
            'result_outcome' => $won ? 'won' : 'lost',
            'result_score'   => $score,
        ]);

        return BotResponse::make(
            $this->textGenerator->rephrase('risultato_ricevuto', $session->persona()),
            BotState::MENU,
            ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
        )->withMatchResultToSave(true);
    }

    private function handleFeedback(BotSession $session, string $input): BotResponse
    {
        // Placeholder — implementazione futura
        return BotResponse::make(
            $this->textGenerator->rephrase('feedback_ricevuto', $session->persona()),
            BotState::MENU,
            ['Prenota campo', 'Trovami avversario', 'Sparapalline'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  PARSER — Validazione input deterministici
     * ═══════════════════════════════════════════════════════════════ */

    private function sanitizeName(string $input): ?string
    {
        // L'apostrofo deve essere preceduto da \ se la stringa è racchiusa tra ' '
        $clean = preg_replace('/[^\p{L}\s\'-]/u', '', trim($input));
        $clean = preg_replace('/\s+/', ' ', $clean);

        if (empty($clean) || mb_strlen($clean) < 2 || mb_strlen($clean) > 60) {
            return null;
        }

        return mb_convert_case($clean, MB_CASE_TITLE, 'UTF-8');
    }

    private function parseClassificaFit(string $input): ?string
    {
        $clean = mb_strtolower(trim($input));

        // NC (non classificato)
        if (in_array($clean, ['nc', 'non classificato', 'n.c.', 'n.c'])) {
            return 'NC';
        }

        // Classifiche FIT: 4.1, 4.2, 4.3, 4.4, 4.5, 4.6, 3.1, 3.2, ... 1.1
        if (preg_match('/^([1-4])[.,]([1-6])$/', $clean, $m)) {
            return "{$m[1]}.{$m[2]}";
        }

        // Categorie storiche: prima, seconda, terza, quarta
        $categories = [
            'prima'  => '1', 'seconda' => '2',
            'terza'  => '3', 'quarta'  => '4',
        ];

        foreach ($categories as $word => $cat) {
            if (str_contains($clean, $word)) {
                return "{$cat}.1"; // Default a .1 se non specificato
            }
        }

        return null;
    }

    private function parseLivello(string $input): ?string
    {
        $clean = mb_strtolower(trim($input));

        $map = [
            'neofita'     => 'neofita',
            'principiante' => 'neofita',
            'inizio'      => 'neofita',
            'dilettante'  => 'dilettante',
            'intermedio'  => 'dilettante',
            'medio'       => 'dilettante',
            'avanzato'    => 'avanzato',
            'esperto'     => 'avanzato',
            'buono'       => 'avanzato',
        ];

        foreach ($map as $keyword => $level) {
            if (str_contains($clean, $keyword)) {
                return $level;
            }
        }

        return null;
    }

    private function parseAge(string $input): ?int
    {
        // Estrai il primo numero trovato
        if (preg_match('/(\d+)/', trim($input), $m)) {
            $age = (int) $m[1];

            if ($age >= 5 && $age <= 99) {
                return $age;
            }
        }

        return null;
    }

    private function parseFasciaOraria(string $input): ?string
    {
        $clean = mb_strtolower(trim($input));

        $map = [
            'mattina'     => 'mattina',
            'mattino'     => 'mattina',
            'presto'      => 'mattina',
            'pomeriggio'  => 'pomeriggio',
            'primo pom'   => 'pomeriggio',
            'sera'        => 'sera',
            'serale'      => 'sera',
            'tardi'       => 'sera',
            'dopo cena'   => 'sera',
        ];

        foreach ($map as $keyword => $slot) {
            if (str_contains($clean, $keyword)) {
                return $slot;
            }
        }

        return null;
    }

    private function isMenuKeyword(string $input): bool
    {
        return in_array($input, ['menu', 'aiuto', 'help', 'home', 'start', 'ricomincia', '0', 'torna'], true)
            || str_contains($input, 'torna al menu');
    }

    private function isPrenotazioniKeyword(string $input): bool
    {
        return str_contains($input, 'prenotazion')
            || str_contains($input, 'mie prenotaz')
            || $input === 'booking';
    }

    private function isProfiloKeyword(string $input): bool
    {
        return str_contains($input, 'profilo')
            || str_contains($input, 'modifica profilo')
            || str_contains($input, 'aggiorna profilo')
            || $input === 'impostazioni';
    }

    private function isIndietroKeyword(string $input): bool
    {
        return in_array($input, ['indietro', 'back', 'torna', 'annulla', 'precedente'], true)
            || str_contains($input, 'torna indietro')
            || str_contains($input, 'vai indietro');
    }

    private function matchesYes(string $input): bool
    {
        return (bool) preg_match('/^(s[ìi]|ok|certo|va bene|perfetto|assolutamente|esatto)\b/i', $input);
    }

    private function matchesNo(string $input): bool
    {
        return (bool) preg_match('/^(no|nah|nope|non|neanche)\b/i', $input);
    }
}
