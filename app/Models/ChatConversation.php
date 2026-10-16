<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ChatConversation extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'chat_conversations';
    protected $guarded = [];
    protected $casts = ['last_message_at' => 'datetime'];

    public function user() { return $this->belongsTo(User::class); }
    public function agent() { return $this->belongsTo(User::class, 'agent_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function messages() { return $this->hasMany(ChatMessage::class, 'conversation_id'); }
}
