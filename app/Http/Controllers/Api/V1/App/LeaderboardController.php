<?php

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardController extends Controller
{
    /**
     * GET /v1/app/leaderboard
     * Classifica ELO del circolo. Esclude admin e utenti senza phone.
     */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();

        $top = User::query()
            ->where('is_admin', false)
            ->whereNotNull('phone')
            ->whereNotNull('elo_rating')
            ->orderByDesc('elo_rating')
            ->orderByDesc('matches_played')
            ->limit(50)
            ->get(['id', 'name', 'avatar_path', 'elo_rating', 'matches_played', 'matches_won', 'fit_rating', 'self_level']);

        // Trova rank assoluto dell'utente loggato
        $myRank = User::where('is_admin', false)
            ->whereNotNull('phone')
            ->where(function ($w) use ($me) {
                $w->where('elo_rating', '>', $me->elo_rating ?? 0)
                  ->orWhere(function ($w2) use ($me) {
                      $w2->where('elo_rating', $me->elo_rating ?? 0)
                         ->where('matches_played', '>', $me->matches_played ?? 0);
                  });
            })
            ->count() + 1;

        return response()->json([
            'data' => $top->values()->map(function ($u, $i) use ($me) {
                return [
                    'rank'           => $i + 1,
                    'id'             => $u->id,
                    'name'           => $u->name,
                    'avatar_url'     => $u->avatar_path ? asset('storage/' . $u->avatar_path) : null,
                    'elo_rating'     => $u->elo_rating,
                    'matches_played' => $u->matches_played,
                    'matches_won'    => $u->matches_won,
                    'fit_rating'     => $u->fit_rating,
                    'is_me'          => $u->id === $me->id,
                ];
            })->all(),
            'me'   => [
                'rank'        => $myRank,
                'elo_rating'  => $me->elo_rating,
                'matches_played' => $me->matches_played,
                'matches_won' => $me->matches_won,
            ],
        ]);
    }
}
