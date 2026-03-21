<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MatchInvitation extends Model
{
    protected $fillable = [
        'booking_id',
        'receiver_id',
        'status',
    ];

    public function booking() {
        return $this->belongsTo(Booking::class);
    }

    public function receiver() {
        return $this->belongsTo(User::class, 'receiver_id');
    }
}
