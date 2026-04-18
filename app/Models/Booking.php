<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    protected $fillable = [
        'player1_id', 'player2_id', 'player2_name_text', 'player2_confirmed_at',
        'booking_date', 'start_time', 'end_time', 'price', 'is_peak',
        'status', 'gcal_event_id',
        'stripe_payment_link_p1', 'stripe_payment_link_p2',
        'payment_status_p1', 'payment_status_p2',
        'reminders_sent',
    ];

    protected $casts = [
        'booking_date'         => 'date',
        'is_peak'              => 'boolean',
        'player2_confirmed_at' => 'datetime',
        'reminders_sent'       => 'array',
    ];

    public function player1() {
        return $this->belongsTo(User::class, 'player1_id');
    }

    public function player2() {
        return $this->belongsTo(User::class, 'player2_id');
    }

    public function invitations() {
        return $this->hasMany(MatchInvitation::class);
    }

    public function result() {
        return $this->hasOne(MatchResult::class);
    }
}
