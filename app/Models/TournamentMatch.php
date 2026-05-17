<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentMatch extends Model
{
    protected $fillable = [
        'tournament_id', 'round', 'bracket_position', 'group_id',
        'player1_id', 'player2_id', 'winner_id', 'score',
        'booking_id', 'scheduled_at', 'played_at', 'status',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'played_at'    => 'datetime',
    ];

    public function tournament() { return $this->belongsTo(Tournament::class); }
    public function player1()    { return $this->belongsTo(User::class, 'player1_id'); }
    public function player2()    { return $this->belongsTo(User::class, 'player2_id'); }
    public function winner()     { return $this->belongsTo(User::class, 'winner_id'); }
    public function booking()    { return $this->belongsTo(Booking::class); }
}
