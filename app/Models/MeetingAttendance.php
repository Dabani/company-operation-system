<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingAttendance extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['arrived_at' => UtcDateTime::class, 'acknowledged_at' => UtcDateTime::class];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function invitation() { return $this->belongsTo(MeetingInvitation::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function method() { return $this->belongsTo(LookupValue::class, 'method_id'); }
    public function history() { return $this->hasMany(MeetingAttendanceHistory::class, 'attendance_id'); }

    public function displayName(): string { return $this->user?->name ?? (string) $this->guest_name; }
}
