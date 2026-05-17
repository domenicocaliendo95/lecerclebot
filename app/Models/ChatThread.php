<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatThread extends Model
{
    use SoftDeletes;

    protected $fillable = ['club_id', 'type', 'name', 'created_by', 'last_message_at'];
    protected $casts = ['last_message_at' => 'datetime'];

    public function participants() { return $this->hasMany(ChatThreadParticipant::class, 'thread_id'); }
    public function messages()     { return $this->hasMany(ChatMessage::class, 'thread_id'); }
    public function creator()      { return $this->belongsTo(User::class, 'created_by'); }
}
