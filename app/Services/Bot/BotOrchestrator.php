<?php

namespace App\Services\Bot;

use App\Models\Booking;
use App\Models\BotFlowState;
use App\Models\BotSession;
use App\Models\BotSetting;
use App\Models\MatchInvitation;
use App\Models\MatchResult;
use App\Models\User;
use App\Services\EloService;
use App\Services\CalendarService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestratore del bot: coordina sessione, stato, side-effects e invio messaggi.
 *
 * Flusso:
 * 1. Recupera/crea sessione
 * 2. Delega allo StateHandler
 * 3. Esegue side-effects (calendar check, pagamento, creazione booking)
 * 4. Aggiorna la sessione
 * 5. Invia il messaggio via WhatsApp
 */
class BotOrchestrator
{
    public function __construct(
        private readonly StateHandler       $stateHandler,
        private readonly WhatsAppService    $whatsApp,
        private readonly CalendarService    $calendar,
        private readonly UserProfileService $profileService,
        private readonly TextGenerator      $textGenerator,
        private readonly EloService         $eloService,
        private readonly ActionExecutor     $actionExecutor,
    ) {}

    /**
     * Processa un messaggio in arrivo.
     */
    public function process(string $phone, string $input): void
    {
        DB::beginTransaction();

        try {
            $user    = User::where('phone', $phone)->first();
            $session = $this->resolveSession($phone, $user);

            // Se lo stato è NEW, invia il saluto e basta (primo contatto o sessione stuck)
            if ($session->state === BotState::NEW->value) {
                DB::commit();
                $this->sendGreeting($session, $phone, $user);
                return;
            }

            // ── Session timeout: se lo stato è fermo da troppo tempo, reset a MENU
            if ($this->isSessionStale($session)) {
                Log::info('Session timeout: resetting to MENU', [
                    'phone'      => $phone,
                    'state'      => $session->state,
                    'updated_at' => $session->updated_at?->toIso8601String(),
                ]);
                $session->update(['state' => BotState::MENU->value]);
                $session->refresh();
            }

            // Processa tramite la macchina a stati
            $response = $this->stateHandler->handle($session, $input, $user);

            // Valida la transizione di stato (supporta enum e custom string)
            $newStateValue = $this->resolveNextStateValue($session->state, $response);

            // Esegui side-effects
            $this->executeSideEffects($session, $response, $phone, $user);

            // Se c'era un calendar check, manda subito il messaggio di attesa, poi processa
            if ($response->needsCalendarCheck()) {
                // Invia "sto verificando..." prima del check (fuori dalla tx non serve aspettare)
                DB::commit();
                $this->sendResponse($phone, $response);

                DB::beginTransaction();
                $session->refresh();

                $calendarResult = $this->performCalendarCheck($session);
                $session->mergeData(['calendar_result' => $calendarResult]);
                $session->update(['state' => BotState::VERIFICA_SLOT->value]);

                // Ri-processa lo stato VERIFICA_SLOT con i risultati
                $response      = $this->stateHandler->handle($session, '', $user);
                $newStateValue = $this->resolveNextStateValue($session->state, $response);
            }

            // Aggiorna sessione
            $session->update(['state' => $newStateValue]);
            $session->appendHistory('user', $input);
            $session->appendHistory('model', $response->message);

            DB::commit();

            // Invia risposta (fuori dalla transazione per non bloccare su errori WhatsApp)
            $this->sendResponse($phone, $response);

        } catch (\Throwable $e) {
            DB::rollBack();

            Log::error('BotOrchestrator: fatal error', [
                'phone' => $phone,
                'input' => $input,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->sendFallbackError($phone);
        }
    }

    /**
     * Calcola il prossimo stato (come stringa) gestendo sia stati built-in che custom.
     *
     *  - Se sia source che target sono enum case → applica la validazione
     *    rigida di BotState::transitionTo() (allowedTransitions hardcoded)
     *  - Altrimenti (uno dei due è custom) → permetti la transizione purché
     *    il target esista (in enum o in bot_flow_states)
     *  - Se il target non esiste in nessun posto → resta sullo stato corrente
     */
    private function resolveNextStateValue(string $currentValue, BotResponse $response): string
    {
        $targetValue = $response->nextStateValue();

        $currentEnum = BotState::tryFrom($currentValue);
        $targetEnum  = BotState::tryFrom($targetValue);

        // Caso 1: entrambi built-in → validazione enum classica
        if ($currentEnum !== null && $targetEnum !== null) {
            return $currentEnum->transitionTo($targetEnum)->value;
        }

        // Caso 2: target è built-in (anche se source custom) → ok
        if ($targetEnum !== null) {
            return $targetEnum->value;
        }

        // Caso 3: target custom → ok solo se esiste in DB
        if (BotFlowState::find($targetValue) !== null) {
            return $targetValue;
        }

        // Caso 4: target sconosciuto → resta dove sei (silenzioso, come da regola enum)
        Log::warning('Unknown target state, staying on current', [
            'current' => $currentValue,
            'target'  => $targetValue,
        ]);
        return $currentValue;
    }

    /**
     * Controlla se la sessione è "stale" (ferma da troppo tempo).
     *
     * Il timeout è determinato (in ordine di priorità):
     *  1. timeout_minutes sul bot_flow_state specifico (se configurato)
     *  2. Default globale da bot_settings 'session_timeout_minutes'
     *
     * Se il timeout è 0 → lo stato non scade mai (es. onboarding).
     */
    private function isSessionStale(BotSession $session): bool
    {
        if (!$session->updated_at) {
            return false;
        }

        // Leggi il timeout per questo stato specifico
        $flowState = BotFlowState::getCached($session->state);
        $timeoutMinutes = $flowState?->timeout_minutes;

        // Fallback al default globale
        if ($timeoutMinutes === null) {
            $timeoutMinutes = BotSetting::get('session_timeout_minutes', 120);
        }

        // 0 = nessun timeout
        if ((int) $timeoutMinutes <= 0) {
            return false;
        }

        $minutesSinceUpdate = $session->updated_at->diffInMinutes(now());

        return $minutesSinceUpdate >= (int) $timeoutMinutes;
    }

    /* ───────── Sessione ───────── */

    private function resolveSession(string $phone, ?User $user): BotSession
    {
        $session = BotSession::where('phone', $phone)->first();

        if ($session) {
            return $session;
        }

        $persona    = BotPersona::pickRandom();
        $startState = $user ? BotState::MENU->value : BotState::NEW->value;

        return BotSession::create([
            'phone' => $phone,
            'state' => $startState,
            'data'  => [
                'persona' => $persona,
                'history' => [],
                'profile' => [],
            ],
        ]);
    }

    /* ───────── Saluto iniziale ───────── */

    private function sendGreeting(BotSession $session, string $phone, ?User $user): void
    {
        $persona = $session->persona();

        if ($user) {
            // Utente registrato → template da DB, fallback a BotPersona hardcoded
            $greeting = $this->textGenerator->rephrase('saluto_ritorno', $persona, [
                'name'    => $user->name,
                'persona' => $persona,
            ]);
            $buttons = $this->stateHandler->getButtonsPublic('MENU', ['Prenota campo', 'Trovami avversario', 'Sparapalline']);

            $session->update(['state' => BotState::MENU->value]);
            $this->whatsApp->sendButtons($phone, $greeting, $buttons);
        } else {
            // Nuovo utente → template da DB, fallback a BotPersona hardcoded
            $greeting = $this->textGenerator->rephrase('saluto_nuovo', $persona, [
                'persona' => $persona,
            ]);

            $session->update(['state' => BotState::ONBOARD_NOME->value]);
            $this->whatsApp->sendText($phone, $greeting);
        }

        $session->appendHistory('model', $greeting);
    }

    /* ───────── Side-effects ───────── */

    private function executeSideEffects(BotSession $session, BotResponse $response, string $phone, ?User $user): void
    {
        // Salvataggio profilo utente
        $profile = $response->profileToSave();
        if ($profile !== null) {
            $this->profileService->saveFromBot($phone, $profile);
        }

        // Cancellazione prenotazione
        if ($response->needsBookingCancellation()) {
            $this->cancelBooking($session);
        }

        // Creazione (o modifica) prenotazione
        if ($response->needsBookingCreation()) {
            $this->createBooking($session, $phone);
        }

        // Avvio ricerca matchmaking
        if ($response->needsMatchmakingSearch()) {
            $this->triggerMatchmaking($session, $phone);
        }

        // Avversario ha accettato la sfida
        if ($response->needsMatchAccepted()) {
            $this->confirmMatch($session, $phone);
        }

        // Avversario ha rifiutato la sfida
        if ($response->needsMatchRefused()) {
            $this->refuseMatch($session, $phone);
        }

        // Salvataggio risultato partita
        if ($response->needsMatchResultSave()) {
            $this->processMatchResult($session, $phone);
        }

        // Salvataggio feedback
        if ($response->needsFeedbackSave()) {
            $this->saveFeedback($session, $phone);
        }

        // Conferma link avversario (lato avversario taggato)
        if ($response->needsOpponentLinkConfirm()) {
            $this->confirmOpponentLink($session, $phone);
        }

        // Rifiuto link avversario (l'utente taggato dichiara che non è lui/lei)
        if ($response->needsOpponentLinkReject()) {
            $this->rejectOpponentLink($session, $phone);
        }
    }

    /* ───────── Calendar ───────── */

    private function performCalendarCheck(BotSession $session): array
    {
        $date     = $session->getData('requested_date');
        $time     = $session->getData('requested_time');
        $raw      = $session->getData('requested_raw');
        $duration = $session->getData('requested_duration_minutes') ?? 60;

        if (empty($date)) {
            return ['available' => false, 'alternatives' => [], 'error' => 'missing_date'];
        }

        try {
            $query = $time ? "{$date} {$time}" : ($raw ?? $date);
            $result = $this->calendar->checkUserRequest($query, $duration);

            return [
                'available'    => $result['available'] ?? false,
                'alternatives' => $result['alternatives'] ?? [],
            ];
        } catch (\Throwable $e) {
            Log::error('Calendar check failed', [
                'date'  => $date,
                'time'  => $time,
                'error' => $e->getMessage(),
            ]);

            return ['available' => false, 'alternatives' => [], 'error' => 'calendar_error'];
        }
    }

    /* ───────── Booking ───────── */

    private function cancelBooking(BotSession $session): void
    {
        $bookingId = $session->getData('selected_booking_id');
        if (!$bookingId) {
            return;
        }

        try {
            $booking = Booking::find($bookingId);
            if (!$booking) {
                return;
            }

            if ($booking->gcal_event_id) {
                try {
                    $this->calendar->deleteEvent($booking->gcal_event_id);
                } catch (\Throwable $e) {
                    Log::warning('cancelBooking: could not delete calendar event', [
                        'gcal_id' => $booking->gcal_event_id,
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            $booking->update(['status' => 'cancelled']);
            $session->mergeData(['selected_booking_id' => null]);

            Log::info('Booking cancelled', ['booking_id' => $bookingId]);
        } catch (\Throwable $e) {
            Log::error('cancelBooking failed', ['booking_id' => $bookingId, 'error' => $e->getMessage()]);
        }
    }

    private function createBooking(BotSession $session, string $phone): void
    {
        try {
            $user = User::where('phone', $phone)->first();
            if (!$user) {
                Log::error('Cannot create booking: user not found', ['phone' => $phone]);
                return;
            }

            // Modalità modifica: cancella la vecchia prenotazione prima di crearne una nuova
            $editingBookingId = $session->getData('editing_booking_id');
            if ($editingBookingId) {
                $oldBooking = Booking::find($editingBookingId);
                if ($oldBooking) {
                    if ($oldBooking->gcal_event_id) {
                        try {
                            $this->calendar->deleteEvent($oldBooking->gcal_event_id);
                        } catch (\Throwable $e) {
                            Log::warning('createBooking: could not delete old calendar event', [
                                'gcal_id' => $oldBooking->gcal_event_id,
                            ]);
                        }
                    }
                    $oldBooking->update(['status' => 'cancelled']);
                }
                $session->mergeData(['editing_booking_id' => null, 'selected_booking_id' => null]);
            }

            $date           = $session->getData('requested_date');  // Y-m-d
            $time           = $session->getData('requested_time');  // H:i
            $bookingType    = $session->getData('booking_type') ?? 'con_avversario';
            $payment        = $session->getData('payment_method') ?? 'in_loco';
            $opponentUserId = $session->getData('opponent_user_id');
            $opponentName   = $session->getData('opponent_name');
            $opponentPhone  = $session->getData('opponent_phone');

            if (empty($date) || empty($time)) {
                Log::error('Cannot create booking: missing date/time', [
                    'phone' => $phone,
                    'date'  => $date,
                    'time'  => $time,
                ]);
                return;
            }

            // Costruisci datetime inizio e fine
            $durationMinutes = $session->getData('requested_duration_minutes') ?? 60;
            $startDateTime   = \Carbon\Carbon::parse("{$date} {$time}", 'Europe/Rome');
            $endDateTime     = $startDateTime->copy()->addMinutes($durationMinutes);

            // Etichette per il tipo di prenotazione
            $typeLabels = [
                'con_avversario' => 'Partita singolo',
                'matchmaking'    => 'Partita (matchmaking)',
                'sparapalline'   => 'Noleggio sparapalline',
            ];
            $typeLabel = $typeLabels[$bookingType] ?? 'Prenotazione campo';

            // Etichetta avversario per descrizione
            $opponentLabel = '';
            if ($opponentUserId && $opponentName) {
                $opponentLabel = "{$opponentName} (tesserato)";
            } elseif ($opponentName) {
                $opponentLabel = "{$opponentName} (esterno)";
            }

            // Summary include avversario se "con_avversario" e nome noto
            if ($bookingType === 'con_avversario' && $opponentName) {
                $summary = "Partita singolo - {$user->name} vs {$opponentName}";
            } else {
                $summary = "{$typeLabel} - {$user->name}";
            }

            $descLines = [
                "Giocatore: {$user->name}",
                "Telefono: {$phone}",
                "Tipo: {$typeLabel}",
                "Pagamento: {$payment}",
            ];
            if ($opponentLabel !== '') {
                $descLines[] = "Avversario: {$opponentLabel}";
            }
            $descLines[] = "Prenotato via: WhatsApp Bot";
            $description = implode("\n", $descLines);

            // Calcola il prezzo usando PricingRule
            $price = \App\Models\PricingRule::getPriceForSlot($startDateTime, $durationMinutes);

            // Crea evento su Google Calendar
            $gcalEvent = $this->calendar->createEvent(
                summary:     $summary,
                description: $description,
                startTime:   $startDateTime,
                endTime:     $endDateTime,
            );

            // Salva prenotazione nel DB
            $booking = Booking::create([
                'player1_id'        => $user->id,
                'player2_id'        => $opponentUserId,
                'player2_name_text' => $opponentUserId ? null : $opponentName,
                'booking_date'      => $startDateTime->format('Y-m-d'),
                'start_time'        => $startDateTime->format('H:i:s'),
                'end_time'          => $endDateTime->format('H:i:s'),
                'price'             => $price,
                'is_peak'           => $startDateTime->hour >= 18,
                'status'            => 'confirmed',
                'gcal_event_id'     => $gcalEvent->getId(),
            ]);

            Log::info('Booking created on Google Calendar', [
                'booking_id'   => $booking->id,
                'user_id'      => $user->id,
                'user_name'    => $user->name,
                'opponent_id'  => $opponentUserId,
                'opponent_name'=> $opponentName,
                'date'         => $date,
                'time'         => $time,
                'type'         => $bookingType,
                'payment'      => $payment,
                'start'        => $startDateTime->toIso8601String(),
                'end'          => $endDateTime->toIso8601String(),
            ]);

            // Pulisci dati avversario dalla sessione del challenger
            $session->mergeData([
                'opponent_user_id'        => null,
                'opponent_name'           => null,
                'opponent_phone'          => null,
                'opponent_search_results' => null,
                'opponent_pending_confirm'=> null,
            ]);

            // Notifica all'avversario tesserato (se ha phone) per conferma bidirezionale
            if ($opponentUserId && $opponentPhone) {
                $this->notifyOpponentForConfirmation(
                    $booking,
                    $user,
                    $opponentUserId,
                    $opponentPhone,
                    $session->getData('requested_friendly') ?? "{$date} {$time}",
                );
            }

        } catch (\Throwable $e) {
            Log::error('Booking creation failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Invia un messaggio WhatsApp all'avversario taggato chiedendogli di confermare
     * (o rifiutare) di essere effettivamente l'avversario indicato.
     *
     * Setta lo stato dell'avversario a CONFERMA_INVITO_OPP.
     */
    private function notifyOpponentForConfirmation(
        Booking $booking,
        User $challenger,
        int $opponentUserId,
        string $opponentPhone,
        string $friendlySlot,
    ): void {
        try {
            $oppData = [
                'opp_invite_booking_id'      => $booking->id,
                'opp_invite_challenger_id'   => $challenger->id,
                'opp_invite_challenger_name' => $challenger->name,
                'opp_invite_slot'            => $friendlySlot,
            ];

            $oppSession = BotSession::where('phone', $opponentPhone)->first();
            if ($oppSession) {
                $oppSession->update(['state' => BotState::CONFERMA_INVITO_OPP->value]);
                $oppSession->mergeData($oppData);
            } else {
                BotSession::create([
                    'phone' => $opponentPhone,
                    'state' => BotState::CONFERMA_INVITO_OPP->value,
                    'data'  => array_merge([
                        'persona' => BotPersona::pickRandom(),
                        'history' => [],
                        'profile' => [],
                    ], $oppData),
                ]);
            }

            $msg = $this->textGenerator->rephrase('opp_invite_richiesta', 'Bot', [
                'challenger_name' => $challenger->name,
                'slot'            => $friendlySlot,
            ]);

            $this->whatsApp->sendButtons($opponentPhone, $msg, ['Sì, confermo', 'No, sbagliato']);

            Log::info('Opponent confirmation invite sent', [
                'booking_id'   => $booking->id,
                'challenger'   => $challenger->id,
                'opponent_id'  => $opponentUserId,
                'phone'        => $opponentPhone,
            ]);
        } catch (\Throwable $e) {
            Log::warning('notifyOpponentForConfirmation failed', [
                'booking_id' => $booking->id,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    /**
     * L'avversario taggato ha CONFERMATO di essere lui.
     * Setta player2_confirmed_at = now() e notifica il challenger.
     */
    private function confirmOpponentLink(BotSession $session, string $phone): void
    {
        try {
            $bookingId   = $session->getData('opp_invite_booking_id');
            $challengerId = $session->getData('opp_invite_challenger_id');

            if (!$bookingId) {
                return;
            }

            $booking = Booking::find($bookingId);
            if (!$booking) {
                return;
            }

            $booking->update(['player2_confirmed_at' => now()]);

            // Notifica al challenger
            $challenger = User::find($challengerId);
            if ($challenger && $challenger->phone) {
                $opponent = User::where('phone', $phone)->first();
                $msg = $this->textGenerator->rephrase('opp_invite_notify_challenger_ok', 'Bot', [
                    'opponent_name' => $opponent?->name ?? 'Il tuo avversario',
                    'slot'          => $session->getData('opp_invite_slot') ?? '',
                ]);
                $this->whatsApp->sendText($challenger->phone, $msg);
            }

            // Pulisci sessione avversario
            $session->mergeData([
                'opp_invite_booking_id'      => null,
                'opp_invite_challenger_id'   => null,
                'opp_invite_challenger_name' => null,
                'opp_invite_slot'            => null,
            ]);

            Log::info('Opponent link confirmed', [
                'booking_id' => $bookingId,
                'opponent'   => $phone,
            ]);
        } catch (\Throwable $e) {
            Log::error('confirmOpponentLink failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    /**
     * L'avversario taggato ha NEGATO di essere lui.
     * Sbianca player2_id, salva il nome come testo libero, notifica il challenger.
     */
    private function rejectOpponentLink(BotSession $session, string $phone): void
    {
        try {
            $bookingId    = $session->getData('opp_invite_booking_id');
            $challengerId = $session->getData('opp_invite_challenger_id');

            if (!$bookingId) {
                return;
            }

            $booking = Booking::find($bookingId);
            if (!$booking) {
                return;
            }

            // Sbianca player2_id (non sarà tracciato per ELO).
            // Mantieni il nome come testo libero per traccia storica.
            $opponent = User::where('phone', $phone)->first();
            $booking->update([
                'player2_id'           => null,
                'player2_name_text'    => $opponent?->name,
                'player2_confirmed_at' => null,
            ]);

            // Notifica al challenger
            $challenger = User::find($challengerId);
            if ($challenger && $challenger->phone) {
                $msg = $this->textGenerator->rephrase('opp_invite_notify_challenger_ko', 'Bot', [
                    'opponent_name' => $opponent?->name ?? 'L\'avversario indicato',
                    'slot'          => $session->getData('opp_invite_slot') ?? '',
                ]);
                $this->whatsApp->sendText($challenger->phone, $msg);
            }

            // Pulisci sessione avversario
            $session->mergeData([
                'opp_invite_booking_id'      => null,
                'opp_invite_challenger_id'   => null,
                'opp_invite_challenger_name' => null,
                'opp_invite_slot'            => null,
            ]);

            Log::info('Opponent link rejected', [
                'booking_id' => $bookingId,
                'opponent'   => $phone,
            ]);
        } catch (\Throwable $e) {
            Log::error('rejectOpponentLink failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    /* ───────── Matchmaking ───────── */

    /**
     * Cerca un avversario a 3 livelli di ELO:
     *  Tier 1 — ±50  (livello identico)
     *  Tier 2 — ±150 (livello simile, lieve disparità)
     *  Tier 3 — qualsiasi ELO (disparità significativa)
     *
     * Se nessuno trovato → salva il timestamp e aspetta fino a 30 min
     * (il comando bot:retry-matchmaking riprova ogni 5 min).
     */
    private function triggerMatchmaking(BotSession $session, string $phone): void
    {
        try {
            $challenger = User::where('phone', $phone)->first();
            if (!$challenger) {
                Log::error('triggerMatchmaking: challenger not found', ['phone' => $phone]);
                return;
            }

            $challengerElo = $challenger->elo_rating ?? 1200;
            $date          = $session->getData('requested_date');
            $time          = $session->getData('requested_time');
            $friendly      = $session->getData('requested_friendly') ?? "{$date} {$time}";

            if (empty($date) || empty($time)) {
                Log::error('triggerMatchmaking: missing date/time', ['phone' => $phone]);
                return;
            }

            [$opponent, $eloGap] = $this->findOpponentTiered($challenger->id, $challengerElo);

            if (!$opponent) {
                // Nessuno trovato ora: avvia attesa asincrona (max 30 min)
                $session->mergeData([
                    'matchmaking_started_at'     => now()->toIso8601String(),
                    'matchmaking_pending_search' => true,
                ]);
                $this->whatsApp->sendText(
                    $phone,
                    $this->textGenerator->rephrase('matchmaking_attesa', $session->persona()),
                );
                Log::info('triggerMatchmaking: no opponent found, waiting', [
                    'challenger_id' => $challenger->id,
                    'elo'           => $challengerElo,
                ]);
                return;
            }

            $this->sendMatchInvite($session, $phone, $challenger, $opponent, $eloGap, $date, $time, $friendly);

        } catch (\Throwable $e) {
            Log::error('triggerMatchmaking failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Ricerca a 3 livelli ELO.
     * Restituisce [$opponent|null, $eloGap].
     */
    public function findOpponentTiered(int $excludeId, int $challengerElo): array
    {
        $tiers = [50, 150, PHP_INT_MAX];

        foreach ($tiers as $i => $radius) {
            $minPrev = $i > 0 ? $tiers[$i - 1] : 0;

            $query = User::where('id', '!=', $excludeId)
                ->whereNotNull('phone')
                ->inRandomOrder();

            if ($radius === PHP_INT_MAX) {
                // Tier 3: qualsiasi ELO, escludi già coperti dai tier precedenti
                $query->where(function ($q) use ($challengerElo, $minPrev) {
                    $q->where('elo_rating', '<', $challengerElo - $minPrev)
                      ->orWhere('elo_rating', '>', $challengerElo + $minPrev);
                });
            } else {
                $query->whereBetween('elo_rating', [
                    $challengerElo - $radius,
                    $challengerElo + $radius,
                ]);
                if ($minPrev > 0) {
                    // Escludi il range già cercato nel tier precedente
                    $query->where(function ($q) use ($challengerElo, $minPrev) {
                        $q->where('elo_rating', '<', $challengerElo - $minPrev)
                          ->orWhere('elo_rating', '>', $challengerElo + $minPrev);
                    });
                }
            }

            $opponent = $query->first();
            if ($opponent) {
                $gap = abs($opponent->elo_rating - $challengerElo);
                return [$opponent, $gap];
            }
        }

        return [null, 0];
    }

    /**
     * Crea Booking + Invitation, aggiorna sessioni, invia invito WhatsApp.
     * Usato sia da triggerMatchmaking che dal comando di retry.
     */
    public function sendMatchInvite(
        BotSession $session,
        string $challengerPhone,
        User $challenger,
        User $opponent,
        int $eloGap,
        string $date,
        string $time,
        string $friendly,
    ): void {
        $durationMinutes = $session->getData('requested_duration_minutes') ?? 60;
        $startDT = \Carbon\Carbon::parse("{$date} {$time}", 'Europe/Rome');
        $endDT   = $startDT->copy()->addMinutes($durationMinutes);
        $price   = \App\Models\PricingRule::getPriceForSlot($startDT, $durationMinutes);

        $booking = Booking::create([
            'player1_id'   => $challenger->id,
            'player2_id'   => $opponent->id,
            'booking_date' => $startDT->format('Y-m-d'),
            'start_time'   => $startDT->format('H:i:s'),
            'end_time'     => $endDT->format('H:i:s'),
            'price'        => $price,
            'is_peak'      => $startDT->hour >= 18,
            'status'       => 'pending_match',
        ]);

        MatchInvitation::create([
            'booking_id'  => $booking->id,
            'receiver_id' => $opponent->id,
            'status'      => 'pending',
        ]);

        // Aggiorna sessione challenger — pulisce i flag di ricerca pendente
        $session->mergeData([
            'pending_booking_id'         => $booking->id,
            'opponent_name'              => $opponent->name,
            'opponent_phone'             => $opponent->phone,
            'matchmaking_pending_search' => false,
            'matchmaking_started_at'     => null,
        ]);

        // Prepara o aggiorna sessione avversario
        $opponentData = [
            'invited_by_phone'   => $challengerPhone,
            'invited_by_name'    => $challenger->name,
            'invited_slot'       => $friendly,
            'invited_booking_id' => $booking->id,
        ];

        $opponentSession = BotSession::where('phone', $opponent->phone)->first();
        if ($opponentSession) {
            $opponentSession->update(['state' => BotState::RISPOSTA_MATCH->value]);
            $opponentSession->mergeData($opponentData);
        } else {
            BotSession::create([
                'phone' => $opponent->phone,
                'state' => BotState::RISPOSTA_MATCH->value,
                'data'  => array_merge([
                    'persona' => BotPersona::pickRandom(),
                    'history' => [],
                    'profile' => [],
                ], $opponentData),
            ]);
        }

        // Invito all'avversario — include nota disparità se ELO gap > 50
        $hasDisparity = $eloGap > 50;
        if ($hasDisparity) {
            $inviteText = str_replace(
                ['{opponent_name}', '{challenger_name}', '{slot}', '{delta}'],
                [$opponent->name, $challenger->name, $friendly, $eloGap],
                "Ciao {opponent_name}! {challenger_name} ti sfida il {slot}.\nNota: c'è una differenza di livello ({delta} ELO). Accetti?",
            );
        } else {
            $inviteText = str_replace(
                ['{opponent_name}', '{challenger_name}', '{slot}'],
                [$opponent->name, $challenger->name, $friendly],
                'Ciao {opponent_name}! {challenger_name} ti sfida il {slot}. Accetti?',
            );
        }
        $this->whatsApp->sendButtons($opponent->phone, $inviteText, ['Accetta', 'Rifiuta']);

        // Notifica al challenger — se c'è disparità, lo segnaliamo
        if ($hasDisparity) {
            $disparityMsg = str_replace('{delta}', (string) $eloGap,
                $this->textGenerator->rephrase('match_trovato_disparita', $session->persona())
            );
            $this->whatsApp->sendText($challengerPhone, $disparityMsg);
        }

        Log::info('Matchmaking invite sent', [
            'challenger_id' => $challenger->id,
            'opponent_id'   => $opponent->id,
            'booking_id'    => $booking->id,
            'elo_gap'       => $eloGap,
            'slot'          => $friendly,
        ]);
    }

    private function confirmMatch(BotSession $session, string $phone): void
    {
        try {
            $bookingId = $session->getData('invited_booking_id');
            if (!$bookingId) {
                return;
            }

            $booking = Booking::find($bookingId);
            if (!$booking) {
                return;
            }

            // Aggiorna l'invitation
            MatchInvitation::where('booking_id', $bookingId)
                ->where('receiver_id', $booking->player2_id)
                ->update(['status' => 'accepted']);

            // Crea evento su Google Calendar
            $opponent = User::where('phone', $phone)->first();
            $dateStr  = \Carbon\Carbon::parse($booking->booking_date)->format('Y-m-d');
            $startDT  = \Carbon\Carbon::parse("{$dateStr} {$booking->start_time}", 'Europe/Rome');
            $endDT    = \Carbon\Carbon::parse("{$dateStr} {$booking->end_time}", 'Europe/Rome');

            $player1   = User::find($booking->player1_id);
            $summary   = "Partita singolo - {$player1?->name} vs {$opponent?->name}";
            $desc      = implode("\n", [
                "Giocatore 1: {$player1?->name} ({$player1?->phone})",
                "Giocatore 2: {$opponent?->name} ({$phone})",
                "Prenotato via: WhatsApp Bot (matchmaking)",
            ]);

            try {
                $gcalEvent = $this->calendar->createEvent(
                    summary:     $summary,
                    description: $desc,
                    startTime:   $startDT,
                    endTime:     $endDT,
                );
                $booking->update([
                    'status'               => 'confirmed',
                    'gcal_event_id'        => $gcalEvent->getId(),
                    'player2_confirmed_at' => now(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('confirmMatch: calendar event failed', ['error' => $e->getMessage()]);
                $booking->update([
                    'status'               => 'confirmed',
                    'player2_confirmed_at' => now(),
                ]);
            }

            // Notifica il challenger
            $challengerPhone = $session->getData('invited_by_phone');
            if ($challengerPhone) {
                $challengerSession = BotSession::where('phone', $challengerPhone)->first();
                $friendly          = $session->getData('invited_slot') ?? '';
                $opponentName      = $opponent?->name ?? 'L\'avversario';

                $msg = str_replace(
                    ['{opponent_name}', '{slot}'],
                    [$opponentName, $friendly],
                    'Ottima notizia! {opponent_name} ha accettato la sfida! Ci vediamo il {slot}. ✅',
                );
                $this->whatsApp->sendButtons(
                    $challengerPhone,
                    $msg,
                    ['Ho già un avversario', 'Trovami avversario', 'Sparapalline'],
                );

                if ($challengerSession) {
                    $challengerSession->update(['state' => BotState::CONFERMATO->value]);
                    $challengerSession->mergeData(['pending_booking_id' => null]);
                }
            }

            // Pulisci i dati invitation dalla sessione avversario
            $session->mergeData([
                'invited_booking_id' => null,
                'invited_by_phone'   => null,
                'invited_by_name'    => null,
                'invited_slot'       => null,
            ]);

            Log::info('Match confirmed', [
                'booking_id'   => $bookingId,
                'opponent'     => $phone,
                'challenger'   => $challengerPhone ?? 'unknown',
            ]);

        } catch (\Throwable $e) {
            Log::error('confirmMatch failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    private function refuseMatch(BotSession $session, string $phone): void
    {
        try {
            $bookingId = $session->getData('invited_booking_id');
            if (!$bookingId) {
                return;
            }

            $booking = Booking::find($bookingId);
            if ($booking) {
                MatchInvitation::where('booking_id', $bookingId)->update(['status' => 'refused']);
                $booking->update(['status' => 'cancelled']);
            }

            // Notifica il challenger
            $challengerPhone = $session->getData('invited_by_phone');
            if ($challengerPhone) {
                $challengerSession = BotSession::where('phone', $challengerPhone)->first();
                $opponentName      = User::where('phone', $phone)->value('name') ?? 'L\'avversario';

                $msg = str_replace(
                    ['{opponent_name}'],
                    [$opponentName],
                    'Purtroppo {opponent_name} non è disponibile. Vuoi cercare un altro avversario?',
                );
                $this->whatsApp->sendButtons(
                    $challengerPhone,
                    $msg,
                    ['Cerca avversario', 'Cambia orario', 'Menu'],
                );

                if ($challengerSession) {
                    $challengerSession->update(['state' => BotState::MENU->value]);
                    $challengerSession->mergeData(['pending_booking_id' => null]);
                }
            }

            // Pulisci i dati invitation dalla sessione avversario
            $session->mergeData([
                'invited_booking_id' => null,
                'invited_by_phone'   => null,
                'invited_by_name'    => null,
                'invited_slot'       => null,
            ]);

            Log::info('Match refused', [
                'booking_id' => $bookingId,
                'opponent'   => $phone,
                'challenger' => $challengerPhone ?? 'unknown',
            ]);

        } catch (\Throwable $e) {
            Log::error('refuseMatch failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    /* ───────── Feedback ───────── */

    private function saveFeedback(BotSession $session, string $phone): void
    {
        try {
            $rating    = $session->getData('feedback_rating');
            $comment   = $session->getData('feedback_comment');
            $bookingId = $session->getData('result_booking_id');

            $user = \App\Models\User::where('phone', $phone)->first();

            \App\Models\Feedback::create([
                'user_id'    => $user?->id,
                'booking_id' => $bookingId,
                'type'       => $bookingId ? 'match_feedback' : 'general',
                'rating'     => $rating,
                'content'    => $comment ? [['question' => 'Commento libero', 'answer' => $comment]] : null,
                'metadata'   => ['phone' => $phone, 'source' => 'bot'],
                'is_read'    => false,
            ]);

            // Pulisci dati sessione
            $session->mergeData([
                'feedback_rating'  => null,
                'feedback_comment' => null,
            ]);

            Log::info('Feedback salvato', ['phone' => $phone, 'rating' => $rating]);
        } catch (\Throwable $e) {
            Log::error('saveFeedback failed', ['phone' => $phone, 'error' => $e->getMessage()]);
        }
    }

    /* ───────── Risultati partita ───────── */

    private function processMatchResult(BotSession $session, string $phone): void
    {
        try {
            $bookingId = $session->getData('result_booking_id');
            $role      = $session->getData('result_role');      // 'player1' | 'player2'
            $outcome   = $session->getData('result_outcome');   // 'won' | 'lost' | 'no_show'
            $score     = $session->getData('result_score');
            $slot      = $session->getData('result_slot') ?? '';

            if (!$bookingId || !$role || !$outcome) {
                Log::warning('processMatchResult: dati mancanti in sessione', ['phone' => $phone]);
                return;
            }

            $matchResult = MatchResult::where('booking_id', $bookingId)->first();
            if (!$matchResult) {
                Log::error('processMatchResult: MatchResult non trovato', [
                    'booking_id' => $bookingId,
                    'phone'      => $phone,
                ]);
                return;
            }

            $booking = Booking::find($bookingId);
            if (!$booking) {
                return;
            }

            // Partita non giocata: segna entrambi confermati, nessun ELO
            if ($outcome === 'no_show') {
                $matchResult->update([
                    'player1_confirmed' => true,
                    'player2_confirmed' => true,
                    'confirmed_at'      => now(),
                ]);
                $booking->update(['status' => 'completed']);
                $session->mergeData(['result_booking_id' => null, 'result_role' => null]);
                return;
            }

            // Determina il winner_id secondo la dichiarazione di questo giocatore
            $user      = User::where('phone', $phone)->first();
            $winnerId  = null;

            if ($user) {
                $winnerId = ($outcome === 'won') ? $user->id : (
                    $role === 'player1' ? $booking->player2_id : $booking->player1_id
                );
            }

            // Aggiorna la colonna corrispondente al ruolo
            $update = $role === 'player1'
                ? ['player1_confirmed' => true, 'winner_id' => $winnerId]
                : ['player2_confirmed' => true];

            // Per player2, aggiorniamo winner solo se non già impostato o se concordano
            if ($role === 'player2' && $matchResult->player1_confirmed) {
                $update['winner_id'] = $winnerId;
            }

            if ($score) {
                $update['score'] = $score;
            }

            $matchResult->update($update);
            $matchResult->refresh();

            // Pulisci dati risultato dalla sessione
            $session->mergeData([
                'result_booking_id' => null,
                'result_role'       => null,
                'result_outcome'    => null,
                'result_score'      => null,
            ]);

            // Entrambi hanno confermato?
            if ($matchResult->player1_confirmed && $matchResult->player2_confirmed) {
                $this->finalizeMatchResult($matchResult, $booking, $slot);
            }

        } catch (\Throwable $e) {
            Log::error('processMatchResult failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function finalizeMatchResult(MatchResult $matchResult, Booking $booking, string $slot): void
    {
        $player1 = User::find($booking->player1_id);
        $player2 = User::find($booking->player2_id);

        if (!$player1 || !$player2) {
            return;
        }

        $winnerId = $matchResult->winner_id;

        // Controlla coerenza: se winner_id non è né player1 né player2 → discordanza
        if ($winnerId && !in_array($winnerId, [$player1->id, $player2->id])) {
            $winnerId = null;
        }

        if (!$winnerId) {
            // Discordanza — notifica entrambi, nessun aggiornamento ELO
            $this->whatsApp->sendText(
                $player1->phone,
                $this->textGenerator->rephrase('risultato_discordante', $booking->player1->name ?? 'Ciao'),
            );
            $this->whatsApp->sendText(
                $player2->phone,
                $this->textGenerator->rephrase('risultato_discordante', $booking->player2->name ?? 'Ciao'),
            );

            Log::warning('finalizeMatchResult: discordanza risultato', [
                'booking_id'      => $booking->id,
                'match_result_id' => $matchResult->id,
            ]);
            return;
        }

        // Aggiorna ELO
        $matchResult->update(['winner_id' => $winnerId]);
        $this->eloService->processResult($matchResult->fresh());

        // Notifica entrambi con il nuovo ELO
        $matchResult->refresh();
        $player1->refresh();
        $player2->refresh();

        $this->notifyEloUpdate($player1, $matchResult->player1_elo_before, $matchResult->player1_elo_after, $winnerId === $player1->id);
        $this->notifyEloUpdate($player2, $matchResult->player2_elo_before, $matchResult->player2_elo_after, $winnerId === $player2->id);
    }

    private function notifyEloUpdate(User $player, ?int $eloBefore, ?int $eloAfter, bool $won): void
    {
        if (!$eloBefore || !$eloAfter || !$player->phone) {
            return;
        }

        $delta        = $eloAfter - $eloBefore;
        $deltaStr     = $delta >= 0 ? "+{$delta}" : (string) $delta;
        $templateId   = $won ? 'elo_aggiornato_vinto' : 'elo_aggiornato_perso';

        $msg = $this->textGenerator->rephrase($templateId, 'Bot', [
            'elo_before' => $eloBefore,
            'elo_after'  => $eloAfter,
            'delta'      => $deltaStr,
        ]);

        try {
            $this->whatsApp->sendText($player->phone, $msg);
        } catch (\Throwable $e) {
            Log::warning('notifyEloUpdate: send failed', [
                'player' => $player->id,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /* ───────── Invio messaggi ───────── */

    private function sendResponse(string $phone, BotResponse $response): void
    {
        try {
            if ($response->hasButtons()) {
                $this->whatsApp->sendButtons($phone, $response->message, $response->buttons);
            } else {
                $this->whatsApp->sendText($phone, $response->message);
            }
        } catch (\Throwable $e) {
            Log::error('WhatsApp send failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendFallbackError(string $phone): void
    {
        try {
            $this->whatsApp->sendText(
                $phone,
                'Scusa, ho avuto un problema tecnico. Riprova tra qualche istante! 🙏'
            );
        } catch (\Throwable $e) {
            Log::critical('Cannot send fallback error to WhatsApp', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
