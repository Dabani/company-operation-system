<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingAttendanceHistory;
use App\Models\MeetingInvitation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Expected list, secretary check-in, self check-in by link or QR, corrections after the event, acknowledgement (Guide 6.5). */
class MeetingAttendanceService
{
    private function fail(string $field, string $key, array $params = []): never
    {
        throw ValidationException::withMessages([$field => [__("meetings.$key", $params)]]);
    }

    private function sid(string $code): string { return Lookup::id('meeting_attendance_status', $code); }
    private function mid(string $code): string { return Lookup::id('meeting_checkin_method', $code); }

    /** One "expected" line for every invitation that has none yet. Safe to run again (late invitees are added, nothing is reset). */
    public function generate(Meeting $m, ?User $by = null): int
    {
        $made = 0;
        foreach (MeetingInvitation::where('meeting_id', $m->id)->get() as $inv) {
            $row = MeetingAttendance::firstOrCreate(['meeting_id' => $m->id, 'invitation_id' => $inv->id], [
                'user_id' => $inv->user_id, 'guest_name' => $inv->user_id ? null : $inv->guest_name, 'status_id' => $this->sid('expected'), 'recorded_by' => $by?->id]);
            $made += $row->wasRecentlyCreated ? 1 : 0;
        }
        return $made;
    }

    /** Set a person's status. Before the end it is ordinary check-in; after it, a correction that needs a reason. */
    public function record(MeetingAttendance $a, string $status, User $by, ?string $note = null, ?string $reason = null, string $method = 'secretary'): MeetingAttendance
    {
        if (!in_array($status, AttendanceRules::STATUSES, true)) {
            $this->fail('status', 'bad_attendance_status');
        }
        return DB::transaction(function () use ($a, $status, $by, $note, $reason, $method) {
            $a = MeetingAttendance::lockForUpdate()->with(['status', 'meeting.status'])->findOrFail($a->id);
            $meetingStatus = $a->meeting->status->code;
            if (!AttendanceRules::isOpen($meetingStatus)) {
                $this->fail('status', 'attendance_not_open');
            }
            $correction = AttendanceRules::isCorrection($meetingStatus);
            $from = $a->status->code;
            $fromId = $a->status_id;
            $reason = $reason !== null ? trim($reason) : null;
            if ($from === $status && $note === ($a->note)) {
                return $a;
            }
            if ($correction && $from !== $status && (!$reason || mb_strlen($reason) < 3)) {
                $this->fail('reason', 'reason_required');
            }
            $attrs = ['status_id' => $this->sid($status), 'note' => $note ?? $a->note, 'recorded_by' => $by->id];
            if (AttendanceRules::attended($status)) {
                $attrs['arrived_at'] = $a->arrived_at ?? now();
                $attrs['method_id'] = $a->method_id ?? $this->mid($method);
            } else {
                $attrs['arrived_at'] = null;
            }
            $a->update($attrs);
            if ($from !== $status) {
                MeetingAttendanceHistory::create(['attendance_id' => $a->id, 'meeting_id' => $a->meeting_id, 'from_status_id' => $fromId,
                    'to_status_id' => $this->sid($status), 'is_correction' => $correction, 'reason' => $reason, 'changed_by' => $by->id, 'created_at' => now()]);
                AuditLogger::log($correction ? 'meeting.attendance_corrected' : 'meeting.attendance_recorded', $a->meeting, ['status' => $from], ['status' => $status, 'person' => $a->displayName()], $reason);
            }
            return $a->refresh();
        });
    }

    /** A person who came without being invited (a member of the organization, or a named guest). */
    public function walkIn(Meeting $m, ?string $userId, ?string $guestName, User $by, ?string $reason = null): MeetingAttendance
    {
        $status = $m->status->code;
        if (!AttendanceRules::isOpen($status)) {
            $this->fail('status', 'attendance_not_open');
        }
        if (AttendanceRules::isCorrection($status) && (!$reason || mb_strlen(trim($reason)) < 3)) {
            $this->fail('reason', 'reason_required');
        }
        $a = $userId
            ? MeetingAttendance::firstOrNew(['meeting_id' => $m->id, 'user_id' => $userId])
            : new MeetingAttendance(['meeting_id' => $m->id, 'guest_name' => $guestName]);
        if ($a->exists) {
            return $this->record($a, 'present', $by, null, $reason);          // already on the sheet: just mark them present
        }
        $a->fill(['status_id' => $this->sid('present'), 'arrived_at' => now(), 'method_id' => $this->mid('walk_in'), 'recorded_by' => $by->id])->save();
        MeetingAttendanceHistory::create(['attendance_id' => $a->id, 'meeting_id' => $m->id, 'from_status_id' => null, 'to_status_id' => $this->sid('present'),
            'is_correction' => AttendanceRules::isCorrection($status), 'reason' => $reason, 'changed_by' => $by->id, 'created_at' => now()]);
        AuditLogger::log('meeting.attendance_walk_in', $m, [], ['person' => $a->displayName()], $reason);
        return $a;
    }

    /** The participant checks themselves in (link or QR). Only while the meeting is in progress; late is worked out from the start time. */
    public function selfCheckIn(Meeting $m, User $u, bool $online = false): MeetingAttendance
    {
        if ($m->status->code !== 'in_progress') {
            $this->fail('status', 'checkin_closed');
        }
        $inv = MeetingInvitation::where('meeting_id', $m->id)->where('user_id', $u->id)->first();
        abort_unless($inv, 404);                                              // not on the list: the meeting does not exist for them
        $this->generate($m);
        $a = MeetingAttendance::where('meeting_id', $m->id)->where('invitation_id', $inv->id)->firstOrFail();
        if (AttendanceRules::attended($a->status()->value('code') ?? '')) {
            return $a;                                                        // checking in twice changes nothing
        }
        $grace = (int) SettingsService::get($m->organization_id, 'meeting.late_after_minutes');
        $status = $online ? 'online' : AttendanceRules::classify(now(), $m->starts_at, $grace);
        return $this->record($a, $status, $u, null, null, 'self');
    }

    /** Participants confirm their own line (the "signature" of the sheet). */
    public function acknowledge(Meeting $m, User $u): MeetingAttendance
    {
        $a = MeetingAttendance::where('meeting_id', $m->id)->where('user_id', $u->id)->first();
        abort_unless($a, 404);
        if (!AttendanceRules::attended($a->status()->value('code') ?? '')) {
            $this->fail('status', 'ack_not_attended');
        }
        if (!$a->acknowledged_at) {
            $a->update(['acknowledged_at' => now()]);
            AuditLogger::log('meeting.attendance_acknowledged', $m, [], ['person' => $u->name]);
        }
        return $a->refresh();
    }

    /** A new check-in token: the old link and QR code stop working (use it when the link was shared too widely). Check-in itself only works while the meeting is in progress. */
    public function rotateCheckinLink(Meeting $m, User $by): string
    {
        $m->update(['checkin_token' => Str::random(40)]);
        AuditLogger::log('meeting.checkin_rotated', $m, [], []);
        return $m->checkin_token;
    }
}
