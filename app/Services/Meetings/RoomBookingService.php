<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingRoom;
use App\Models\MeetingRoomBooking;
use App\Services\Hr\Attendance\AttendanceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Room booking with conflict detection (Guide 6.2). Back-to-back meetings are allowed; any real overlap is refused. */
class RoomBookingService
{
    private function stamp(CarbonInterface $t): string { return $t->copy()->utc()->format('Y-m-d H:i:s'); }

    /** Active bookings of the room that overlap the period, optionally ignoring one meeting (its own booking while it is being moved). */
    public function conflicts(string $roomId, CarbonInterface $start, CarbonInterface $end, ?string $ignoreMeetingId = null): Collection
    {
        return MeetingRoomBooking::where('room_id', $roomId)->whereNull('released_at')
            ->where('starts_at', '<', $this->stamp($end))->where('ends_at', '>', $this->stamp($start))
            ->when($ignoreMeetingId, fn ($q) => $q->where('meeting_id', '!=', $ignoreMeetingId))
            ->orderBy('starts_at')->get();
    }

    /**
     * Book the meeting's room for its period. Call inside a transaction: the room row is locked first, so two people booking
     * the same room at the same moment are served one after the other and the second one sees the first booking.
     */
    public function book(Meeting $m): void
    {
        if (!$m->room_id) {
            return;
        }
        $room = MeetingRoom::whereKey($m->room_id)->lockForUpdate()->first();
        if (!$room || !$room->is_active) {
            throw ValidationException::withMessages(['room_id' => [__('meetings.room_unavailable')]]);
        }
        $clash = $this->conflicts($room->id, $m->starts_at, $m->ends_at, $m->id)->first();
        if ($clash) {
            // The message names the busy period only: who holds the room (and why) may be confidential.
            $tz = AttendanceService::tz($m->organization_id);
            throw ValidationException::withMessages(['room_id' => [__('meetings.room_conflict', [
                'room' => $room->name, 'from' => $clash->starts_at->setTimezone($tz)->format('Y-m-d H:i'), 'to' => $clash->ends_at->setTimezone($tz)->format('H:i'),
            ])]]);
        }
        MeetingRoomBooking::create(['room_id' => $room->id, 'meeting_id' => $m->id, 'starts_at' => $m->starts_at, 'ends_at' => $m->ends_at]);
    }

    public function release(Meeting $m): void
    {
        MeetingRoomBooking::where('meeting_id', $m->id)->whereNull('released_at')->update(['released_at' => now()]);
    }

    /** Free/busy of one room over a period: the busy slots without titles (the room calendar shows who is allowed to know). */
    public function busy(string $roomId, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return $this->conflicts($roomId, $from, $to);
    }
}
