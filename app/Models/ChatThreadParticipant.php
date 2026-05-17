<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatThreadParticipant extends Model
{
    protected $fillable = ['thread_id', 'user_id', 'role', 'joined_at', 'last_read_at', 'muted_until'];
    protected $casts = [
        'joined_at'    => 'datetime',
        'last_read_at' => 'datetime',
        'muted_until'  => 'datetime',
    ];

    public function thread() { return $this->belongsTo(ChatThread::class, 'thread_id'); }
    public function user()   { return $this->belongsTo(User::class); }
}
