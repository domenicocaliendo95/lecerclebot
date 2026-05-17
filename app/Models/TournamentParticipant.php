<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TournamentParticipant extends Model
{
    protected $fillable = [
        'tournament_id', 'user_id', 'status', 'seed', 'payment_status', 'registered_at',
    ];

    protected $casts = ['registered_at' => 'datetime'];

    public function tournament() { return $this->belongsTo(Tournament::class); }
    public function user()       { return $this->belongsTo(User::class); }
}
