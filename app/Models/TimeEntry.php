<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class TimeEntry extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['work_date' => 'date', 'started_at' => 'datetime', 'ended_at' => 'datetime', 'approved_at' => 'datetime', 'duration_seconds' => 'integer',
        'is_manual' => 'boolean', 'is_billable' => 'boolean', 'auto_stopped' => 'boolean'];

    /** Approved and invoiced entries are immutable (Guide 4.6): only the status may advance (approved -> invoiced). Corrections are adjustment entries. */
    protected static function booted(): void
    {
        static::updating(function (self $e) {
            $was = $e->getOriginal('status_id');
            if (in_array($was, self::lockedStatusIds(), true)) {
                $allowed = ['status_id', 'updated_at'];
                if (array_diff(array_keys($e->getDirty()), $allowed)) {
                    throw new LogicException(__('time.entry.locked'));
                }
            }
        });
        static::deleting(function (self $e) {
            if (in_array($e->status_id, self::lockedStatusIds(), true) || $e->timesheet_id) {
                throw new LogicException(__('time.entry.locked'));
            }
        });
    }

    /** @return string[] */
    public static function lockedStatusIds(): array
    {
        return [\App\Support\Lookup::id('time_entry_status', 'approved'), \App\Support\Lookup::id('time_entry_status', 'invoiced')];
    }

    public function project() { return $this->belongsTo(Project::class); }
    public function task() { return $this->belongsTo(Task::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function segments() { return $this->hasMany(TimeEntrySegment::class)->orderBy('started_at'); }
}
