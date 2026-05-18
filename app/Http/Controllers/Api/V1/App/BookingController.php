<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\BotSetting;
use App\Models\User;
use App\Services\CalendarService;
use App\Services\UserSearchService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function __construct(
        private CalendarService $calendar,
        private UserSearchService $userSearch,
        private WhatsAppService $wa,
    ) {}

    /**
     * GET /v1/app/bookings?status=upcoming|past|all&from=&to=
     * Solo bookings dell'utente loggato (come player1 o player2).
     */
    public function index(Request $request): JsonResponse
    {
        $user   = $request->user();
        $status = $request->query('status', 'upcoming');

        $q = Booking::query()
            ->where(function ($w) use ($user) {
                $w->where('player1_id', $user->id)->orWhere('player2_id', $user->id);
            })
            ->with(['player1:id,name,phone,avatar_path,elo_rating',
                    'player2:id,name,phone,avatar_path,elo_rating']);

        $now = Carbon::now('Europe/Rome');

        if ($status === 'upcoming') {
            $q->whereRaw("CONCAT(booking_date, ' ', start_time) >= ?", [$now->format('Y-m-d H:i:s')])
              ->whereIn('status', ['confirmed', 'pending_match'])
              ->orderBy('booking_date')->orderBy('start_time');
        } elseif ($status === 'past') {
            $q->whereRaw("CONCAT(booking_date, ' ', end_time) < ?", [$now->format('Y-m-d H:i:s')])
              ->orderByDesc('booking_date')->orderByDesc('start_time');
        } else {
            $q->orderByDesc('booking_date')->orderByDesc('start_time');
        }

        if ($from = $request->query('from')) $q->where('booking_date', '>=', $from);
        if ($to   = $request->query('to'))   $q->where('booking_date', '<=', $to);

        $bookings = $q->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($bookings->items())->map(fn($b) => $this->serialize($b, $user->id))->all(),
            'meta' => [
                'current_page' => $bookings->currentPage(),
                'last_page'    => $bookings->lastPage(),
                'total'        => $bookings->total(),
            ],
        ]);
    }

    /**
     * GET /v1/app/bookings/next  — la prossima partita dell'utente
     */
    public function next(Request $request): JsonResponse
    {
        $user = $request->user();
        $now  = Carbon::now('Europe/Rome');

        $booking = Booking::where(function ($w) use ($user) {
                $w->where('player1_id', $user->id)->orWhere('player2_id', $user->id);
            })
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->whereRaw("CONCAT(booking_date, ' ', start_time) >= ?", [$now->format('Y-m-d H:i:s')])
            ->with(['player1:id,name,phone,avatar_path,elo_rating',
                    'player2:id,name,phone,avatar_path,elo_rating'])
            ->orderBy('booking_date')->orderBy('start_time')
            ->first();

        return response()->json(['data' => $booking ? $this->serialize($booking, $user->id) : null]);
    }

    /**
     * GET /v1/app/bookings/{id}
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $booking = Booking::with(['player1:id,name,phone,avatar_path,elo_rating',
                                  'player2:id,name,phone,avatar_path,elo_rating'])
            ->where('id', $id)
            ->where(function ($w) use ($user) {
                $w->where('player1_id', $user->id)->orWhere('player2_id', $user->id);
            })
            ->first();

        if (!$booking) {
            return response()->json(['error' => ['code' => 'not_found']], 404);
        }

        return response()->json(['data' => $this->serialize($booking, $user->id)]);
    }

    /**
     * GET /v1/app/bookings/availability?date=YYYY-MM-DD&duration_minutes=60
     * Ritorna tutti gli slot del giorno con status e prezzo.
     */
    public function availability(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date'             => 'required|date_format:Y-m-d',
            'duration_minutes' => 'sometimes|integer|in:60,90,120',
        ]);

        $date     = Carbon::parse($validated['date'], 'Europe/Rome')->startOfDay();
        $duration = (int) ($validated['duration_minutes'] ?? 60);

        try {
            $slots = $this->calendar->listDaySlots($date, $duration);
        } catch (\Throwable $e) {
            Log::error('App availability failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => ['code' => 'calendar_unavailable']], 503);
        }

        return response()->json([
            'date'             => $validated['date'],
            'duration_minutes' => $duration,
            'slots'            => $slots,
        ]);
    }

    /**
     * POST /v1/app/bookings
     * Body: {
     *   date, start_time, duration_minutes,
     *   type: 'con_avversario'|'matchmaking'|'sparapalline',
     *   opponent_user_id?, opponent_name_text?,
     *   payment_method?: 'online'|'in_loco',
     *   notes?
     * }
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'date'               => 'required|date_format:Y-m-d',
            'start_time'         => 'required|date_format:H:i',
            'duration_minutes'   => 'required|integer|in:60,90,120',
            'type'               => 'required|in:con_avversario,matchmaking,sparapalline',
            'opponent_user_id'   => 'nullable|integer|exists:users,id',
            'opponent_name_text' => 'nullable|string|max:100',
            'payment_method'     => 'sometimes|in:online,in_loco',
            'notes'              => 'nullable|string|max:500',
        ]);

        // Verifica slot ancora libero
        $startCarbon = Carbon::parse("{$data['date']} {$data['start_time']}", 'Europe/Rome');
        $endCarbon   = $startCarbon->copy()->addMinutes($data['duration_minutes']);

        try {
            $check = $this->calendar->checkUserRequest(
                $startCarbon->format('Y-m-d H:i'),
                $data['duration_minutes']
            );
        } catch (\Throwable $e) {
            return response()->json(['error' => ['code' => 'calendar_unavailable']], 503);
        }

        if (!($check['available'] ?? false)) {
            return response()->json([
                'error' => [
                    'code' => 'slot_unavailable',
                    'message' => 'Slot non più disponibile.',
                    'alternatives' => $check['alternatives'] ?? [],
                ],
            ], 409);
        }

        $price = \App\Models\PricingRule::getPriceForSlot($startCarbon, $data['duration_minutes']);
        $isPeak = $startCarbon->hour >= 18 || in_array($startCarbon->dayOfWeek, [0, 6]);

        $booking = DB::transaction(function () use ($user, $data, $startCarbon, $endCarbon, $price, $isPeak) {
            $b = Booking::create([
                'player1_id'         => $user->id,
                'player2_id'         => $data['opponent_user_id'] ?? null,
                'player2_name_text'  => empty($data['opponent_user_id']) ? ($data['opponent_name_text'] ?? null) : null,
                'booking_date'       => $data['date'],
                'start_time'         => $data['start_time'],
                'end_time'           => $endCarbon->format('H:i'),
                'price'              => $price,
                'is_peak'            => $isPeak,
                'status'             => $data['type'] === 'matchmaking' ? 'pending_match' : 'confirmed',
                'created_via'        => 'app',
                'notes'              => $data['notes'] ?? null,
            ]);

            // Crea evento gcal con stesso pattern del bot
            try {
                $opponentName = $b->player2_id
                    ? optional($b->player2()->first())->name
                    : $b->player2_name_text;

                $typeLabels = [
                    'con_avversario' => 'Partita singolo',
                    'matchmaking'    => 'Partita (matchmaking)',
                    'sparapalline'   => 'Noleggio sparapalline',
                ];
                $typeLabel = $typeLabels[$data['type']] ?? 'Prenotazione campo';

                $summary = ($data['type'] === 'con_avversario' && $opponentName)
                    ? "Partita singolo - {$user->name} vs {$opponentName}"
                    : "{$typeLabel} - {$user->name}";

                $descLines = [
                    "Giocatore: {$user->name}",
                    "Telefono: {$user->phone}",
                    "Tipo: {$typeLabel}",
                    "Pagamento: " . ($data['payment_method'] ?? 'in_loco'),
                ];
                if ($opponentName) {
                    $descLines[] = "Avversario: {$opponentName}";
                }
                $descLines[] = 'Prenotato via: App';

                $event = $this->calendar->createEvent(
                    $summary,
                    implode("\n", $descLines),
                    $startCarbon,
                    $endCarbon,
                );
                $b->update(['gcal_event_id' => $event->getId()]);
            } catch (\Throwable $e) {
                Log::warning('gcal event create failed', ['booking_id' => $b->id, 'error' => $e->getMessage()]);
            }

            return $b->fresh(['player1', 'player2']);
        });

        // ── Notifiche WhatsApp post-creazione (fuori dalla transazione) ──
        $this->notifyAdmin($booking, $user, $startCarbon, $endCarbon);
        $this->notifyOpponent($booking, $user, $startCarbon);

        return response()->json([
            'data' => $this->serialize($booking, $user->id),
        ], 201);
    }

    /**
     * Notifica admin via template `admin_prenotazione`.
     * Stesso pattern di CreaPrenotazioneModule del bot.
     */
    private function notifyAdmin(Booking $b, User $challenger, Carbon $start, Carbon $end): void
    {
        try {
            $adminPhone = BotSetting::get('admin_phone');
            if (!$adminPhone) {
                Log::info('📢 admin_phone non configurato — skip notify');
                return;
            }

            $opponentName = $b->player2_id
                ? optional($b->player2()->first())->name
                : $b->player2_name_text;

            $players = $opponentName ? "{$challenger->name} vs {$opponentName}" : $challenger->name;
            $dateStr = $start->locale('it')->isoFormat('ddd D MMM');
            $timeStr = "{$start->format('H:i')}-{$end->format('H:i')}";

            $this->wa->sendTemplate((string) $adminPhone, 'admin_prenotazione', [$players, $dateStr, $timeStr]);
            Log::info('📢 Admin notificato (app)', ['booking' => $b->id]);
        } catch (\Throwable $e) {
            Log::warning('📢 Admin notify failed', ['booking_id' => $b->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Notifica avversario via template `invito_avversario`.
     * Solo se è user tesserato del circolo con phone.
     */
    private function notifyOpponent(Booking $b, User $challenger, Carbon $start): void
    {
        if (!$b->player2_id) return;

        try {
            $opponent = $b->player2()->first();
            if (!$opponent || !$opponent->phone) return;

            $slotFriendly = $start->locale('it')->isoFormat('dddd D MMMM') . ' alle ' . $start->format('H:i');

            $this->wa->sendTemplate($opponent->phone, 'invito_avversario', [
                $opponent->name,
                $challenger->name,
                $slotFriendly,
            ]);
            Log::info('📩 Avversario notificato (app)', ['phone' => $opponent->phone, 'booking' => $b->id]);
        } catch (\Throwable $e) {
            Log::warning('📩 Avversario notify failed', ['booking_id' => $b->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * GET /v1/app/players/search?q=...
     * Per cercare l'avversario quando type=con_avversario.
     */
    public function searchPlayers(Request $request): JsonResponse
    {
        $request->validate(['q' => 'required|string|min:2|max:40']);

        $me = $request->user();
        $results = $this->userSearch->search($request->query('q'), 8, true)
            ->reject(fn($u) => $u->id === $me->id)
            ->map(fn($u) => [
                'id'         => $u->id,
                'name'       => $u->name,
                'avatar_url' => $u->avatar_path ? asset('storage/' . $u->avatar_path) : null,
                'elo_rating' => $u->elo_rating,
                'fit_rating' => $u->fit_rating,
            ])
            ->values();

        return response()->json(['data' => $results]);
    }

    /**
     * DELETE /v1/app/bookings/{id}  — cancella prenotazione
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $booking = Booking::where('id', $id)
            ->where('player1_id', $user->id)
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->first();

        if (!$booking) {
            return response()->json(['error' => ['code' => 'not_found_or_not_owned']], 404);
        }

        if ($booking->gcal_event_id) {
            try {
                $this->calendar->deleteEvent($booking->gcal_event_id);
            } catch (\Throwable $e) {
                Log::warning('gcal event delete failed', ['booking_id' => $booking->id, 'error' => $e->getMessage()]);
            }
        }
        $booking->update(['status' => 'cancelled']);

        return response()->json(['ok' => true]);
    }

    // ── helper serializer ────────────────────────────────────────────────

    private function serialize(Booking $b, int $viewerId): array
    {
        $isP1 = $b->player1_id === $viewerId;
        $opponent = $isP1 ? $b->player2 : $b->player1;
        $opponentName = $opponent?->name ?? $b->player2_name_text;

        // booking_date è cast a Carbon dall'Eloquent — devo formattarlo come
        // 'Y-m-d' altrimenti viene serializzato con tempo+microsecondi e
        // l'ISO concatenato risulta invalido per Date() in JS (→ NaN).
        $dateStr = $b->booking_date instanceof \Illuminate\Support\Carbon
            ? $b->booking_date->format('Y-m-d')
            : (string) $b->booking_date;
        $timeStr = substr((string) $b->start_time, 0, 5);
        $startsAtIso = Carbon::parse("{$dateStr} {$timeStr}", 'Europe/Rome')->toIso8601String();

        return [
            'id'                => $b->id,
            'date'              => $dateStr,
            'start_time'        => $timeStr,
            'end_time'          => substr((string) $b->end_time, 0, 5),
            'duration_minutes'  => $this->durationMinutes($b->start_time, $b->end_time),
            'price'             => (float) $b->price,
            'is_peak'           => (bool) $b->is_peak,
            'status'            => $b->status,
            'created_via'       => $b->created_via,
            'notes'             => $b->notes,
            'me_role'           => $isP1 ? 'player1' : 'player2',
            'opponent'          => $opponent ? [
                'id'         => $opponent->id,
                'name'       => $opponent->name,
                'avatar_url' => $opponent->avatar_path ? asset('storage/' . $opponent->avatar_path) : null,
                'elo_rating' => $opponent->elo_rating,
            ] : null,
            'opponent_name_text' => $opponentName,
            'opponent_confirmed' => $b->player2_id !== null && $b->player2_confirmed_at !== null,
            'starts_at_iso'      => $startsAtIso,
        ];
    }

    private function durationMinutes(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }
}
