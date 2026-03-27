<?php

namespace App\Services\Bot;

use App\Models\BotSession;
use App\Models\User;
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
        private readonly StateHandler    $stateHandler,
        private readonly WhatsAppService $whatsApp,
        private readonly CalendarService $calendar,
        private readonly UserProfileService $profileService,
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

            // Se è una nuova sessione, invia il saluto e basta
            if ($session->wasRecentlyCreated && $session->state === BotState::NEW->value) {
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

            // Se c'era un calendar check, ri-processa con i risultati
            if ($response->needsCalendarCheck()) {
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

        // Creazione prenotazione
        if ($response->needsBookingCreation()) {
            $this->createBooking($session, $phone);
        }
    }

    /* ───────── Calendar ───────── */

    private function performCalendarCheck(BotSession $session): array
    {
        $date = $session->getData('requested_date');
        $time = $session->getData('requested_time');
        $raw  = $session->getData('requested_raw');

        if (empty($date)) {
            return ['available' => false, 'alternatives' => [], 'error' => 'missing_date'];
        }

        try {
            $query = $time ? "{$date} {$time}" : ($raw ?? $date);
            $result = $this->calendar->checkUserRequest($query);

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

    private function createBooking(BotSession $session, string $phone): void
    {
        try {
            $user = User::where('phone', $phone)->first();
            if (!$user) {
                Log::error('Cannot create booking: user not found', ['phone' => $phone]);
                return;
            }

            // TODO: Integrare con il BookingService completo dal modello di progetto.
            // Per ora logghiamo la prenotazione.
            Log::info('Booking created', [
                'user_id'      => $user->id,
                'date'         => $session->getData('requested_date'),
                'time'         => $session->getData('requested_time'),
                'booking_type' => $session->getData('booking_type'),
                'payment'      => $session->getData('payment_method'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Booking creation failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
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
