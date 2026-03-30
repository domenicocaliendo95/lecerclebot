<?php

namespace App\Services\Bot;

use App\Models\Booking;
use App\Models\BotSession;
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

            // Processa tramite la macchina a stati
            $response = $this->stateHandler->handle($session, $input, $user);

            // Valida la transizione di stato
            $currentState = BotState::from($session->state);
            $newState     = $currentState->transitionTo($response->nextState);

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
                $response = $this->stateHandler->handle($session, '', $user);
                $newState  = BotState::from($session->state)->transitionTo($response->nextState);
            }

            // Aggiorna sessione
            $session->update(['state' => $newState->value]);
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
            $greeting = BotPersona::greetingReturning($persona, $user->name);
            $buttons  = ['Ho già un avversario', 'Trovami un avversario', 'Noleggio sparapalline'];

            // Aggiorna lo stato a MENU perché l'utente è registrato
            $session->update(['state' => BotState::MENU->value]);
            $this->whatsApp->sendButtons($phone, $greeting, $buttons);
        } else {
            $greeting = BotPersona::greetingNew($persona);
            // Lo stato rimane NEW — il prossimo input sarà il nome
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

            $date        = $session->getData('requested_date');  // Y-m-d
            $time        = $session->getData('requested_time');  // H:i
            $bookingType = $session->getData('booking_type') ?? 'con_avversario';
            $payment     = $session->getData('payment_method') ?? 'in_loco';

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

            $summary     = "{$typeLabel} - {$user->name}";
            $description = implode("\n", [
                "Giocatore: {$user->name}",
                "Telefono: {$phone}",
                "Tipo: {$typeLabel}",
                "Pagamento: {$payment}",
                "Prenotato via: WhatsApp Bot",
            ]);

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
            Booking::create([
                'player1_id'   => $user->id,
                'booking_date' => $startDateTime->format('Y-m-d'),
                'start_time'   => $startDateTime->format('H:i:s'),
                'end_time'     => $endDateTime->format('H:i:s'),
                'price'        => $price,
                'is_peak'      => $startDateTime->hour >= 18,
                'status'       => 'confirmed',
                'gcal_event_id'=> $gcalEvent->getId(),
            ]);

            Log::info('Booking created on Google Calendar', [
                'user_id'   => $user->id,
                'user_name' => $user->name,
                'date'      => $date,
                'time'      => $time,
                'type'      => $bookingType,
                'payment'   => $payment,
                'start'     => $startDateTime->toIso8601String(),
                'end'       => $endDateTime->toIso8601String(),
            ]);

        } catch (\Throwable $e) {
            Log::error('Booking creation failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /* ───────── Matchmaking ───────── */

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

            // Cerca un avversario con ELO simile (±200), con telefono, diverso dal challenger
            $opponent = User::where('id', '!=', $challenger->id)
                ->whereNotNull('phone')
                ->whereBetween('elo_rating', [$challengerElo - 200, $challengerElo + 200])
                ->inRandomOrder()
                ->first();

            if (!$opponent) {
                Log::info('triggerMatchmaking: no opponent found', [
                    'challenger_id' => $challenger->id,
                    'elo'           => $challengerElo,
                ]);
                $this->whatsApp->sendButtons(
                    $phone,
                    $this->textGenerator->rephrase('nessun_avversario', $session->persona()),
                    ['Cambia orario', 'Menu'],
                );
                $session->update(['state' => BotState::MENU->value]);
                return;
            }

            // Crea prenotazione in stato pending_match
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

            // Crea record invitation
            MatchInvitation::create([
                'booking_id'  => $booking->id,
                'receiver_id' => $opponent->id,
                'status'      => 'pending',
            ]);

            // Aggiorna sessione del challenger
            $session->mergeData([
                'pending_booking_id' => $booking->id,
                'opponent_name'      => $opponent->name,
                'opponent_phone'     => $opponent->phone,
            ]);

            // Trova o crea sessione dell'avversario
            $opponentSession = BotSession::where('phone', $opponent->phone)->first();
            $opponentData    = [
                'invited_by_phone'   => $phone,
                'invited_by_name'    => $challenger->name,
                'invited_slot'       => $friendly,
                'invited_booking_id' => $booking->id,
            ];

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

            // Invia invito all'avversario
            $inviteText = str_replace(
                ['{opponent_name}', '{challenger_name}', '{slot}'],
                [$opponent->name, $challenger->name, $friendly],
                'Ciao {opponent_name}! {challenger_name} ti sfida il {slot}. Accetti?',
            );
            $this->whatsApp->sendButtons($opponent->phone, $inviteText, ['Accetta', 'Rifiuta']);

            Log::info('Matchmaking invite sent', [
                'challenger_id' => $challenger->id,
                'opponent_id'   => $opponent->id,
                'booking_id'    => $booking->id,
                'slot'          => $friendly,
            ]);

        } catch (\Throwable $e) {
            Log::error('triggerMatchmaking failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
        }
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
                    'status'        => 'confirmed',
                    'gcal_event_id' => $gcalEvent->getId(),
                ]);
            } catch (\Throwable $e) {
                Log::warning('confirmMatch: calendar event failed', ['error' => $e->getMessage()]);
                $booking->update(['status' => 'confirmed']);
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
