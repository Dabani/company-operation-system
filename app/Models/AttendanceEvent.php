<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A raw clock event. Append-only: it is never edited or deleted (corrections are separate, approved records). */
class AttendanceEvent extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $table = 'hr_attendance_events';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['occurred_at' => UtcDateTime::class, 'created_at' => UtcDateTime::class];

    protected static function booted(): void
    {
        static::creating(fn (self $m) => $m->created_at = $m->created_at ?? now());
        static::updating(fn () => throw new LogicException('Clock events are append-only.'));
        static::deleting(fn () => throw new LogicException('Clock events are never deleted.'));
    }

    public function employee() { return $this->belongsTo(Employee::class, 'employee_id'); }
}
