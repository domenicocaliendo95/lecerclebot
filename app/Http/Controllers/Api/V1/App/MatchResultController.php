<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MatchResult;
use App\Services\EloService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MatchResultController extends Controller
{
    public function __construct(private EloService $elo) {}

    /**
     * GET /v1/app/match-results/pending
     * Booking giocati ma non ancora registrati da me (sia tracked che non).
     */
    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        $bookings = Booking::query()
            ->where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)->orWhere('player2_id', $user->id);
            })
            ->whereIn('status', ['confirmed', 'pending_match'])
            ->whereRaw("CONCAT(booking_date, ' ', end_time) < NOW()")
            ->where(function ($q) {
                $q->whereNotNull('player2_id')->orWhereNotNull('player2_name_text');
            })
            ->with(['player1:id,name,avatar_path,elo_rating', 'player2:id,name,avatar_path,elo_rating'])
            ->orderByDesc('booking_date')->orderByDesc('start_time')
            ->limit(50)
            ->get();

        $items = $bookings->filter(function ($b) use ($user) {
            $result = MatchResult::where('booking_id', $b->id)->first();
            if (!$result) return true;
            $isP1 = $b->player1_id === $user->id;
            return $isP1 ? !$result->player1_confirmed : !$result->player2_confirmed;
        })->map(fn($b) => $this->serializeBooking($b, $user->id))->values();

        return response()->json(['data' => $items]);
    }

    /**
     * GET /v1/app/match-results
     * Storico risultati finalizzati dell'utente.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $results = MatchResult::query()
            ->whereHas('booking', function ($q) use ($user) {
                $q->where(function ($w) use ($user) {
                    $w->where('player1_id', $user->id)->orWhere('player2_id', $user->id);
                });
            })
            ->whereNotNull('confirmed_at')
            ->with(['booking.player1:id,name,avatar_path', 'booking.player2:id,name,avatar_path', 'winner:id,name'])
            ->orderByDesc('confirmed_at')
            ->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => collect($results->items())->map(fn($r) => $this->serializeResult($r, $user->id))->all(),
            'meta' => [
                'current_page' => $results->currentPage(),
                'last_page'    => $results->lastPage(),
                'total'        => $results->total(),
            ],
        ]);
    }

    /**
     * POST /v1/app/match-results/{bookingId}
     * Body: { outcome: 'won'|'lost'|'not_played', score?: string }
     */
    public function submit(Request $request, int $bookingId): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'outcome' => 'required|in:won,lost,not_played',
            'score'   => 'nullable|string|max:30',
        ]);

        $booking = Booking::with(['player1', 'player2'])
            ->where('id', $bookingId)
            ->where(function ($q) use ($user) {
                $q->where('player1_id', $user->id)->orWhere('player2_id', $user->id);
            })
            ->first();

        if (!$booking) {
            return response()->json(['error' => ['code' => 'not_found']], 404);
        }

        $isP1 = $booking->player1_id === $user->id;
        $isTracked = $booking->player2_id !== null && $booking->player2_confirmed_at !== null;

        return DB::transaction(function () use ($booking, $user, $isP1, $isTracked, $data) {
            $result = MatchResult::firstOrCreate(
                ['booking_id' => $booking->id],
                ['winner_id'  => null, 'score' => null, 'player1_confirmed' => false, 'player2_confirmed' => false],
            );

            if ($data['outcome'] === 'not_played') {
                $result->update([
                    'winner_id'         => null,
                    'score'             => null,
                    'player1_confirmed' => true,
                    'player2_confirmed' => true,
                    'confirmed_at'      => now(),
                ]);
                $booking->update(['status' => 'cancelled']);
                return response()->json(['data' => $this->serializeResult($result->fresh(['booking.player1', 'booking.player2']), $user->id)]);
            }

            $myWinnerId = $data['outcome'] === 'won'
                ? $user->id
                : ($isP1 ? $booking->player2_id : $booking->player1_id);

            $updates = [
                'score' => $data['score'] ?? $result->score,
            ];

            if ($isP1) {
                $updates['player1_confirmed'] = true;
            } else {
                $updates['player2_confirmed'] = true;
            }

            // Se altro player ha già confermato un winner diverso → discordanza
            if ($result->winner_id !== null && $result->winner_id !== $myWinnerId) {
                // Discordanza: mantieni i confirm ma non finalizzare. Admin verifica.
                $result->update($updates);
                Log::warning('Match result discordance', ['booking_id' => $booking->id, 'p1_says' => $result->winner_id, 'me_says' => $myWinnerId]);
                return response()->json([
                    'data'    => $this->serializeResult($result->fresh(['booking.player1', 'booking.player2']), $user->id),
                    'warning' => 'discordance',
                ]);
            }

            $updates['winner_id'] = $myWinnerId;
            $result->update($updates);
            $result->refresh();

            // Se ELO-tracked AND entrambi confermati → finalizza (calcola ELO)
            if ($isTracked && $result->player1_confirmed && $result->player2_confirmed && $result->winner_id) {
                $this->elo->processResult($result);
            } elseif (!$isTracked && ($result->player1_confirmed || $result->player2_confirmed)) {
                // Untracked: basta una conferma per chiudere come completed (no ELO)
                $booking->update(['status' => 'completed']);
                $result->update(['confirmed_at' => now()]);
            }

            return response()->json(['data' => $this->serializeResult($result->fresh(['booking.player1', 'booking.player2']), $user->id)]);
        });
    }

    // ── serializers ──────────────────────────────────────────────────────

    private function serializeBooking(Booking $b, int $viewerId): array
    {
        $isP1 = $b->player1_id === $viewerId;
        $opp = $isP1 ? $b->player2 : $b->player1;
        return [
            'booking_id'         => $b->id,
            'date'               => $b->booking_date,
            'start_time'         => substr($b->start_time, 0, 5),
            'end_time'           => substr($b->end_time, 0, 5),
            'opponent' => $opp ? [
                'id'         => $opp->id,
                'name'       => $opp->name,
                'avatar_url' => $opp->avatar_path ? asset('storage/' . $opp->avatar_path) : null,
            ] : null,
            'opponent_name_text' => $b->player2_name_text,
            'is_tracked'         => $b->player2_id !== null && $b->player2_confirmed_at !== null,
        ];
    }

    private function serializeResult(MatchResult $r, int $viewerId): array
    {
        $b = $r->booking;
        $isP1 = $b->player1_id === $viewerId;
        $myConfirmed = $isP1 ? $r->player1_confirmed : $r->player2_confirmed;
        $oppConfirmed = $isP1 ? $r->player2_confirmed : $r->player1_confirmed;
        $myDelta = $r->confirmed_at && $r->player1_elo_after !== null
            ? ($isP1 ? $r->player1_elo_after - $r->player1_elo_before : $r->player2_elo_after - $r->player2_elo_before)
            : null;

        return [
            'booking_id'        => $b->id,
            'date'              => $b->booking_date,
            'opponent_name'     => $isP1 ? ($b->player2?->name ?? $b->player2_name_text) : $b->player1?->name,
            'winner_id'         => $r->winner_id,
            'i_won'             => $r->winner_id !== null && $r->winner_id === $viewerId,
            'score'             => $r->score,
            'my_confirmed'      => (bool) $myConfirmed,
            'opponent_confirmed'=> (bool) $oppConfirmed,
            'finalized'         => $r->confirmed_at !== null,
            'elo_delta'         => $myDelta,
        ];
    }
}
