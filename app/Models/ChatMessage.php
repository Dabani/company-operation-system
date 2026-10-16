<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    public function senderType() { return $this->belongsTo(LookupValue::class, 'sender_type_id'); }
    public function sender() { return $this->belongsTo(User::class, 'sender_id'); }
    public function conversation() { return $this->belongsTo(ChatConversation::class, 'conversation_id')->withoutGlobalScopes(); }
}
