<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class MeetingRoomBooking extends Model
{
    use HasUlids, BelongsToOrganization;

    protected $guarded = [];
    protected $casts = ['starts_at' => UtcDateTime::class, 'ends_at' => UtcDateTime::class, 'released_at' => UtcDateTime::class];

    public function room() { return $this->belongsTo(MeetingRoom::class, 'room_id'); }
    public function meeting() { return $this->belongsTo(Meeting::class); }
}
