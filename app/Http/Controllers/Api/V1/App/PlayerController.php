<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\MatchResult;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlayerController extends Controller
{
    /**
     * GET /v1/app/players/{id}
     * Profilo pubblico filtrato per privacy.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $viewer = $request->user();

        $player = User::where('id', $id)
            ->where('is_admin', false)
            ->whereNotNull('phone')
            ->first();

        if (!$player) {
            return response()->json(['error' => ['code' => 'not_found']], 404);
        }

        // Privacy filter
        if ($player->privacy_profile === 'friends_only' && $player->id !== $viewer->id) {
            // TODO: verifica amicizia. Per ora friends_only = solo me.
            return response()->json(['error' => ['code' => 'private']], 403);
        }

        // Head to head con viewer
        $h2h = $this->headToHead($viewer->id, $player->id);

        return response()->json([
            'data' => [
                'id'             => $player->id,
                'name'           => $player->name,
                'bio'            => $player->bio,
                'avatar_url'     => $player->avatar_path ? asset('storage/' . $player->avatar_path) : null,
                'elo_rating'     => $player->elo_rating,
                'matches_played' => $player->matches_played,
                'matches_won'    => $player->matches_won,
                'fit_rating'     => $player->fit_rating,
                'self_level'     => $player->self_level,
                'is_me'          => $player->id === $viewer->id,
                'head_to_head'   => $h2h,
            ],
        ]);
    }

    /**
     * GET /v1/app/players/{id}/recent-matches
     * Ultime 5 partite finalizzate del giocatore.
     */
    public function recentMatches(Request $request, int $id): JsonResponse
    {
        $viewer = $request->user();

        $results = MatchResult::query()
            ->whereNotNull('confirmed_at')
            ->whereHas('booking', function ($q) use ($id) {
                $q->where(function ($w) use ($id) {
                    $w->where('player1_id', $id)->orWhere('player2_id', $id);
                });
            })
            ->with(['booking.player1:id,name,avatar_path', 'booking.player2:id,name,avatar_path'])
            ->orderByDesc('confirmed_at')
            ->limit(5)
            ->get();

        $items = $results->map(function ($r) use ($id) {
            $b = $r->booking;
            $isP1 = $b->player1_id === $id;
            $oppName = $isP1 ? $b->player2?->name : $b->player1?->name;
            $oppAvatar = $isP1 ? $b->player2?->avatar_path : $b->player1?->avatar_path;
            $delta = $isP1 ? ($r->player1_elo_after - $r->player1_elo_before) : ($r->player2_elo_after - $r->player2_elo_before);

            return [
                'date'          => $b->booking_date instanceof \Illuminate\Support\Carbon ? $b->booking_date->format('Y-m-d') : (string) $b->booking_date,
                'opponent_name' => $oppName,
                'opponent_avatar_url' => $oppAvatar ? asset('storage/' . $oppAvatar) : null,
                'won'           => $r->winner_id === $id,
                'score'         => $r->score,
                'elo_delta'     => $delta,
            ];
        });

        return response()->json(['data' => $items]);
    }

    private function headToHead(int $viewerId, int $otherId): array
    {
        if ($viewerId === $otherId) {
            return ['played' => 0, 'me_wins' => 0, 'other_wins' => 0];
        }

        $results = MatchResult::query()
            ->whereNotNull('confirmed_at')
            ->whereNotNull('winner_id')
            ->whereHas('booking', function ($q) use ($viewerId, $otherId) {
                $q->where(function ($w) use ($viewerId, $otherId) {
                    $w->whereIn('player1_id', [$viewerId, $otherId])
                      ->whereIn('player2_id', [$viewerId, $otherId]);
                });
            })
            ->get();

        $meWins = $results->where('winner_id', $viewerId)->count();
        $otherWins = $results->where('winner_id', $otherId)->count();

        return [
            'played'     => $results->count(),
            'me_wins'    => $meWins,
            'other_wins' => $otherWins,
        ];
    }
}
