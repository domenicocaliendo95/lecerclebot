<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, SoftDeletes;

    protected $fillable = [
        'name', 'email', 'password',
        'is_admin',
        'phone', 'age', 'birthdate', 'is_fit',
        'fit_rating', 'self_level', 'elo_rating',
        'matches_played', 'matches_won',
        'is_elo_established', 'preferred_slots',
        // App fields
        'avatar_path', 'bio', 'last_seen_at', 'app_onboarded_at',
        'notification_preferences', 'privacy_profile', 'show_in_matchmaking',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at'        => 'datetime',
        'birthdate'                => 'date',
        'is_fit'                   => 'boolean',
        'is_admin'                 => 'boolean',
        'is_elo_established'       => 'boolean',
        'preferred_slots'          => 'array',
        'password'                 => 'hashed',
        'last_seen_at'             => 'datetime',
        'app_onboarded_at'         => 'datetime',
        'notification_preferences' => 'array',
        'show_in_matchmaking'      => 'boolean',
    ];

    // ── Relazioni esistenti (bot) ────────────────────────────────────────
    public function bookingsAsPlayer1() {
        return $this->hasMany(Booking::class, 'player1_id');
    }

    public function bookingsAsPlayer2() {
        return $this->hasMany(Booking::class, 'player2_id');
    }

    public function invitations() {
        return $this->hasMany(MatchInvitation::class, 'receiver_id');
    }

    // ── Nuove relazioni (app) ────────────────────────────────────────────
    public function deviceTokens() {
        return $this->hasMany(DeviceToken::class);
    }

    public function friendRequestsSent() {
        return $this->hasMany(Friendship::class, 'requester_id');
    }

    public function friendRequestsReceived() {
        return $this->hasMany(Friendship::class, 'addressee_id');
    }

    public function activityEvents() {
        return $this->hasMany(ActivityEvent::class);
    }

    public function chatThreadParticipations() {
        return $this->hasMany(ChatThreadParticipant::class);
    }

    public function sentMessages() {
        return $this->hasMany(ChatMessage::class, 'sender_id');
    }

    public function tournamentParticipations() {
        return $this->hasMany(TournamentParticipant::class);
    }

    public function eventParticipations() {
        return $this->hasMany(EventParticipant::class);
    }
}
