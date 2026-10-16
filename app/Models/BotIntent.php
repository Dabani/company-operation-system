<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class BotIntent extends Model
{
    use HasUlids;

    protected $guarded = [];
    protected $casts = ['is_active' => 'boolean'];

    public function action() { return $this->belongsTo(LookupValue::class, 'action_id'); }
    public function keywords() { return $this->hasMany(BotIntentKeyword::class, 'intent_id'); }
    public function responses() { return $this->hasMany(BotIntentResponse::class, 'intent_id'); }
}
