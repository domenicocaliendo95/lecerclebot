<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Friendship extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'club_id', 'requester_id', 'addressee_id', 'status', 'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function requester() { return $this->belongsTo(User::class, 'requester_id'); }
    public function addressee() { return $this->belongsTo(User::class, 'addressee_id'); }
}
