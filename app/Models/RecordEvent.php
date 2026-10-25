<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class RecordEvent extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Record events are append-only.'));
        static::deleting(fn () => throw new LogicException('Record events are append-only.'));
    }

    public function user() { return $this->belongsTo(User::class); }
    public function type() { return $this->belongsTo(LookupValue::class, 'event_type_id'); }
}
