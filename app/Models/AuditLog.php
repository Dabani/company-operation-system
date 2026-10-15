<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only: updates and deletes are refused at model level. */
class AuditLog extends Model
{
    use HasUlids;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit logs are append-only.'));
        static::deleting(fn () => throw new LogicException('Audit logs are append-only.'));
    }

    public function changes() { return $this->hasMany(AuditLogChange::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function outcome() { return $this->belongsTo(LookupValue::class, 'outcome_id'); }
}
