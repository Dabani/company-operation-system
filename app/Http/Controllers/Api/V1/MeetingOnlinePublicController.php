<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingIntervention;
use App\Models\MeetingInvitation;
use App\Models\Organization;
use App\Services\Meetings\OnlineMeetingService;
use App\Services\Meetings\OnlineRules;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Online meeting for external guests, through the personal link of their invitation. Unauthenticated and rate limited: the link is the credential.
 * It shows the minimum (title, status, whether the room is open), never the agenda or the other invitees, and a guest is never a moderator.
 */
class MeetingOnlinePublicController extends Controller
{
    public function __construct(private OnlineMeetingService $svc) {}

    private function find(string $token): MeetingInvitation
    {
        abort_unless(strlen($token) >= 40, 404);
        $inv = MeetingInvitation::withoutGlobalScopes()->where('token', $token)->whereNull('user_id')->first();
        abort_unless($inv, 404);
        $m = Meeting::withoutGlobalScopes()->with(['status', 'confidentiality'])->find($inv->meeting_id);
        abort_unless($m && $m->status->code !== 'draft', 404);
        app(OrganizationContext::class)->resolveForOrganization(Organization::findOrFail($inv->organization_id));
        return $inv->setRelation('meeting', $m);
    }

    private function state(MeetingInvitation $inv): array
    {
        $m = $inv->meeting;
        $inv->loadMissing('response');
        $allowed = $m->online_room && OnlineRules::guestMayJoin((bool) $m->online_allow_guests, $m->isConfidential(), $inv->response?->code);
        $queue = $allowed ? $this->svc->queue($m, null, $inv) : null;
        $mine = $queue ? collect([$queue['speaking']])->merge($queue['waiting'])->filter()->firstWhere('mine', true) : null;
        return ['data' => ['title' => $m->title, 'status' => $m->status->code, 'guest' => $inv->guest_name, 'enabled' => $allowed, 'window_open' => $allowed && $this->svc->windowOpen($m),
            'kinds' => OnlineRules::KINDS, 'me' => ['can_join' => $allowed && $this->svc->windowOpen($m), 'can_request' => $allowed && OnlineRules::mayRequest($m->status->code) && !$mine],
            'queue' => $queue ? ['speaking' => $queue['speaking'], 'waiting' => $queue['waiting']] : null]];
    }

    public function show(string $token) { return $this->state($this->find($token)); }

    public function join(string $token)
    {
        $inv = $this->find($token);
        return ['data' => $this->svc->joinGuest($inv->meeting, $inv)];
    }

    public function leave(string $token)
    {
        $inv = $this->find($token);
        $this->svc->leave($inv->meeting, null, $inv);
        return response()->noContent();
    }

    public function request(Request $request, string $token)
    {
        $inv = $this->find($token);
        $d = $request->validate(['kind' => ['required', Rule::in(OnlineRules::KINDS)], 'topic' => 'nullable|string|max:200']);
        abort_unless($this->state($inv)['data']['enabled'], 403, 'forbidden');
        $this->svc->request($inv->meeting, null, $inv, $d['kind'], $d['topic'] ?? null, null);
        return $this->state($inv);
    }

    public function withdraw(string $token, string $interventionId)
    {
        $inv = $this->find($token);
        $this->svc->withdraw($interventionId, null, $inv);
        return $this->state($inv);
    }
}
