<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BotSession extends Model
{
    protected $fillable = [
        'phone',
        'state',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];
}
