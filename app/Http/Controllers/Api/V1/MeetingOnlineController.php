<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingIntervention;
use App\Services\Meetings\MeetingAccess;
use App\Services\Meetings\OnlineMeetingService;
use App\Services\Meetings\OnlineRules;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Online meetings for account holders: room, join, and the intervention queue. Visibility of the meeting itself is MeetingAccess (404 when not visible). */
class MeetingOnlineController extends Controller
{
    public function __construct(private MeetingAccess $access, private OnlineMeetingService $svc) {}

    private function meeting(Request $r, string $id): Meeting
    {
        return $this->access->find($r->user(), $id, ['status', 'confidentiality']);
    }

    private function state(Request $r, Meeting $m): array
    {
        $u = $r->user();
        $participant = $this->access->isParticipant($u, $m);
        $code = $m->status->code;
        $queue = $m->online_room && $participant ? $this->svc->queue($m, $u, null) : null;
        $mine = $queue ? collect([$queue['speaking']])->merge($queue['waiting'])->filter()->firstWhere('mine', true) : null;
        return ['data' => [
            'org_enabled' => $this->svc->orgEnabled($m), 'enabled' => (bool) $m->online_room, 'allow_guests' => (bool) $m->online_allow_guests, 'status' => $code, 'confidential' => $m->isConfidential(),
            'window_open' => $m->online_room ? $this->svc->windowOpen($m) : false, 'domain' => config('jitsi.domain'), 'signed' => (bool) (config('jitsi.app_id') && config('jitsi.app_secret')),
            'kinds' => OnlineRules::KINDS,
            'me' => ['can_manage' => $this->access->canManage($u, $m), 'is_participant' => $participant, 'can_join' => $m->online_room && $participant && $this->svc->windowOpen($m),
                'is_moderator' => $this->svc->isModerator($m, $u), 'can_floor' => $this->svc->canFloor($m, $u), 'can_request' => $m->online_room && $participant && OnlineRules::mayRequest($code) && !$mine],
            'queue' => $queue,
            'agenda' => $m->online_room && $participant ? $m->agendaItems()->orderBy('position')->get(['id', 'title'])->all() : [],
        ]];
    }

    public function show(Request $request, string $id) { return $this->state($request, $this->meeting($request, $id)); }

    public function enable(Request $request, string $id)
    {
        $m = $this->meeting($request, $id);
        $this->access->requireManage($request->user(), $m);
        $d = $request->validate(['allow_guests' => 'sometimes|boolean']);
        $this->svc->enable($m, (bool) ($d['allow_guests'] ?? false), $request->user());
        return $this->state($request, $this->meeting($request, $id));
    }

    public function disable(Request $request, string $id)
    {
        $m = $this->meeting($request, $id);
        $this->access->requireManage($request->user(), $m);
        $this->svc->disable($m, $request->user());
        return $this->state($request, $this->meeting($request, $id));
    }

    public function join(Request $request, string $id)
    {
        return ['data' => $this->svc->join($this->meeting($request, $id), $request->user())];
    }

    public function leave(Request $request, string $id)
    {
        $this->svc->leave($this->meeting($request, $id), $request->user(), null);
        return response()->noContent();
    }

    public function request(Request $request, string $id)
    {
        $m = $this->meeting($request, $id);
        abort_unless($this->access->isParticipant($request->user(), $m), 403, 'forbidden');
        $d = $request->validate(['kind' => ['required', Rule::in(OnlineRules::KINDS)], 'topic' => 'nullable|string|max:200', 'agenda_item_id' => 'nullable|string']);
        $this->svc->request($m, $request->user(), null, $d['kind'], $d['topic'] ?? null, $d['agenda_item_id'] ?? null);
        return $this->state($request, $this->meeting($request, $id));
    }

    private function act(Request $request, string $intervId, callable $do)
    {
        $i = MeetingIntervention::findOrFail($intervId);
        $m = $this->meeting($request, $i->meeting_id);
        $do();
        return $this->state($request, $m);
    }

    public function grant(Request $request, string $interventionId) { return $this->act($request, $interventionId, fn () => $this->svc->grant($interventionId, $request->user())); }

    public function finish(Request $request, string $interventionId) { return $this->act($request, $interventionId, fn () => $this->svc->finish($interventionId, $request->user())); }

    public function dismiss(Request $request, string $interventionId)
    {
        $d = $request->validate(['note' => 'nullable|string|max:300']);
        return $this->act($request, $interventionId, fn () => $this->svc->dismiss($interventionId, $request->user(), $d['note'] ?? null));
    }

    public function withdraw(Request $request, string $interventionId) { return $this->act($request, $interventionId, fn () => $this->svc->withdraw($interventionId, $request->user(), null)); }
}
