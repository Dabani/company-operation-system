<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAgendaItem;
use App\Models\MeetingInvitation;
use App\Models\MeetingRecurrence;
use App\Models\MeetingScheduleHistory;
use App\Models\MeetingStatusHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Meeting lifecycle (Guide 6.1-6.4): plan -> invite -> agenda -> hold. Minutes, attendance and resolutions are in later bundles.
 * Every state change is transaction-safe, validated here (not in the screen), recorded in the status history and audited.
 */
class MeetingService
{
    public function __construct(private RoomBookingService $rooms, private InvitationService $invites, private MeetingNotices $notices) {}

    private function fail(string $field, string $key, array $params = []): never
    {
        throw ValidationException::withMessages([$field => [__("meetings.$key", $params)]]);
    }

    private function status(string $code): string { return Lookup::id('meeting_status', $code); }
    private function org(): string { return app(OrganizationContext::class)->organizationId(); }

    private function history(Meeting $m, ?string $from, string $to, ?User $by, ?string $note = null): void
    {
        MeetingStatusHistory::create(['meeting_id' => $m->id, 'from_status_id' => $from, 'to_status_id' => $to, 'note' => $note, 'changed_by' => $by?->id, 'created_at' => now()]);
    }

    private function parse(string $iso): CarbonImmutable { return CarbonImmutable::parse($iso)->utc(); }

    // ---- create ------------------------------------------------------------------------------------------------------------
    /**
     * @param array $d validated input (see MeetingController::rules)
     * @return array{meetings: Meeting[], warnings: array}
     */
    public function create(array $d, User $by): array
    {
        $start = $this->parse($d['starts_at']);
        $end = $this->parse($d['ends_at']);
        if (!$end->greaterThan($start) || $start->diffInHours($end) > 24 * 7) {
            $this->fail('ends_at', 'bad_period');
        }
        $duration = $end->diffInSeconds($start, true);
        $starts = [$start];
        if (!empty($d['recurrence'])) {
            $r = $d['recurrence'];
            try {
                $starts = RecurrenceExpander::expand($start, $r['frequency'], (int) ($r['interval'] ?? 1), $r['ends_on'] ?? null, isset($r['occurrences']) ? (int) $r['occurrences'] : null, AttendanceService::tz($this->org()));
            } catch (\InvalidArgumentException) {
                $this->fail('recurrence', 'bad_recurrence');
            }
        }

        return DB::transaction(function () use ($d, $by, $starts, $duration) {
            $series = null;
            if (count($starts) > 1) {
                $r = $d['recurrence'];
                $series = MeetingRecurrence::create(['frequency_id' => Lookup::id('meeting_frequency', $r['frequency']), 'interval_count' => (int) ($r['interval'] ?? 1),
                    'ends_on' => $r['ends_on'] ?? null, 'occurrences' => $r['occurrences'] ?? null, 'created_by' => $by->id]);
            }
            $made = [];
            foreach ($starts as $at) {
                $m = Meeting::create([
                    'title' => $d['title'], 'purpose' => $d['purpose'] ?? null, 'type_id' => $d['type_id'],
                    'status_id' => $this->status('draft'), 'agenda_status_id' => Lookup::id('agenda_status', 'draft'),
                    'confidentiality_id' => $d['confidentiality_id'] ?? Lookup::id('meeting_confidentiality', 'normal'),
                    'department_id' => $d['department_id'] ?? null, 'project_id' => $d['project_id'] ?? null,
                    'organizer_id' => $by->id, 'chair_id' => $d['chair_id'] ?? null, 'secretary_id' => $d['secretary_id'] ?? $by->id, 'facilitator_id' => $d['facilitator_id'] ?? null,
                    'starts_at' => $at, 'ends_at' => $at->addSeconds($duration), 'room_id' => $d['room_id'] ?? null, 'venue' => $d['venue'] ?? null, 'online_url' => $d['online_url'] ?? null,
                    'recurrence_id' => $series?->id, 'rsvp_deadline' => $d['rsvp_deadline'] ?? null, 'checkin_token' => Str::random(40),
                ]);
                $this->rooms->book($m);                                   // any occurrence in conflict stops the whole series
                $this->invites->ensureHosts($m);
                $this->invites->add($m, $d['participants'] ?? []);
                $this->history($m, null, $m->status_id, $by, 'created');
                AuditLogger::log('meeting.created', $m, [], ['code' => $m->code, 'starts_at' => $m->starts_at->toIso8601String(), 'series' => $series?->id]);
                $made[] = $m;
            }
            return ['meetings' => $made, 'warnings' => $this->invites->busyElsewhere($made[0])];
        });
    }

    // ---- change ------------------------------------------------------------------------------------------------------------
    private const TRACKED = ['title', 'purpose', 'type_id', 'confidentiality_id', 'department_id', 'project_id', 'chair_id', 'secretary_id', 'facilitator_id', 'room_id', 'venue', 'online_url', 'rsvp_deadline'];

    /** Reschedule or edit. After scheduling, a change of time, place or title raises the calendar sequence and tells the invitees. */
    public function update(Meeting $meeting, array $d, User $by): array
    {
        return DB::transaction(function () use ($meeting, $d, $by) {
            $m = Meeting::lockForUpdate()->findOrFail($meeting->id);
            $status = $m->status->code;
            if (!MeetingWorkflow::editable($status)) {
                $this->fail('status', 'not_editable');
            }
            $old = $m->only(self::TRACKED) + ['starts_at' => $m->starts_at->toIso8601String(), 'ends_at' => $m->ends_at->toIso8601String()];
            $oldStart = $m->starts_at; $oldEnd = $m->ends_at; $oldRoom = $m->room_id; $oldVenue = $m->venue;

            $attrs = array_intersect_key($d, array_flip(self::TRACKED));
            if (isset($d['starts_at']) || isset($d['ends_at'])) {
                $s = isset($d['starts_at']) ? $this->parse($d['starts_at']) : $m->starts_at;
                $e = isset($d['ends_at']) ? $this->parse($d['ends_at']) : $m->ends_at;
                if (!$e->greaterThan($s) || $s->diffInHours($e) > 24 * 7) {
                    $this->fail('ends_at', 'bad_period');
                }
                $attrs['starts_at'] = $s;
                $attrs['ends_at'] = $e;
            }
            $m->fill($attrs);

            $timeChanged = !$oldStart->equalTo($m->starts_at) || !$oldEnd->equalTo($m->ends_at);
            $placeChanged = $oldRoom !== $m->room_id || $oldVenue !== $m->venue || $m->isDirty('online_url');
            $visibleChange = $timeChanged || $placeChanged || $m->isDirty('title');

            if ($timeChanged || $oldRoom !== $m->room_id) {
                $this->rooms->release($m);
                $this->rooms->book($m);
            }
            if ($status === 'scheduled' && $visibleChange) {
                $m->ics_sequence = $m->ics_sequence + 1;
            }
            $m->save();
            $this->invites->ensureHosts($m);

            if ($status === 'scheduled' && ($timeChanged || $placeChanged)) {
                MeetingScheduleHistory::create(['meeting_id' => $m->id, 'old_starts_at' => $oldStart, 'old_ends_at' => $oldEnd, 'old_room_id' => $oldRoom, 'old_venue' => $oldVenue,
                    'reason' => $d['reason'] ?? null, 'changed_by' => $by->id, 'created_at' => now()]);
            }
            if ($timeChanged && $status === 'scheduled') {
                $this->invites->resetResponses($m);                       // a new time must be confirmed again
                $this->invites->ensureHosts($m);
            }
            $new = $m->only(self::TRACKED) + ['starts_at' => $m->starts_at->toIso8601String(), 'ends_at' => $m->ends_at->toIso8601String()];
            AuditLogger::log('meeting.updated', $m, $old, $new, $d['reason'] ?? null);

            if ($status === 'scheduled' && $visibleChange) {
                $this->notices->send($m, $m->invitations()->with('user.language')->get(), 'meeting_updated', ['reason' => (string) ($d['reason'] ?? '')]);
            }
            return ['meeting' => $m->refresh(), 'warnings' => $this->invites->busyElsewhere($m)];
        });
    }

    // ---- lifecycle ---------------------------------------------------------------------------------------------------------
    private function move(Meeting $meeting, string $to, User $by, ?string $note = null): Meeting
    {
        $m = Meeting::lockForUpdate()->with('status')->findOrFail($meeting->id);
        $from = $m->status->code;
        if (!MeetingWorkflow::allowed($from, $to)) {
            $this->fail('status', 'transition_not_allowed', ['from' => $from, 'to' => $to]);
        }
        $fromId = $m->status_id;
        $m->update(['status_id' => $this->status($to)]);
        $this->history($m, $fromId, $m->status_id, $by, $note);
        AuditLogger::log("meeting.$to", $m, ['status' => $from], ['status' => $to], $note);
        return $m->refresh();
    }

    /** Draft -> scheduled: invitations are sent. Needs a chair (they approve the minutes), a start in the future and at least one other person. */
    public function schedule(Meeting $meeting, User $by): Meeting
    {
        return DB::transaction(function () use ($meeting, $by) {
            $m = Meeting::with(['invitations'])->findOrFail($meeting->id);
            if (!$m->chair_id) {
                $this->fail('chair_id', 'chair_required');
            }
            if ($m->starts_at->isPast()) {
                $this->fail('starts_at', 'in_the_past');
            }
            if ($m->invitations->where('user_id', '!=', $m->organizer_id)->count() < 1) {
                $this->fail('participants', 'nobody_invited');
            }
            $m = $this->move($m, 'scheduled', $by);
            MeetingInvitation::where('meeting_id', $m->id)->update(['invited_at' => now()]);
            $this->notices->send($m, $m->invitations()->with('user.language')->get(), 'meeting_invited');
            return $m;
        });
    }

    public function cancel(Meeting $meeting, string $reason, User $by): Meeting
    {
        return DB::transaction(function () use ($meeting, $reason, $by) {
            $m = Meeting::with('status')->findOrFail($meeting->id);
            $wasScheduled = $m->status->code === 'scheduled';
            $m = $this->move($m, 'cancelled', $by, $reason);
            $m->update(['cancelled_at' => now(), 'cancel_reason' => $reason, 'ics_sequence' => $m->ics_sequence + 1]);
            $this->rooms->release($m);
            if ($wasScheduled) {
                $this->notices->send($m, $m->invitations()->with('user.language')->get(), 'meeting_cancelled', ['reason' => $reason]);
            }
            return $m->refresh();
        });
    }

    public function start(Meeting $meeting, User $by): Meeting
    {
        return DB::transaction(function () use ($meeting, $by) {
            $m = $this->move($meeting, 'in_progress', $by);
            $m->update(['started_at' => now()]);
            return $m->refresh();
        });
    }

    /** End of the meeting: from here the secretary records attendance (if not done) and drafts the minutes. */
    public function hold(Meeting $meeting, User $by): Meeting
    {
        return DB::transaction(function () use ($meeting, $by) {
            $m = $this->move($meeting, 'held', $by);
            $m->update(['ended_at' => now(), 'started_at' => $m->started_at ?? now()]);
            return $m->refresh();
        });
    }

    // ---- agenda ------------------------------------------------------------------------------------------------------------
    private function requireOpenAgenda(Meeting $m): void
    {
        if (!MeetingWorkflow::editable($m->status->code)) {
            $this->fail('status', 'not_editable');
        }
    }

    /** An approved agenda that is edited goes back to draft: the chair approves what will really be discussed. */
    private function touchAgenda(Meeting $m, User $by): void
    {
        if ($m->agendaStatus->code === 'approved') {
            $m->update(['agenda_status_id' => Lookup::id('agenda_status', 'draft'), 'agenda_approved_by' => null, 'agenda_approved_at' => null]);
            AuditLogger::log('meeting.agenda_reopened', $m, ['agenda' => 'approved'], ['agenda' => 'draft']);
        }
    }

    public function addAgendaItem(Meeting $meeting, array $d, User $by): MeetingAgendaItem
    {
        return DB::transaction(function () use ($meeting, $d, $by) {
            $m = Meeting::lockForUpdate()->with(['status', 'agendaStatus'])->findOrFail($meeting->id);
            $this->requireOpenAgenda($m);
            $position = (int) MeetingAgendaItem::where('meeting_id', $m->id)->max('position') + 1;
            $item = MeetingAgendaItem::create($d + ['meeting_id' => $m->id, 'position' => $position, 'priority_id' => $d['priority_id'] ?? Lookup::id('agenda_priority', 'normal')]);
            $this->touchAgenda($m, $by);
            AuditLogger::log('meeting.agenda_item_added', $item, [], ['meeting' => $m->code, 'title' => $item->title]);
            return $item;
        });
    }

    public function updateAgendaItem(MeetingAgendaItem $item, array $d, User $by): MeetingAgendaItem
    {
        return DB::transaction(function () use ($item, $d, $by) {
            $m = Meeting::lockForUpdate()->with(['status', 'agendaStatus'])->findOrFail($item->meeting_id);
            $this->requireOpenAgenda($m);
            $old = $item->only(array_keys($d));
            $item->update($d);
            $this->touchAgenda($m, $by);
            AuditLogger::log('meeting.agenda_item_updated', $item, $old, $d);
            return $item->refresh();
        });
    }

    public function removeAgendaItem(MeetingAgendaItem $item, User $by): void
    {
        DB::transaction(function () use ($item, $by) {
            $m = Meeting::lockForUpdate()->with(['status', 'agendaStatus'])->findOrFail($item->meeting_id);
            $this->requireOpenAgenda($m);
            AuditLogger::log('meeting.agenda_item_removed', $item, ['title' => $item->title], []);
            $item->delete();
            MeetingAgendaItem::where('meeting_id', $m->id)->orderBy('position')->get()->each(fn ($i, $n) => $i->update(['position' => $n + 1]));
            $this->touchAgenda($m, $by);
        });
    }

    /** @param string[] $orderedIds every item id of the meeting, in the new order */
    public function reorderAgenda(Meeting $meeting, array $orderedIds, User $by): void
    {
        DB::transaction(function () use ($meeting, $orderedIds, $by) {
            $m = Meeting::lockForUpdate()->with(['status', 'agendaStatus'])->findOrFail($meeting->id);
            $this->requireOpenAgenda($m);
            $ids = MeetingAgendaItem::where('meeting_id', $m->id)->pluck('id')->all();
            if (count($orderedIds) !== count($ids) || array_diff($ids, $orderedIds)) {
                $this->fail('order', 'bad_order');
            }
            foreach (array_values($orderedIds) as $n => $id) {
                MeetingAgendaItem::whereKey($id)->update(['position' => $n + 1]);
            }
            $this->touchAgenda($m, $by);
            AuditLogger::log('meeting.agenda_reordered', $m);
        });
    }

    /** draft -> circulated: the invitees are told the agenda is ready to read. */
    public function circulateAgenda(Meeting $meeting, User $by): Meeting
    {
        return DB::transaction(function () use ($meeting, $by) {
            $m = Meeting::lockForUpdate()->with(['status', 'agendaStatus'])->findOrFail($meeting->id);
            $this->requireOpenAgenda($m);
            if ($m->agendaStatus->code !== 'draft') {
                $this->fail('agenda', 'agenda_not_draft');
            }
            if (!MeetingAgendaItem::where('meeting_id', $m->id)->exists()) {
                $this->fail('agenda', 'agenda_empty');
            }
            $m->update(['agenda_status_id' => Lookup::id('agenda_status', 'circulated')]);
            AuditLogger::log('meeting.agenda_circulated', $m);
            if ($m->status->code === 'scheduled') {
                $this->notices->send($m, $m->invitations()->with('user.language')->get(), 'meeting_agenda_circulated', [], ['in_app']);
            }
            return $m->refresh();
        });
    }

    /** circulated -> approved, by the chair only. */
    public function approveAgenda(Meeting $meeting, User $by): Meeting
    {
        return DB::transaction(function () use ($meeting, $by) {
            $m = Meeting::lockForUpdate()->with(['status', 'agendaStatus'])->findOrFail($meeting->id);
            $this->requireOpenAgenda($m);
            if ($m->agendaStatus->code !== 'circulated') {
                $this->fail('agenda', 'agenda_not_circulated');
            }
            $m->update(['agenda_status_id' => Lookup::id('agenda_status', 'approved'), 'agenda_approved_by' => $by->id, 'agenda_approved_at' => now()]);
            AuditLogger::log('meeting.agenda_approved', $m);
            return $m->refresh();
        });
    }

    // ---- answers -----------------------------------------------------------------------------------------------------------
    /** An invitee (or a guest through their link) answers. */
    public function respond(MeetingInvitation $inv, string $response, ?string $note, ?string $proposedStartsAt, ?User $actor = null): MeetingInvitation
    {
        $m = Meeting::with('status')->findOrFail($inv->meeting_id);
        if ($m->status->code !== 'scheduled') {
            $this->fail('response', 'rsvp_closed');
        }
        if ($response === 'proposed' && !$proposedStartsAt) {
            $this->fail('proposed_starts_at', 'proposal_needs_time');
        }
        $inv->update(['response_id' => Lookup::id('rsvp_response', $response), 'response_note' => $note, 'responded_at' => now(),
            'proposed_starts_at' => $response === 'proposed' ? $this->parse($proposedStartsAt) : null]);
        AuditLogger::log('meeting.rsvp', $m, [], ['invitee' => $inv->displayName(), 'response' => $response]);
        if (in_array($response, ['declined', 'proposed'], true) && $m->organizer_id !== $inv->user_id) {
            Notifier::send($m->organizer, 'meeting_rsvp_' . $response, ['title' => $m->title, 'who' => $inv->displayName(), 'note' => (string) $note,
                'when' => $this->notices->whenText($m)], null, ['in_app'], '/meetings/' . $m->id, $m->organization_id);
        }
        return $inv->refresh();
    }
}
