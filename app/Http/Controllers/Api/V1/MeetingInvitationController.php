<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MeetingInvitation;
use App\Services\AuditLogger;
use App\Services\Meetings\InvitationService;
use App\Services\Meetings\MeetingAccess;
use App\Services\Meetings\MeetingNotices;
use App\Services\Meetings\MeetingService;
use App\Services\Meetings\MeetingWorkflow;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Who is invited, and how an invited user answers (accept, decline, propose another time). */
class MeetingInvitationController extends Controller
{
    public function __construct(private MeetingAccess $access, private MeetingService $svc, private InvitationService $invites, private MeetingNotices $notices) {}

    public function store(Request $request, string $id)
    {
        $org = app(OrganizationContext::class)->organizationId();
        $m = $this->access->find($request->user(), $id, ['status']);
        $this->access->requireManage($request->user(), $m);
        abort_unless(MeetingWorkflow::editable($m->status->code), 422, __('meetings.not_editable'));
        $d = $request->validate([
            'user_ids' => 'nullable|array|max:300', 'user_ids.*' => ['distinct', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $org)],
            'department_ids' => 'nullable|array|max:20', 'department_ids.*' => ['distinct', Rule::exists('departments', 'id')->where('organization_id', $org)],
            'guests' => 'nullable|array|max:100', 'guests.*.name' => 'required|string|max:150', 'guests.*.email' => 'required|email|max:190',
        ]);
        $added = $this->invites->add($m, $d);
        if ($m->status->code === 'scheduled' && $added) {                      // people added after scheduling are invited at once
            MeetingInvitation::whereIn('id', collect($added)->pluck('id'))->update(['invited_at' => now()]);
            $this->notices->send($m, MeetingInvitation::whereIn('id', collect($added)->pluck('id'))->with('user.language')->get(), 'meeting_invited');
        }
        AuditLogger::log('meeting.invited', $m, [], ['added' => count($added)]);
        return response()->json(['data' => ['added' => count($added), 'warnings' => $this->invites->busyElsewhere($m)]], 201);
    }

    public function destroy(Request $request, string $invitationId)
    {
        $inv = MeetingInvitation::with('meeting.status')->findOrFail($invitationId);
        $m = $this->access->find($request->user(), $inv->meeting_id, ['status']);
        $this->access->requireManage($request->user(), $m);
        abort_unless(MeetingWorkflow::editable($m->status->code), 422, __('meetings.not_editable'));
        abort_if($inv->user_id !== null && $m->isHost($inv->user), 422, __('meetings.cannot_remove_host'));
        AuditLogger::log('meeting.uninvited', $m, ['invitee' => $inv->displayName()], []);
        $inv->delete();
        return response()->noContent();
    }

    /** The signed-in invitee answers for themselves. */
    public function rsvp(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $inv = $this->access->invitation($request->user(), $m);
        abort_unless($inv, 403, 'forbidden');
        $d = $request->validate(['response' => ['required', Rule::in(['accepted', 'declined', 'proposed'])], 'note' => 'nullable|string|max:1000', 'proposed_starts_at' => 'nullable|date']);
        $inv = $this->svc->respond($inv, $d['response'], $d['note'] ?? null, $d['proposed_starts_at'] ?? null, $request->user());
        return ['data' => ['response' => $inv->response->code]];
    }
}
