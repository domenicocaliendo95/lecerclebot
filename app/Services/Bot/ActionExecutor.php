<?php

namespace App\Services\Bot;

use App\Models\Booking;
use App\Models\BotSession;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Esegue azioni atomiche invocabili dal flusso bot.
 *
 * Ogni azione è un metodo privato che:
 *  - legge dati dalla sessione ($session->getData)
 *  - esegue operazioni (Calendar API, DB, ecc.)
 *  - salva risultati nella sessione ($session->mergeData)
 *
 * Le azioni sono divise in due categorie:
 *
 *  PRE-AZIONI (on_enter_actions): girano ALL'INGRESSO di uno stato, PRIMA
 *  di mostrare il messaggio. I risultati finiscono in session.data e possono
 *  essere usati dalle transitions condizionali per determinare dove andare.
 *
 *  POST-AZIONI (action su bottone/rule): girano DOPO la transizione, come
 *  effetti collaterali. Corrispondono ai vecchi "side_effect".
 */
class ActionExecutor
{
    public function __construct(
        private readonly CalendarService $calendar,
        private readonly TextGenerator   $textGenerator,
        private readonly WhatsAppService $whatsApp,
    ) {}

    /**
     * Esegue una singola azione. Restituisce true se eseguita, false se sconosciuta.
     */
    public function execute(string $action, BotSession $session, string $phone, ?User $user): bool
    {
        return match ($action) {
            // ── Pre-azioni (on_enter) ─────────────────────────
            'parse_date'       => $this->parseDate($session),
            'check_calendar'   => $this->checkCalendar($session),
            'load_bookings'    => $this->loadBookings($session, $user),

            // ── Generatori di bottoni dinamici ────────────────
            'gen_calendar_alternatives' => $this->genCalendarAlternatives($session),
            'gen_user_bookings'         => $this->genUserBookings($session, $user),
            'gen_pricing_durations'     => $this->genPricingDurations($session),

            // ── Post-azioni (button/rule click) ───────────────
            'create_booking'   => $this->createBooking($session, $phone, $user),
            'cancel_booking'   => $this->cancelBooking($session),
            'save_profile'     => $this->saveProfile($session, $phone),

            // ── Backward compat: i vecchi side_effect names ───
            'calendarCheck'         => $this->checkCalendar($session),
            'bookingToCreate'       => $this->createBooking($session, $phone, $user),
            'bookingToCancel'       => $this->cancelBooking($session),
            'matchmakingSearch'     => true,  // gestiti nell'orchestrator (troppo complessi per atomizzare ora)
            'matchAccepted'         => true,
            'matchRefused'          => true,
            'matchResultToSave'     => true,
            'feedbackToSave'        => true,
            'opponentLinkConfirmed' => true,
            'opponentLinkRejected'  => true,
            'paymentRequired'       => true,

            default => false,
        };
    }

    /**
     * Esegue un array di azioni in sequenza (per on_enter_actions).
     */
    public function executeAll(array $actions, BotSession $session, string $phone, ?User $user): void
    {
        foreach ($actions as $action) {
            if (is_string($action)) {
                try {
                    $this->execute($action, $session, $phone, $user);
                } catch (\Throwable $e) {
                    Log::warning("ActionExecutor: action '{$action}' failed", [
                        'phone' => $phone,
                        'state' => $session->state,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * Whitelist di tutte le azioni disponibili, con descrizioni friendly.
     * Usata dal frontend per popolare i dropdown.
     */
    public static function availableActions(): array
    {
        return [
            // Pre-azioni
            'parse_date' => [
                'label'       => 'Interpreta data/ora',
                'description' => "Legge l'ultimo input come data in linguaggio naturale e salva in data.requested_date/time/friendly",
                'timing'      => 'pre',
            ],
            'check_calendar' => [
                'label'       => 'Verifica calendario',
                'description' => 'Controlla se lo slot è libero su Google Calendar. Salva in data.calendar_available e data.calendar_alternatives',
                'timing'      => 'pre',
            ],
            'load_bookings' => [
                'label'       => 'Carica prenotazioni',
                'description' => "Carica le prossime prenotazioni dell'utente in data.bookings_list",
                'timing'      => 'pre',
            ],
            'gen_calendar_alternatives' => [
                'label'       => 'Genera bottoni alternativi calendario',
                'description' => 'Crea bottoni dinamici dagli slot alternativi trovati dal calendario (data.calendar_alternatives)',
                'timing'      => 'pre',
            ],
            'gen_user_bookings' => [
                'label'       => 'Genera bottoni prenotazioni utente',
                'description' => 'Crea bottoni dinamici dalle prossime prenotazioni dell\'utente (data.bookings_list)',
                'timing'      => 'pre',
            ],
            'gen_pricing_durations' => [
                'label'       => 'Genera bottoni durate/prezzi',
                'description' => 'Crea bottoni con le durate disponibili e i relativi prezzi (da PricingRule)',
                'timing'      => 'pre',
            ],

            // Post-azioni
            'create_booking' => [
                'label'       => 'Crea prenotazione',
                'description' => 'Crea un record Booking + evento Google Calendar con i dati dalla sessione',
                'timing'      => 'post',
            ],
            'cancel_booking' => [
                'label'       => 'Cancella prenotazione',
                'description' => 'Cancella la prenotazione selezionata (data.selected_booking_id) + evento Calendar',
                'timing'      => 'post',
            ],
            'save_profile' => [
                'label'       => 'Salva profilo utente',
                'description' => 'Salva i dati raccolti nel profilo (da session.data.profile a tabella users)',
                'timing'      => 'post',
            ],
            'search_matchmaking' => [
                'label'       => 'Cerca avversario (matchmaking)',
                'description' => 'Cerca un avversario per ELO simile, crea booking + invia invito WA',
                'timing'      => 'post',
            ],
            'send_match_invite' => [
                'label'       => 'Invia invito partita',
                'description' => "Invia messaggio WhatsApp all'avversario con i bottoni Accetta/Rifiuta",
                'timing'      => 'post',
            ],
            'confirm_match' => [
                'label'       => 'Conferma sfida',
                'description' => "L'avversario accetta: crea evento calendar, conferma booking, notifica challenger",
                'timing'      => 'post',
            ],
            'refuse_match' => [
                'label'       => 'Rifiuta sfida',
                'description' => "L'avversario rifiuta: cancella booking, notifica challenger",
                'timing'      => 'post',
            ],
            'save_match_result' => [
                'label'       => 'Salva risultato partita',
                'description' => 'Registra esito (vinto/perso/non giocata) e aggiorna ELO se entrambi confermano',
                'timing'      => 'post',
            ],
            'save_feedback' => [
                'label'       => 'Salva feedback',
                'description' => 'Salva rating 1-5 + commento nella tabella feedbacks',
                'timing'      => 'post',
            ],
            'confirm_opponent' => [
                'label'       => 'Conferma avversario',
                'description' => "L'avversario conferma di essere il compagno di gioco. Abilita tracking ELO",
                'timing'      => 'post',
            ],
            'reject_opponent' => [
                'label'       => 'Rifiuta link avversario',
                'description' => "L'avversario nega di essere il compagno. Rimuove player2_id dalla prenotazione",
                'timing'      => 'post',
            ],
        ];
    }

    /**
     * Solo le pre-azioni (per il dropdown on_enter).
     */
    public static function preActions(): array
    {
        return array_filter(self::availableActions(), fn($a) => $a['timing'] === 'pre');
    }

    /**
     * Solo le post-azioni (per il dropdown bottoni/rules).
     */
    public static function postActions(): array
    {
        return array_filter(self::availableActions(), fn($a) => $a['timing'] === 'post');
    }

    /* ═══════════════════════════════════════════════════════════════
     *  PRE-AZIONI
     * ═══════════════════════════════════════════════════════════════ */

    private function parseDate(BotSession $session): bool
    {
        $input = $session->getData('last_input');
        if (!$input) {
            return false;
        }

        $parsed = $this->textGenerator->parseDateTime($input);
        if ($parsed === null) {
            $session->mergeData(['date_parsed' => false]);
            return false;
        }

        $session->mergeData([
            'requested_date'     => $parsed['date'],
            'requested_time'     => $parsed['time'],
            'requested_friendly' => $parsed['friendly'],
            'requested_raw'      => $input,
            'date_parsed'        => true,
        ]);

        Log::info('ActionExecutor: parse_date success', [
            'input'  => $input,
            'result' => $parsed,
        ]);

        return true;
    }

    private function checkCalendar(BotSession $session): bool
    {
        $date     = $session->getData('requested_date');
        $time     = $session->getData('requested_time');
        $raw      = $session->getData('requested_raw');
        $duration = $session->getData('requested_duration_minutes') ?? 60;

        if (empty($date)) {
            $session->mergeData([
                'calendar_available'    => false,
                'calendar_alternatives' => [],
                'calendar_error'        => 'missing_date',
            ]);
            return false;
        }

        try {
            $query  = $time ? "{$date} {$time}" : ($raw ?? $date);
            $result = $this->calendar->checkUserRequest($query, $duration);

            $session->mergeData([
                'calendar_available'     => $result['available'] ?? false,
                'calendar_alternatives'  => $result['alternatives'] ?? [],
                'calendar_result'        => $result,  // backward compat
                'calendar_error'         => null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('ActionExecutor: check_calendar failed', [
                'date'  => $date,
                'time'  => $time,
                'error' => $e->getMessage(),
            ]);

            $session->mergeData([
                'calendar_available'    => false,
                'calendar_alternatives' => [],
                'calendar_error'        => 'api_error',
            ]);

            return false;
        }
    }

    private function loadBookings(BotSession $session, ?User $user): bool
    {
        if (!$user) {
            $session->mergeData(['bookings_list' => []]);
            return false;
        }

        $bookings = Booking::where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)
                  ->orWhere('player2_id', $user->id);
            })
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->where('booking_date', '>=', now()->format('Y-m-d'))
            ->orderBy('booking_date')
            ->orderBy('start_time')
            ->limit(3)
            ->get()
            ->map(fn(Booking $b) => [
                'id'     => $b->id,
                'date'   => $b->booking_date->format('Y-m-d'),
                'time'   => substr($b->start_time, 0, 5),
                'status' => $b->status,
                'label'  => $b->booking_date->locale('it')->isoFormat('ddd D MMM') . ' ' . substr($b->start_time, 0, 5),
            ])
            ->toArray();

        $session->mergeData(['bookings_list' => $bookings]);
        return true;
    }

    /* ═══════════════════════════════════════════════════════════════
     *  GENERATORI BOTTONI DINAMICI
     *  Scrivono in data._dynamic_buttons [{label, value, target_state}]
     *  Il generic handler li usa al posto dei bottoni statici del DB.
     * ═══════════════════════════════════════════════════════════════ */

    private function genCalendarAlternatives(BotSession $session): bool
    {
        $alternatives = $session->getData('calendar_alternatives') ?? [];
        if (empty($alternatives)) {
            $session->mergeData(['_dynamic_buttons' => []]);
            return false;
        }

        $buttons = [];
        foreach (array_slice($alternatives, 0, 3) as $alt) {
            $time  = $alt['time'] ?? $alt['start'] ?? '';
            $price = isset($alt['price']) ? " €{$alt['price']}" : '';
            $label = substr($time, 0, 5) . $price;
            if (mb_strlen($label) > 20) {
                $label = mb_substr($label, 0, 20);
            }
            $buttons[] = [
                'label'        => $label,
                'value'        => $time,
                'target_state' => 'CONFERMA',
            ];
        }

        $session->mergeData(['_dynamic_buttons' => $buttons]);
        return true;
    }

    private function genUserBookings(BotSession $session, ?User $user): bool
    {
        // Prima carica le prenotazioni se non già presente
        $list = $session->getData('bookings_list');
        if ($list === null && $user) {
            $this->loadBookings($session, $user);
            $list = $session->getData('bookings_list');
        }

        if (empty($list)) {
            $session->mergeData(['_dynamic_buttons' => []]);
            return false;
        }

        $buttons = [];
        foreach (array_slice($list, 0, 3) as $b) {
            $label = $b['label'] ?? "{$b['date']} {$b['time']}";
            if (mb_strlen($label) > 20) {
                $label = mb_substr($label, 0, 20);
            }
            $buttons[] = [
                'label'        => $label,
                'value'        => (string) $b['id'],
                'target_state' => 'AZIONE_PRENOTAZIONE',
            ];
        }

        $session->mergeData(['_dynamic_buttons' => $buttons]);
        return true;
    }

    private function genPricingDurations(BotSession $session): bool
    {
        $durations = \App\Models\PricingRule::availableDurations();
        if (empty($durations)) {
            $session->mergeData(['_dynamic_buttons' => []]);
            return false;
        }

        $startTime = null;
        $date = $session->getData('requested_date');
        $time = $session->getData('requested_time');
        if ($date && $time) {
            $startTime = \Carbon\Carbon::parse("{$date} {$time}", 'Europe/Rome');
        }

        $buttons = [];
        foreach (array_slice($durations, 0, 3) as $min) {
            $label = \App\Models\PricingRule::durationLabel($min);
            if ($startTime) {
                $price = \App\Models\PricingRule::getPriceForSlot($startTime, $min);
                $label .= " €{$price}";
            }
            if (mb_strlen($label) > 20) {
                $label = mb_substr($label, 0, 20);
            }
            $buttons[] = [
                'label'        => $label,
                'value'        => (string) $min,
                'target_state' => 'VERIFICA_SLOT',
            ];
        }

        $session->mergeData(['_dynamic_buttons' => $buttons]);
        return true;
    }

    /* ═══════════════════════════════════════════════════════════════
     *  POST-AZIONI
     * ═══════════════════════════════════════════════════════════════ */

    private function createBooking(BotSession $session, string $phone, ?User $user): bool
    {
        if (!$user) {
            return false;
        }

        $date        = $session->getData('requested_date');
        $time        = $session->getData('requested_time');
        $bookingType = $session->getData('booking_type') ?? 'con_avversario';
        $payment     = $session->getData('payment_method') ?? 'in_loco';
        $duration    = $session->getData('requested_duration_minutes') ?? 60;
        $opponentId  = $session->getData('opponent_user_id');
        $opponentName= $session->getData('opponent_name');

        if (empty($date) || empty($time)) {
            return false;
        }

        try {
            // Modifica: cancella vecchia prenotazione se presente
            $editingId = $session->getData('editing_booking_id');
            if ($editingId) {
                $old = Booking::find($editingId);
                if ($old) {
                    if ($old->gcal_event_id) {
                        try { $this->calendar->deleteEvent($old->gcal_event_id); } catch (\Throwable) {}
                    }
                    $old->update(['status' => 'cancelled']);
                }
                $session->mergeData(['editing_booking_id' => null, 'selected_booking_id' => null]);
            }

            $startDT = \Carbon\Carbon::parse("{$date} {$time}", 'Europe/Rome');
            $endDT   = $startDT->copy()->addMinutes($duration);

            $typeLabels = [
                'con_avversario' => 'Partita singolo',
                'matchmaking'    => 'Partita (matchmaking)',
                'sparapalline'   => 'Noleggio sparapalline',
            ];
            $typeLabel = $typeLabels[$bookingType] ?? 'Prenotazione campo';

            $summary = ($bookingType === 'con_avversario' && $opponentName)
                ? "Partita singolo - {$user->name} vs {$opponentName}"
                : "{$typeLabel} - {$user->name}";

            $descLines = ["Giocatore: {$user->name}", "Telefono: {$phone}", "Tipo: {$typeLabel}", "Pagamento: {$payment}"];
            if ($opponentName) {
                $descLines[] = "Avversario: {$opponentName}";
            }
            $descLines[] = "Prenotato via: WhatsApp Bot";

            $price = \App\Models\PricingRule::getPriceForSlot($startDT, $duration);

            $gcalEvent = $this->calendar->createEvent(
                summary:     $summary,
                description: implode("\n", $descLines),
                startTime:   $startDT,
                endTime:     $endDT,
            );

            $booking = Booking::create([
                'player1_id'        => $user->id,
                'player2_id'        => $opponentId,
                'player2_name_text' => $opponentId ? null : $opponentName,
                'booking_date'      => $startDT->format('Y-m-d'),
                'start_time'        => $startDT->format('H:i:s'),
                'end_time'          => $endDT->format('H:i:s'),
                'price'             => $price,
                'is_peak'           => $startDT->hour >= 18,
                'status'            => 'confirmed',
                'gcal_event_id'     => $gcalEvent->getId(),
            ]);

            Log::info('ActionExecutor: create_booking', [
                'booking_id' => $booking->id,
                'user'       => $user->id,
                'date'       => $date,
                'time'       => $time,
            ]);

            // Pulisci dati avversario
            $session->mergeData([
                'opponent_user_id'        => null,
                'opponent_name'           => null,
                'opponent_phone'          => null,
                'opponent_search_results' => null,
                'opponent_pending_confirm'=> null,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('ActionExecutor: create_booking failed', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function cancelBooking(BotSession $session): bool
    {
        $bookingId = $session->getData('selected_booking_id');
        if (!$bookingId) {
            return false;
        }

        try {
            $booking = Booking::find($bookingId);
            if (!$booking) {
                return false;
            }

            if ($booking->gcal_event_id) {
                try { $this->calendar->deleteEvent($booking->gcal_event_id); } catch (\Throwable) {}
            }

            $booking->update(['status' => 'cancelled']);
            $session->mergeData(['selected_booking_id' => null]);

            return true;
        } catch (\Throwable $e) {
            Log::error('ActionExecutor: cancel_booking failed', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function saveProfile(BotSession $session, string $phone): bool
    {
        $profile = $session->profile();
        if (empty($profile)) {
            return false;
        }

        try {
            // Delega a UserProfileService (iniettato da service container)
            app(UserProfileService::class)->saveFromBot($phone, $profile);
            return true;
        } catch (\Throwable $e) {
            Log::error('ActionExecutor: save_profile failed', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
