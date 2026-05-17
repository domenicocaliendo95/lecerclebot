<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChatMessage extends Model
{
    use SoftDeletes;

    protected $fillable = ['thread_id', 'sender_id', 'type', 'content', 'attachment_path', 'reply_to_message_id'];

    public function thread() { return $this->belongsTo(ChatThread::class, 'thread_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function replyTo(){ return $this->belongsTo(ChatMessage::class, 'reply_to_message_id'); }
}
