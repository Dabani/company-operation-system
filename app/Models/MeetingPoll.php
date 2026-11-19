<?php

namespace App\Models;

use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use App\Casts\UtcDateTime;

class MeetingPoll extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['multiple' => 'boolean', 'anonymous' => 'boolean', 'guests_vote' => 'boolean', 'max_choices' => 'integer', 'closed_at' => UtcDateTime::class];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function status() { return $this->belongsTo(LookupValue::class, 'status_id'); }
    public function agendaItem() { return $this->belongsTo(MeetingAgendaItem::class, 'agenda_item_id'); }
    public function options() { return $this->hasMany(MeetingPollOption::class, 'poll_id')->orderBy('position'); }
    public function voters() { return $this->hasMany(MeetingPollVoter::class, 'poll_id'); }
    public function votes() { return $this->hasMany(MeetingPollVote::class, 'poll_id'); }
}
