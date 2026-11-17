<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Meetings\MeetingNotices;
use App\Services\Meetings\MeetingService;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The reply page for people without an account (external guests), reached through the personal link in their invitation.
 * Unauthenticated and rate limited. The link is the credential: it shows the minimum (title, time, place), never the agenda,
 * the other invitees or anything of a confidential meeting beyond a generic title.
 */
class MeetingPublicController extends Controller
{
    private function find(string $token): MeetingInvitation
    {
        abort_unless(strlen($token) >= 40, 404);
        $inv = MeetingInvitation::withoutGlobalScopes()->where('token', $token)->whereNull('user_id')->first();
        abort_unless($inv, 404);
        $m = Meeting::withoutGlobalScopes()->with(['status', 'confidentiality', 'room', 'organizer'])->find($inv->meeting_id);
        abort_unless($m && $m->status->code !== 'draft', 404);
        app(OrganizationContext::class)->resolveForOrganization(\App\Models\Organization::findOrFail($inv->organization_id));
        return $inv->setRelation('meeting', $m);
    }

    private function shape(MeetingInvitation $inv): array
    {
        $m = $inv->meeting;
        $secret = $m->confidentiality?->code === 'confidential';
        $tz = AttendanceService::tz($m->organization_id);
        $inv->loadMissing('response');
        return ['data' => ['title' => $secret ? __('meetings.confidential_meeting') : $m->title, 'status' => $m->status->code, 'timezone' => $tz,
            'starts_at' => $m->starts_at->toIso8601String(), 'ends_at' => $m->ends_at->toIso8601String(), 'where' => $secret ? null : app(MeetingNotices::class)->whereText($m),
            'organizer' => $m->organizer?->name, 'guest' => $inv->guest_name, 'response' => $inv->response?->code, 'can_respond' => $m->status->code === 'scheduled']];
    }

    public function show(string $token)
    {
        return $this->shape($this->find($token));
    }

    public function respond(Request $request, string $token, MeetingService $svc)
    {
        $inv = $this->find($token);
        $d = $request->validate(['response' => ['required', Rule::in(['accepted', 'declined', 'proposed'])], 'note' => 'nullable|string|max:1000', 'proposed_starts_at' => 'nullable|date']);
        $svc->respond($inv, $d['response'], $d['note'] ?? null, $d['proposed_starts_at'] ?? null, null);
        return $this->shape($this->find($token));
    }

    public function ics(string $token, MeetingNotices $notices)
    {
        $inv = $this->find($token);
        return response($notices->ics($inv->meeting, $inv), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $inv->meeting->code . '.ics"']);
    }
}
