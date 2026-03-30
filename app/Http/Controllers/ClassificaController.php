<?php

namespace App\Http\Controllers;

use App\Models\EloHistory;
use App\Models\User;
use Illuminate\Http\Request;

class ClassificaController extends Controller
{
    public function index()
    {
        $players = User::where('matches_played', '>', 0)
            ->orderByDesc('elo_rating')
            ->get(['id', 'name', 'elo_rating', 'matches_played', 'matches_won', 'is_fit', 'fit_rating', 'self_level']);

        return view('classifica.index', compact('players'));
    }

    public function show(int $id)
    {
        $player = User::where('matches_played', '>', 0)
            ->findOrFail($id);

        // Global rank
        $rank = User::where('matches_played', '>', 0)
            ->where('elo_rating', '>', $player->elo_rating)
            ->count() + 1;

        // ELO history for chart (latest 20 entries, chronological)
        $eloHistory = EloHistory::where('user_id', $id)
            ->orderBy('created_at')
            ->get(['elo_before', 'elo_after', 'delta', 'reason', 'created_at'])
            ->takeLast(20);

        // Recent completed matches
        $recentMatches = \App\Models\Booking::with(['player1:id,name', 'player2:id,name', 'result'])
            ->where(function ($q) use ($id) {
                $q->where('player1_id', $id)->orWhere('player2_id', $id);
            })
            ->where('status', 'confirmed')
            ->whereHas('result', fn($q) => $q->whereNotNull('winner_id'))
            ->orderByDesc('booking_date')
            ->limit(10)
            ->get();

        return view('classifica.show', compact('player', 'rank', 'eloHistory', 'recentMatches'));
    }
}
