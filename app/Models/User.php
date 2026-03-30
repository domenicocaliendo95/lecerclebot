<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password',
        'phone', 'age', 'birthdate', 'is_fit',
        'fit_rating', 'self_level', 'elo_rating',
        'matches_played', 'matches_won',
        'is_elo_established', 'preferred_slots',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'  => 'datetime',
        'birthdate'          => 'date',
        'is_fit'             => 'boolean',
        'is_elo_established' => 'boolean',
        'preferred_slots'    => 'array',
        'password'           => 'hashed',
    ];

    public function bookingsAsPlayer1() {
        return $this->hasMany(Booking::class, 'player1_id');
    }

    public function bookingsAsPlayer2() {
        return $this->hasMany(Booking::class, 'player2_id');
    }

    public function invitations() {
        return $this->hasMany(MatchInvitation::class, 'receiver_id');
    }
}
