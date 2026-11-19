<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingOnlineSession extends Model
{
    use HasUlids, BelongsToOrganization;

    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['moderator' => 'boolean', 'joined_at' => UtcDateTime::class, 'left_at' => UtcDateTime::class];

    public function meeting() { return $this->belongsTo(Meeting::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function invitation() { return $this->belongsTo(MeetingInvitation::class, 'invitation_id'); }
}
