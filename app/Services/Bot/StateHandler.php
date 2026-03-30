<?php

namespace App\Services\Bot;

use App\Models\Booking;
use App\Models\BotSession;
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
                    ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
                );
            }

            if ($user !== null && $this->isPrenotazioniKeyword($normalized)) {
                return $this->handleMostraPrenotazioni($session, $user);
            }
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
            BotState::VERIFICA_SLOT      => $this->handleVerificaSlot($session, $input),
            BotState::PROPONI_SLOT       => $this->handleProponiSlot($session, $input),
            BotState::CONFERMA           => $this->handleConferma($session, $input),
            BotState::PAGAMENTO          => $this->handlePagamento($session, $input),
            BotState::CONFERMATO              => $this->handleConfermato($session, $input),
            BotState::ATTESA_MATCH            => $this->handleAttesaMatch($session, $input),
            BotState::GESTIONE_PRENOTAZIONI   => $this->handleSelezionaPrenotazione($session, $input, $user),
            BotState::AZIONE_PRENOTAZIONE     => $this->handleAzionePrenotazione($session, $input),
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
            ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
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

        if (str_contains($normalized, 'avversario') && str_contains($normalized, 'già')) {
            // "Ho già un avversario" → prenotazione diretta
            $session->mergeData(['booking_type' => 'con_avversario']);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if (str_contains($normalized, 'trovami') || str_contains($normalized, 'trova')) {
            $session->mergeData(['booking_type' => 'matchmaking']);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando_match', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        if (str_contains($normalized, 'sparapalline') || str_contains($normalized, 'noleggio')) {
            $session->mergeData(['booking_type' => 'sparapalline']);

            return BotResponse::make(
                $this->textGenerator->rephrase('chiedi_quando_sparapalline', $session->persona()),
                BotState::SCEGLI_QUANDO,
            );
        }

        // Input non riconosciuto: riproponi il menu
        return BotResponse::make(
            $this->textGenerator->rephrase('menu_non_capito', $session->persona()),
            BotState::MENU,
            ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
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
            'requested_date'     => $parsed['date'],      // Y-m-d
            'requested_time'     => $parsed['time'],      // H:i
            'requested_raw'      => $input,
            'requested_friendly' => $parsed['friendly'],  // "sabato 28 marzo alle 18:00"
        ]);

        // Transizione a VERIFICA_SLOT — la verifica calendar avviene nel prossimo step
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
            $friendly = $session->getData('requested_friendly') ?? 'l\'orario richiesto';

            return BotResponse::make(
                $this->textGenerator->rephrase('slot_disponibile', $session->persona(), [
                    'slot' => $friendly,
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
            $friendly = $session->getData('requested_friendly') ?? 'l\'orario selezionato';
            $bookingType = $session->getData('booking_type') ?? 'con_avversario';

            return BotResponse::make(
                $this->textGenerator->rephrase('riepilogo_prenotazione', $session->persona(), [
                    'slot'         => $friendly,
                    'booking_type' => $bookingType,
                ]),
                BotState::CONFERMA,
                ['Paga online', 'Pago di persona', 'Annulla'],
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
        $normalized = mb_strtolower(trim($input));

        if (str_contains($normalized, 'annulla') || str_contains($normalized, 'indietro')) {
            return BotResponse::make(
                $this->textGenerator->rephrase('prenotazione_annullata', $session->persona()),
                BotState::MENU,
                ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
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
            ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
        );
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
                ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
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
                ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
            )->withBookingToCancel(true);
        }

        // "Torna al menu" o qualsiasi altro input
        return BotResponse::make(
            $this->textGenerator->rephrase('menu_ritorno', $session->persona()),
            BotState::MENU,
            ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
        );
    }

    /* ═══════════════════════════════════════════════════════════════
     *  MATCHMAKING
     * ═══════════════════════════════════════════════════════════════ */

    private function handleAttesaMatch(BotSession $session, string $input): BotResponse
    {
        // Placeholder per il flusso matchmaking — fase 3
        return BotResponse::make(
            $this->textGenerator->rephrase('matchmaking_attesa', $session->persona()),
            BotState::ATTESA_MATCH,
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

    private function matchesYes(string $input): bool
    {
        return (bool) preg_match('/^(s[ìi]|ok|certo|va bene|perfetto|assolutamente|esatto)\b/i', $input);
    }

    private function matchesNo(string $input): bool
    {
        return (bool) preg_match('/^(no|nah|nope|non|neanche)\b/i', $input);
    }
}
