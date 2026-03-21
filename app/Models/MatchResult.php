<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchResult extends Model
{
    protected $fillable = [
        'booking_id', 'winner_id', 'score',
        'player1_elo_before', 'player1_elo_after',
        'player2_elo_before', 'player2_elo_after',
        'player1_confirmed', 'player2_confirmed', 'confirmed_at',
    ];

    protected $casts = [
        'player1_confirmed' => 'boolean',
        'player2_confirmed' => 'boolean',
        'confirmed_at'      => 'datetime',
    ];

    public function booking() {
        return $this->belongsTo(Booking::class);
    }

    public function winner() {
        return $this->belongsTo(User::class, 'winner_id');
    }
}
