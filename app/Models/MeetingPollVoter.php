<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingPollVoter extends Model
{
    use HasUlids, BelongsToOrganization;

    public $timestamps = false;
    protected $guarded = [];

    public function user() { return $this->belongsTo(User::class); }
    public function invitation() { return $this->belongsTo(MeetingInvitation::class, 'invitation_id'); }
    public function votes() { return $this->hasMany(MeetingPollVote::class, 'voter_id'); }

    public function displayName(): string { return $this->user?->name ?? (string) $this->invitation?->guest_name; }
}
