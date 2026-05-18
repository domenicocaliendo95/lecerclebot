<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MatchResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class FeedController extends Controller
{
    /**
     * GET /v1/app/feed
     * Attività recente del circolo: prenotazioni create + risultati partite.
     * MVP semplificato — niente activity_events ancora.
     */
    public function index(Request $request): JsonResponse
    {
        $cutoff = Carbon::now()->subDays(3);

        // Match results finalizzati
        $results = MatchResult::query()
            ->whereNotNull('confirmed_at')
            ->whereNotNull('winner_id')
            ->where('confirmed_at', '>=', $cutoff)
            ->with(['booking.player1:id,name,avatar_path', 'booking.player2:id,name,avatar_path', 'winner:id,name'])
            ->orderByDesc('confirmed_at')
            ->limit(15)
            ->get();

        // Bookings recentemente create (status confirmed)
        $bookings = Booking::query()
            ->where('status', 'confirmed')
            ->where('created_at', '>=', $cutoff)
            ->with(['player1:id,name,avatar_path', 'player2:id,name,avatar_path'])
            ->orderByDesc('created_at')
            ->limit(15)
            ->get();

        $items = collect();

        foreach ($results as $r) {
            $b = $r->booking;
            if (!$b) continue;
            $winner = $r->winner;
            $loserId = $r->winner_id === $b->player1_id ? $b->player2_id : $b->player1_id;
            $loser = $loserId === $b->player1_id ? $b->player1 : $b->player2;

            $items->push([
                'type'        => 'match_won',
                'happened_at' => $r->confirmed_at->toIso8601String(),
                'winner'      => $winner ? [
                    'id'   => $winner->id,
                    'name' => $winner->name,
                ] : null,
                'loser'       => $loser ? [
                    'id'   => $loser->id,
                    'name' => $loser->name,
                ] : null,
                'score'       => $r->score,
                'avatar_url'  => $winner && $winner->avatar_path ? asset('storage/' . $winner->avatar_path) : null,
            ]);
        }

        foreach ($bookings as $b) {
            $opponentName = $b->player2?->name ?? $b->player2_name_text;
            $items->push([
                'type'        => 'booking_created',
                'happened_at' => $b->created_at->toIso8601String(),
                'player'      => $b->player1 ? [
                    'id'   => $b->player1->id,
                    'name' => $b->player1->name,
                ] : null,
                'opponent_name' => $opponentName,
                'date'        => $b->booking_date instanceof Carbon ? $b->booking_date->format('Y-m-d') : (string) $b->booking_date,
                'start_time'  => substr((string) $b->start_time, 0, 5),
                'avatar_url'  => $b->player1 && $b->player1->avatar_path ? asset('storage/' . $b->player1->avatar_path) : null,
            ]);
        }

        $sorted = $items->sortByDesc('happened_at')->values()->take(20);

        return response()->json(['data' => $sorted]);
    }
}
