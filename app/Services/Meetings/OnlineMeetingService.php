<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingIntervention;
use App\Models\MeetingInvitation;
use App\Models\MeetingOnlineSession;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\Html;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Online meetings (Phase 7f): the video room (Jitsi), who may enter and as what, online attendance, and the intervention queue.
 * An "actor" is a user (account) or an invitation (external guest); every rule is the same for both.
 */
class OnlineMeetingService
{
    public function __construct(private MeetingAccess $access, private MeetingAttendanceService $attendance) {}

    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("meetings.$key", $p)]]);
    }

    private function kind(string $c): string { return Lookup::id('meeting_intervention_kind', $c); }
    private function st(string $c): string { return Lookup::id('meeting_intervention_status', $c); }

    public function orgEnabled(Meeting $m): bool { return (bool) SettingsService::get($m->organization_id, 'meeting.online_enabled'); }

    public function windowOpen(Meeting $m): bool
    {
        $m->loadMissing('status');
        return OnlineRules::joinWindowOpen($m->status->code, now(), $m->starts_at, $m->ends_at, (int) SettingsService::get($m->organization_id, 'meeting.online_early_minutes'));
    }

    public function isModerator(Meeting $m, User $u): bool
    {
        return OnlineRules::moderator($m->organizer_id === $u->id, $m->chair_id === $u->id, $m->secretary_id === $u->id, $m->facilitator_id === $u->id);
    }

    /** Who gives the floor: the chair, the facilitator, or a holder of "manage all". */
    public function canFloor(Meeting $m, User $u): bool
    {
        return $m->chair_id === $u->id || $m->facilitator_id === $u->id || $this->access->canChair($u, $m);
    }

    // ---- the room ----------------------------------------------------------------------------------------------------------------
    public function enable(Meeting $m, bool $allowGuests, User $by): Meeting
    {
        $m->loadMissing('status');
        if (!$this->orgEnabled($m)) {
            $this->fail('online', 'online_disabled_org');
        }
        if (!in_array($m->status->code, ['draft', 'scheduled', 'in_progress'], true)) {
            $this->fail('status', 'online_bad_status');
        }
        $allowGuests = $allowGuests && !$m->isConfidential();
        if (!$m->online_room) {
            $m->online_room = 'cops-' . Str::lower(Str::random(28));
        }
        $m->online_allow_guests = $allowGuests;
        $m->save();
        AuditLogger::log('meeting.online_enabled', $m, [], ['guests' => $allowGuests]);
        return $m->refresh();
    }

    public function disable(Meeting $m, User $by): Meeting
    {
        $m->loadMissing('status');
        if (!in_array($m->status->code, ['draft', 'scheduled', 'in_progress'], true)) {
            $this->fail('status', 'online_bad_status');
        }
        $m->update(['online_room' => null, 'online_allow_guests' => false]);
        AuditLogger::log('meeting.online_disabled', $m, [], []);
        return $m->refresh();
    }

    private function token(Meeting $m, string $id, string $name, ?string $email, bool $moderator): ?string
    {
        $secret = config('jitsi.app_secret');
        $app = config('jitsi.app_id');
        if (!$secret || !$app) {
            return null;
        }
        return JitsiToken::forUser($app, $secret, config('jitsi.sub') ?: config('jitsi.domain'), $m->online_room, $name, $email, $id, $moderator, time(), (int) config('jitsi.token_ttl_minutes') * 60);
    }

    private function joinData(Meeting $m, string $name, ?string $jwt, bool $moderator): array
    {
        return ['domain' => config('jitsi.domain'), 'room' => $m->online_room, 'jwt' => $jwt, 'display_name' => $name, 'moderator' => $moderator,
            'subject' => $m->isConfidential() ? __('meetings.confidential_meeting') : $m->title, 'signed' => $jwt !== null];
    }

    /** An account holder enters: hosts and invited people (who did not decline). Online attendance is recorded when the meeting is in progress. */
    public function join(Meeting $m, User $u): array
    {
        $m->loadMissing('status');
        if (!$m->online_room) {
            $this->fail('online', 'online_not_enabled');
        }
        $inv = $this->access->invitation($u, $m);
        if (!$m->isHost($u) && (!$inv || $inv->response?->code === 'declined')) {
            $this->fail('online', 'online_not_participant');
        }
        if (!$this->windowOpen($m)) {
            $this->fail('online', 'online_closed');
        }
        $mod = $this->isModerator($m, $u);
        DB::transaction(function () use ($m, $u, $mod) {
            MeetingOnlineSession::where('meeting_id', $m->id)->where('user_id', $u->id)->whereNull('left_at')->update(['left_at' => now()]);
            MeetingOnlineSession::create(['meeting_id' => $m->id, 'user_id' => $u->id, 'moderator' => $mod, 'joined_at' => now()]);
        });
        if ($m->status->code === 'in_progress' && $inv) {
            try {
                $this->attendance->selfCheckIn($m, $u, true);
            } catch (ValidationException|HttpException $e) {
                // attendance is a courtesy of joining: it never blocks the call
            }
        }
        AuditLogger::log('meeting.online_joined', $m, [], ['moderator' => $mod]);
        return $this->joinData($m, $u->name, $this->token($m, $u->id, $u->name, $u->email, $mod), $mod);
    }

    /** An external guest enters through the personal link (never as a moderator). */
    public function joinGuest(Meeting $m, MeetingInvitation $inv): array
    {
        $m->loadMissing('status');
        if (!$m->online_room) {
            $this->fail('online', 'online_not_enabled');
        }
        $inv->loadMissing('response');
        if (!OnlineRules::guestMayJoin((bool) $m->online_allow_guests, $m->isConfidential(), $inv->response?->code)) {
            $this->fail('online', 'online_guests_not_allowed');
        }
        if (!$this->windowOpen($m)) {
            $this->fail('online', 'online_closed');
        }
        MeetingOnlineSession::where('meeting_id', $m->id)->where('invitation_id', $inv->id)->whereNull('left_at')->update(['left_at' => now()]);
        MeetingOnlineSession::create(['meeting_id' => $m->id, 'invitation_id' => $inv->id, 'moderator' => false, 'joined_at' => now()]);
        AuditLogger::log('meeting.online_guest_joined', $m, [], ['guest' => $inv->displayName()]);
        $name = $inv->displayName() ?: 'Guest';
        return $this->joinData($m, $name, $this->token($m, 'guest-' . $inv->id, $name, $inv->email(), false), false);
    }

    public function leave(Meeting $m, ?User $u, ?MeetingInvitation $inv): void
    {
        MeetingOnlineSession::where('meeting_id', $m->id)->whereNull('left_at')
            ->when($u, fn ($q) => $q->where('user_id', $u->id))->when($inv, fn ($q) => $q->where('invitation_id', $inv->id))->update(['left_at' => now()]);
    }

    // ---- interventions -----------------------------------------------------------------------------------------------------------
    private function actorScope($q, ?User $u, ?MeetingInvitation $inv)
    {
        return $u ? $q->where('user_id', $u->id) : $q->where('invitation_id', $inv->id);
    }

    public function request(Meeting $m, ?User $u, ?MeetingInvitation $inv, string $kind, ?string $topic, ?string $agendaItemId): MeetingIntervention
    {
        $m->loadMissing('status');
        if (!OnlineRules::mayRequest($m->status->code)) {
            $this->fail('status', 'online_not_open');
        }
        if (!in_array($kind, OnlineRules::KINDS, true)) {
            $this->fail('kind', 'intervention_bad_kind');
        }
        if (!OnlineRules::topicOk($topic)) {
            $this->fail('topic', 'intervention_topic_long');
        }
        if ($agendaItemId && !$m->agendaItems()->whereKey($agendaItemId)->exists()) {
            $this->fail('agenda_item_id', 'intervention_bad_item');
        }
        return DB::transaction(function () use ($m, $u, $inv, $kind, $topic, $agendaItemId) {
            Meeting::whereKey($m->id)->lockForUpdate()->first();               // serialises requests of one meeting
            $open = [$this->st('requested'), $this->st('speaking')];
            if ($this->actorScope(MeetingIntervention::where('meeting_id', $m->id)->whereIn('status_id', $open), $u, $inv)->exists()) {
                $this->fail('kind', 'intervention_exists');
            }
            $i = MeetingIntervention::create(['meeting_id' => $m->id, 'user_id' => $u?->id, 'invitation_id' => $u ? null : $inv->id, 'agenda_item_id' => $agendaItemId, 'kind_id' => $this->kind($kind),
                'status_id' => $this->st('requested'), 'topic' => $topic !== null && trim($topic) !== '' ? trim($topic) : null, 'requested_at' => now()]);
            AuditLogger::log('meeting.intervention_requested', $m, [], ['kind' => $kind, 'person' => $i->displayName()]);
            return $i->refresh();
        });
    }

    private function load(string $id): MeetingIntervention
    {
        return MeetingIntervention::with(['status', 'kind', 'meeting.status'])->lockForUpdate()->findOrFail($id);
    }

    private function move(MeetingIntervention $i, string $to, array $extra, ?User $by, string $auditKey, ?string $note = null): MeetingIntervention
    {
        $from = $i->status->code;
        if (!OnlineRules::allowed($from, $to)) {
            $this->fail('status', 'intervention_bad_move');
        }
        $i->update(['status_id' => $this->st($to)] + $extra + ($by ? ['decided_by' => $by->id] : []));
        AuditLogger::log($auditKey, $i->meeting, [], ['person' => $i->displayName(), 'kind' => $i->kind->code, 'topic' => $i->topic, 'note' => $note]);
        return $i->refresh();
    }

    /** Give the floor. Only one person speaks at a time: finish the current speaker first. */
    public function grant(string $id, User $by): MeetingIntervention
    {
        return DB::transaction(function () use ($id, $by) {
            $i = $this->load($id);
            if (!$this->canFloor($i->meeting, $by)) {
                abort(403, 'forbidden');
            }
            $speaking = MeetingIntervention::where('meeting_id', $i->meeting_id)->where('status_id', $this->st('speaking'))->exists();
            if (!OnlineRules::canGrant($speaking)) {
                $this->fail('status', 'intervention_someone_speaking');
            }
            if (!OnlineRules::mayRequest($i->meeting->status->code)) {
                $this->fail('status', 'online_not_open');
            }
            return $this->move($i, 'speaking', ['granted_at' => now()], $by, 'meeting.intervention_granted');
        });
    }

    public function finish(string $id, User $by): MeetingIntervention
    {
        return DB::transaction(function () use ($id, $by) {
            $i = $this->load($id);
            if (!$this->canFloor($i->meeting, $by)) {
                abort(403, 'forbidden');
            }
            return $this->move($i, 'done', ['finished_at' => now()], $by, 'meeting.intervention_done');
        });
    }

    public function dismiss(string $id, User $by, ?string $note): MeetingIntervention
    {
        return DB::transaction(function () use ($id, $by, $note) {
            $i = $this->load($id);
            if (!$this->canFloor($i->meeting, $by)) {
                abort(403, 'forbidden');
            }
            return $this->move($i, 'dismissed', ['decision_note' => $note ? Str::limit(trim($note), 300, '') : null, 'finished_at' => now()], $by, 'meeting.intervention_dismissed', $note);
        });
    }

    /** The requester takes their own request back (only while it waits). */
    public function withdraw(string $id, ?User $u, ?MeetingInvitation $inv): MeetingIntervention
    {
        return DB::transaction(function () use ($id, $u, $inv) {
            $i = $this->load($id);
            $mine = $u ? $i->user_id === $u->id : ($inv && $i->invitation_id === $inv->id);
            abort_unless($mine, 404);
            return $this->move($i, 'withdrawn', ['finished_at' => now()], null, 'meeting.intervention_withdrawn');
        });
    }

    /** The queue for display: open requests in speaking order, then what has been said so far. */
    public function queue(Meeting $m, ?User $u, ?MeetingInvitation $inv): array
    {
        $rows = MeetingIntervention::where('meeting_id', $m->id)->with(['user:id,name', 'invitation', 'kind', 'status', 'agendaItem'])->orderBy('requested_at')->get();
        $waiting = $rows->filter(fn ($r) => $r->status->code === 'requested');
        $order = OnlineRules::order($waiting->map(fn ($r) => ['id' => $r->id, 'kind' => $r->kind->code, 'at' => $r->requested_at->getTimestamp()])->all());
        $shape = fn ($r) => ['id' => $r->id, 'name' => $r->displayName(), 'is_guest' => $r->user_id === null, 'kind' => $r->kind->code, 'status' => $r->status->code, 'topic' => $r->topic,
            'agenda_item' => $r->agendaItem?->title, 'requested_at' => $r->requested_at->toIso8601String(), 'granted_at' => $r->granted_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(),
            'position' => ($p = array_search($r->id, $order, true)) === false ? null : $p + 1, 'decision_note' => $r->decision_note,
            'mine' => $u ? $r->user_id === $u->id : ($inv && $r->invitation_id === $inv->id)];
        return ['speaking' => $rows->first(fn ($r) => $r->status->code === 'speaking') ? $shape($rows->first(fn ($r) => $r->status->code === 'speaking')) : null,
            'waiting' => collect($order)->map(fn ($id) => $shape($waiting->firstWhere('id', $id)))->values()->all(),
            'history' => $rows->filter(fn ($r) => in_array($r->status->code, ['done', 'dismissed', 'withdrawn'], true))->sortByDesc('requested_at')->take(30)->map($shape)->values()->all()];
    }
}
