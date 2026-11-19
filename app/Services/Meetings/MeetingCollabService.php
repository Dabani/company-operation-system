<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingChatMessage;
use App\Models\MeetingInvitation;
use App\Models\MeetingPoll;
use App\Models\MeetingPollOption;
use App\Models\MeetingPollVote;
use App\Models\MeetingPollVoter;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Chat and polls of online meetings (Phase 7g). An actor is an account holder or an external guest (invitation), as in OnlineMeetingService.
 * Chat is open to participants while the online room is open and stays readable for them afterwards. Polls are run by the hosts while the meeting is in progress.
 */
class MeetingCollabService
{
    public function __construct(private OnlineMeetingService $online, private MeetingAccess $access) {}

    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("meetings.$key", $p)]]);
    }

    private function st(string $c): string { return Lookup::id('meeting_poll_status', $c); }

    /** May this actor take part (chat, vote) in the online meeting right now? Hosts and invited account holders, or a guest the meeting admits. */
    public function participates(Meeting $m, ?User $u, ?MeetingInvitation $inv): bool
    {
        if (!$m->online_room) {
            return false;
        }
        if ($u) {
            $i = $this->access->invitation($u, $m);
            return $m->isHost($u) || ($i && $i->response?->code !== 'declined');
        }
        $inv?->loadMissing('response');
        return $inv && OnlineRules::guestMayJoin((bool) $m->online_allow_guests, $m->isConfidential(), $inv->response?->code);
    }

    // ---- chat --------------------------------------------------------------------------------------------------------------------
    public function post(Meeting $m, ?User $u, ?MeetingInvitation $inv, ?string $body): MeetingChatMessage
    {
        if (!$this->participates($m, $u, $inv)) {
            $this->fail('body', 'online_not_participant');
        }
        if (!$this->online->windowOpen($m)) {
            $this->fail('body', 'online_closed');
        }
        if (!CollabRules::messageOk($body)) {
            $this->fail('body', 'chat_body_invalid');
        }
        return MeetingChatMessage::create(['meeting_id' => $m->id, 'user_id' => $u?->id, 'invitation_id' => $u ? null : $inv->id, 'body' => trim($body)]);
    }

    /** Messages after a cursor (ULIDs sort by time), or the latest 100. Removed messages keep their place but lose their text. */
    public function messages(Meeting $m, ?User $u, ?MeetingInvitation $inv, ?string $after): array
    {
        $q = MeetingChatMessage::where('meeting_id', $m->id)->with(['user:id,name', 'invitation']);
        $rows = $after
            ? $q->where('id', '>', $after)->orderBy('id')->limit(200)->get()
            : $q->orderByDesc('id')->limit(100)->get()->reverse()->values();
        $hosts = array_filter([$m->organizer_id, $m->chair_id, $m->secretary_id, $m->facilitator_id]);
        return $rows->map(fn ($r) => ['id' => $r->id, 'name' => $r->displayName(), 'is_guest' => $r->user_id === null, 'host' => $r->user_id !== null && in_array($r->user_id, $hosts, true),
            'body' => $r->removed_at ? null : $r->body, 'removed' => $r->removed_at !== null, 'at' => $r->created_at->toIso8601String(),
            'mine' => $u ? $r->user_id === $u->id : ($inv && $r->invitation_id === $inv->id)])->all();
    }

    public function removeMessage(string $id, User $by): MeetingChatMessage
    {
        $msg = MeetingChatMessage::with('meeting')->findOrFail($id);
        abort_unless($this->online->isModerator($msg->meeting, $by) || $this->access->canChair($by, $msg->meeting), 403, 'forbidden');
        if (!$msg->removed_at) {
            $msg->update(['removed_at' => now(), 'removed_by' => $by->id]);
            AuditLogger::log('meeting.chat_removed', $msg->meeting, [], ['person' => $msg->displayName(), 'message' => $msg->id]);
        }
        return $msg;
    }

    // ---- polls -------------------------------------------------------------------------------------------------------------------
    /** @param string[] $labels */
    public function createPoll(Meeting $m, User $by, string $question, array $labels, array $o): MeetingPoll
    {
        $m->loadMissing('status');
        if (!$this->online->isModerator($m, $by) && !$this->access->canChair($by, $m)) {
            abort(403, 'forbidden');
        }
        if (!$m->online_room) {
            $this->fail('question', 'online_not_enabled');
        }
        if (!OnlineRules::mayRequest($m->status->code)) {
            $this->fail('question', 'online_not_open');
        }
        if (!CollabRules::questionOk($question)) {
            $this->fail('question', 'poll_question_invalid');
        }
        $labels = array_values(array_map('trim', $labels));
        if (!CollabRules::optionsOk($labels)) {
            $this->fail('options', 'poll_options_invalid');
        }
        $multiple = (bool) ($o['multiple'] ?? false);
        $max = $multiple ? (int) ($o['max_choices'] ?? 0) : 0;
        if ($max < 0 || $max > count($labels)) {
            $this->fail('max_choices', 'poll_options_invalid');
        }
        if (!empty($o['agenda_item_id']) && !$m->agendaItems()->whereKey($o['agenda_item_id'])->exists()) {
            $this->fail('agenda_item_id', 'intervention_bad_item');
        }
        return DB::transaction(function () use ($m, $by, $question, $labels, $o, $multiple, $max) {
            $p = MeetingPoll::create(['meeting_id' => $m->id, 'agenda_item_id' => $o['agenda_item_id'] ?? null, 'question' => trim($question), 'status_id' => $this->st('open'), 'multiple' => $multiple,
                'max_choices' => $max, 'anonymous' => (bool) ($o['anonymous'] ?? false), 'guests_vote' => (bool) ($o['guests_vote'] ?? false) && !$m->isConfidential(), 'created_by' => $by->id]);
            foreach ($labels as $i => $l) {
                MeetingPollOption::create(['poll_id' => $p->id, 'label' => $l, 'position' => $i]);
            }
            AuditLogger::log('meeting.poll_opened', $m, [], ['question' => $p->question, 'options' => $labels, 'anonymous' => $p->anonymous]);
            return $p->refresh();
        });
    }

    /** @param string[] $optionIds */
    public function vote(MeetingPoll $p, ?User $u, ?MeetingInvitation $inv, array $optionIds): MeetingPoll
    {
        return DB::transaction(function () use ($p, $u, $inv, $optionIds) {
            $p = MeetingPoll::lockForUpdate()->with(['status', 'meeting.status', 'options'])->findOrFail($p->id);
            $m = $p->meeting;
            if ($p->status->code !== 'open' || !OnlineRules::mayRequest($m->status->code)) {
                $this->fail('option_ids', 'poll_closed');
            }
            if (!$this->participates($m, $u, $inv) || (!$u && !$p->guests_vote)) {
                $this->fail('option_ids', 'poll_not_eligible');
            }
            $optionIds = array_values(array_unique($optionIds));
            $valid = $p->options->pluck('id')->all();
            if (array_diff($optionIds, $valid) || !CollabRules::choicesOk(count($optionIds), $p->multiple, $p->max_choices, count($valid))) {
                $this->fail('option_ids', 'poll_choice_invalid');
            }
            $exists = MeetingPollVoter::where('poll_id', $p->id)->when($u, fn ($q) => $q->where('user_id', $u->id))->when(!$u, fn ($q) => $q->where('invitation_id', $inv->id))->exists();
            if ($exists) {
                $this->fail('option_ids', 'poll_already_voted');
            }
            $voter = MeetingPollVoter::create(['poll_id' => $p->id, 'user_id' => $u?->id, 'invitation_id' => $u ? null : $inv->id]);
            foreach ($optionIds as $oid) {
                // ULIDs sort by time, which would let someone line a vote up with its voter: anonymous votes get random ids instead
                MeetingPollVote::create(['id' => $p->anonymous ? Str::upper(Str::random(26)) : (string) Str::ulid(), 'poll_id' => $p->id, 'option_id' => $oid, 'voter_id' => $p->anonymous ? null : $voter->id]);
            }
            return $p;
        });
    }

    public function closePoll(string $id, User $by): MeetingPoll
    {
        return DB::transaction(function () use ($id, $by) {
            $p = MeetingPoll::lockForUpdate()->with(['status', 'meeting', 'options'])->findOrFail($id);
            $m = $p->meeting;
            if (!$this->online->isModerator($m, $by) && !$this->access->canChair($by, $m)) {
                abort(403, 'forbidden');
            }
            if (!CollabRules::allowedMove($p->status->code, 'closed')) {
                $this->fail('status', 'poll_closed');
            }
            $p->update(['status_id' => $this->st('closed'), 'closed_by' => $by->id, 'closed_at' => now()]);
            $res = $this->results($p->refresh());
            AuditLogger::log('meeting.poll_closed', $m, [], ['question' => $p->question, 'voters' => $res['voters'], 'result' => array_map(fn ($o) => [$o['label'], $o['votes']], $res['options'])]);
            return $p;
        });
    }

    /** @return array{voters:int, options: array<int, array{id:string,label:string,votes:int,percent:float,leader:bool}>} */
    public function results(MeetingPoll $p): array
    {
        $p->loadMissing('options');
        $ids = $p->options->pluck('id')->all();
        $counts = CollabRules::tally($ids, MeetingPollVote::where('poll_id', $p->id)->pluck('option_id')->all());
        $voters = MeetingPollVoter::where('poll_id', $p->id)->count();
        $leaders = CollabRules::leaders($counts);
        return ['voters' => $voters, 'options' => $p->options->map(fn ($o) => ['id' => $o->id, 'label' => $o->label, 'votes' => $counts[$o->id], 'percent' => CollabRules::percent($counts[$o->id], $voters),
            'leader' => in_array($o->id, $leaders, true)])->all()];
    }

    /** The polls of a meeting as one actor may see them. */
    public function polls(Meeting $m, ?User $u, ?MeetingInvitation $inv, bool $moderator): array
    {
        $polls = MeetingPoll::where('meeting_id', $m->id)->with(['status', 'agendaItem', 'options'])->orderByDesc('created_at')->get();
        $mineByPoll = MeetingPollVoter::whereIn('poll_id', $polls->pluck('id'))->when($u, fn ($q) => $q->where('user_id', $u->id))->when(!$u, fn ($q) => $q->where('invitation_id', $inv?->id))
            ->with('votes')->get()->keyBy('poll_id');
        $canTakePart = $this->participates($m, $u, $inv);
        $m->loadMissing('status');
        $live = OnlineRules::mayRequest($m->status->code);
        return $polls->map(function ($p) use ($m, $u, $moderator, $mineByPoll, $canTakePart, $live) {
            $code = $p->status->code;
            $show = CollabRules::showResults($code, $moderator);
            $res = $this->results($p);
            $voted = $mineByPoll->get($p->id);
            $row = ['id' => $p->id, 'question' => $p->question, 'status' => $code, 'multiple' => $p->multiple, 'max_choices' => $p->max_choices, 'anonymous' => $p->anonymous, 'guests_vote' => $p->guests_vote,
                'agenda_item' => $p->agendaItem?->title, 'created_at' => $p->created_at->toIso8601String(), 'closed_at' => $p->closed_at?->toIso8601String(), 'voters' => $show ? $res['voters'] : null,
                'has_voted' => $voted !== null, 'my_options' => $voted && !$p->anonymous ? $voted->votes->pluck('option_id')->all() : [],
                'can_vote' => $code === 'open' && $live && $canTakePart && !$voted && ($u !== null || $p->guests_vote),
                'options' => collect($res['options'])->map(fn ($o) => ['id' => $o['id'], 'label' => $o['label']] + ($show ? ['votes' => $o['votes'], 'percent' => $o['percent'], 'leader' => $code === 'closed' && $o['leader']] : []))->all()];
            if ($moderator && $code === 'closed' && !$p->anonymous) {
                $row['voter_names'] = MeetingPollVoter::where('poll_id', $p->id)->with(['user:id,name', 'invitation', 'votes'])->get()
                    ->map(fn ($v) => ['name' => $v->displayName(), 'options' => $v->votes->pluck('option_id')->all()])->all();
            }
            return $row;
        })->all();
    }
}
