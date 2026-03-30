<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EloHistory extends Model
{
    protected $table = 'elo_history';

    protected $fillable = [
        'user_id',
        'match_result_id',
        'elo_before',
        'elo_after',
        'delta',
        'reason',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function matchResult()
    {
        return $this->belongsTo(MatchResult::class);
    }
}
