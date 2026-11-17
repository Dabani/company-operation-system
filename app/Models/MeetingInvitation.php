<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingInvitation extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $hidden = ['token'];       // never serialised by accident: the token is the guest's credential
    protected $casts = ['proposed_starts_at' => UtcDateTime::class, 'responded_at' => UtcDateTime::class, 'invited_at' => UtcDateTime::class];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function response() { return $this->belongsTo(LookupValue::class, 'response_id'); }
    public function department() { return $this->belongsTo(Department::class, 'via_department_id'); }

    public function displayName(): string { return $this->user?->name ?? (string) $this->guest_name; }
    public function email(): ?string { return $this->user?->email ?? $this->guest_email; }
}
