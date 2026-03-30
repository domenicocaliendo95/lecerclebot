<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Feedback extends Model
{
    protected $table = 'feedbacks';

    protected $fillable = [
        'user_id',
        'booking_id',
        'type',
        'rating',
        'content',
        'metadata',
        'is_read',
    ];

    protected $casts = [
        'content'  => 'array',
        'metadata' => 'array',
        'is_read'  => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }
}
