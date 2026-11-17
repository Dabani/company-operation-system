<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Support\BelongsToOrganization;
use App\Support\Immutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** What the schedule was before a change. Rows are never edited or removed. */
class MeetingScheduleHistory extends Model
{
    use HasUlids, BelongsToOrganization, Immutable;

    protected $table = 'meeting_schedule_history';
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['old_starts_at' => UtcDateTime::class, 'old_ends_at' => UtcDateTime::class, 'created_at' => 'datetime'];

    public function changedBy() { return $this->belongsTo(User::class, 'changed_by'); }
    public function oldRoom() { return $this->belongsTo(MeetingRoom::class, 'old_room_id'); }
}
