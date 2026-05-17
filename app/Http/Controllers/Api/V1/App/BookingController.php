<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BookingController extends Controller
{
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

        // TODO: cancellazione Google Calendar — riusare CalendarService::deleteEvent($booking->gcal_event_id)
        $booking->update(['status' => 'cancelled']);

        return response()->json(['ok' => true]);
    }

    // ── helper serializer ────────────────────────────────────────────────

    private function serialize(Booking $b, int $viewerId): array
    {
        $isP1 = $b->player1_id === $viewerId;
        $opponent = $isP1 ? $b->player2 : $b->player1;
        $opponentName = $opponent?->name ?? $b->player2_name_text;

        return [
            'id'                => $b->id,
            'date'              => $b->booking_date,
            'start_time'        => substr($b->start_time, 0, 5),
            'end_time'          => substr($b->end_time, 0, 5),
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
            'starts_at_iso'      => $b->booking_date . 'T' . $b->start_time,
        ];
    }

    private function durationMinutes(string $start, string $end): int
    {
        [$sh, $sm] = array_map('intval', explode(':', $start));
        [$eh, $em] = array_map('intval', explode(':', $end));
        return ($eh * 60 + $em) - ($sh * 60 + $sm);
    }
}
