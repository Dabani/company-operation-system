<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\MeetingPoll;
use App\Models\Organization;
use App\Services\Meetings\MeetingCollabService;
use App\Services\Meetings\OnlineMeetingService;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;

/** Chat and polls for external guests through the personal link (the credential). Unauthenticated and rate limited; a guest sees only what the meeting admits. */
class MeetingCollabPublicController extends Controller
{
    public function __construct(private MeetingCollabService $svc, private OnlineMeetingService $online) {}

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

    private function allowed(MeetingInvitation $inv): void
    {
        abort_unless($this->svc->participates($inv->meeting, null, $inv), 403, 'forbidden');
    }

    public function chat(Request $request, string $token)
    {
        $inv = $this->find($token);
        $this->allowed($inv);
        $m = $inv->meeting;
        $msgs = $this->svc->messages($m, null, $inv, $request->query('after'));
        return ['data' => $msgs, 'meta' => ['last_id' => $msgs ? end($msgs)['id'] : $request->query('after'), 'can_post' => $this->online->windowOpen($m), 'can_remove' => false]];
    }

    public function post(Request $request, string $token)
    {
        $inv = $this->find($token);
        $d = $request->validate(['body' => 'required|string|max:1000']);
        $this->svc->post($inv->meeting, null, $inv, $d['body']);
        return $this->chat($request, $token);
    }

    public function polls(string $token)
    {
        $inv = $this->find($token);
        $this->allowed($inv);
        return ['data' => $this->svc->polls($inv->meeting, null, $inv, false), 'meta' => ['can_create' => false]];
    }

    public function vote(Request $request, string $token, string $pollId)
    {
        $inv = $this->find($token);
        $p = MeetingPoll::withoutGlobalScopes()->where('meeting_id', $inv->meeting_id)->findOrFail($pollId);
        $d = $request->validate(['option_ids' => 'required|array|min:1|max:10', 'option_ids.*' => 'string']);
        $this->svc->vote($p, null, $inv, $d['option_ids']);
        return $this->polls($token);
    }
}
