<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class EventRegistration extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $hidden = ['ticket_token'];
    protected $casts = ['registered_at' => UtcDateTime::class, 'cancelled_at' => UtcDateTime::class];

    public function event() { return $this->belongsTo(Event::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function attendance() { return $this->hasOne(EventAttendance::class, 'registration_id'); }

    public function displayName(): string { return $this->user?->name ?? (string) $this->guest_name; }
}
