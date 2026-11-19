<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingIntervention extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['requested_at' => UtcDateTime::class, 'granted_at' => UtcDateTime::class, 'finished_at' => UtcDateTime::class];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function invitation() { return $this->belongsTo(MeetingInvitation::class, 'invitation_id'); }
    public function agendaItem() { return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id'); }
    public function kind() { return $this->belongsTo(LookupValue::class, 'kind_id'); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }

    public function displayName(): string { return $this->user?->name ?? (string) $this->invitation?->guest_name; }
}
