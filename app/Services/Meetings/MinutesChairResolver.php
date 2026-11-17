<?php

namespace App\Services\Meetings;

use App\Models\ApprovalRequest;
use App\Models\ApprovalStep;
use App\Models\MeetingMinuteVersion;
use App\Models\User;
use Illuminate\Support\Collection;

/** Approval step type "meeting_chair": the chair of the meeting the minutes belong to. Unresolved (no chair, or the chair is the requester) -> the engine's top-authority fallback. */
class MinutesChairResolver
{
    public function users(ApprovalRequest $r, ApprovalStep $step): Collection
    {
        $v = MeetingMinuteVersion::with('minutes.meeting')->find($r->subject_id);
        $chair = $v?->minutes?->meeting?->chair_id;
        return $chair ? User::query()->whereKey($chair)->whereHas('status', fn ($s) => $s->where('code', 'active'))->get() : collect();
    }
}
