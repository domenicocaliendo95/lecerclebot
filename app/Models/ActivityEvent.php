<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityEvent extends Model
{
    public $timestamps = false;
    protected $fillable = ['club_id', 'user_id', 'type', 'payload', 'visibility', 'created_at'];
    protected $casts = [
        'payload'    => 'array',
        'created_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
