<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventParticipant extends Model
{
    protected $fillable = [
        'event_id', 'user_id', 'status', 'plus_ones_count', 'plus_ones_names',
        'payment_status', 'notes', 'registered_at', 'checked_in_at',
    ];

    protected $casts = [
        'plus_ones_names' => 'array',
        'registered_at'   => 'datetime',
        'checked_in_at'   => 'datetime',
    ];

    public function event() { return $this->belongsTo(Event::class); }
    public function user()  { return $this->belongsTo(User::class); }
}
