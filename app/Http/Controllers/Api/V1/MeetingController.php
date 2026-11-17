<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MeetingRowResource;
use App\Models\Department;
use App\Models\LookupType;
use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\Project;
use App\Models\User;
use App\Services\Meetings\MeetingAccess;
use App\Services\Meetings\MeetingNotices;
use App\Services\Meetings\MeetingService;
use App\Services\OrganizationContext;
use App\Services\Projects\ProjectAccess;
use App\Support\AppliesListQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Meetings and events: schedule, change, invite, run (Guide module C, 6.1-6.4). Permission and data scope are checked on every route. */
class MeetingController extends Controller
{
    use AppliesListQuery;

    public function __construct(private MeetingAccess $access, private MeetingService $svc, private MeetingNotices $notices) {}

    private function org(): string { return app(OrganizationContext::class)->organizationId(); }

    private function lookup(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    private function member() { return Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->org()); }

    private function rules(bool $create): array
    {
        $org = $this->org();
        $req = $create ? 'required' : 'sometimes';
        return [
            'title' => [$req, 'string', 'max:200'], 'purpose' => 'nullable|string|max:20000',
            'type_id' => [$req, $this->lookup('meeting_type')], 'confidentiality_id' => ['nullable', $this->lookup('meeting_confidentiality')],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $org)],
            'project_id' => ['nullable', Rule::exists('projects', 'id')->where('organization_id', $org)],
            'chair_id' => ['nullable', $this->member()], 'secretary_id' => ['nullable', $this->member()], 'facilitator_id' => ['nullable', $this->member()],
            'starts_at' => [$req, 'date'], 'ends_at' => [$req, 'date'],
            'room_id' => ['nullable', Rule::exists('meeting_rooms', 'id')->where('organization_id', $org)],
            'venue' => 'nullable|string|max:255', 'online_url' => 'nullable|url|max:500', 'rsvp_deadline' => 'nullable|date',
            'reason' => 'nullable|string|max:1000',
        ];
    }

    private function participantRules(): array
    {
        return [
            'participants' => 'nullable|array', 'participants.user_ids' => 'nullable|array|max:300', 'participants.user_ids.*' => ['distinct', $this->member()],
            'participants.department_ids' => 'nullable|array|max:20', 'participants.department_ids.*' => ['distinct', Rule::exists('departments', 'id')->where('organization_id', $this->org())],
            'participants.guests' => 'nullable|array|max:100', 'participants.guests.*.name' => 'required|string|max:150', 'participants.guests.*.email' => 'required|email|max:190',
        ];
    }

    private function checkProject(Request $request, ?string $projectId): void
    {
        if ($projectId) {
            app(ProjectAccess::class)->find($request->user(), $projectId);          // 404 when the project is not visible to this user
        }
    }

    // ---- list --------------------------------------------------------------------------------------------------------------
    public function index(Request $request)
    {
        $u = $request->user();
        $q = Meeting::query()->visibleTo($u)->with(['type', 'status', 'confidentiality', 'room', 'department', 'organizer', 'chair', 'agendaStatus',
            'invitations' => fn ($i) => $i->where('user_id', $u->id)->with('response')]);
        if ($from = $request->query('from')) {
            $q->where('ends_at', '>=', CarbonImmutable::parse($from)->utc()->format('Y-m-d H:i:s'));
        }
        if ($to = $request->query('to')) {
            $q->where('starts_at', '<=', CarbonImmutable::parse($to)->utc()->format('Y-m-d H:i:s'));
        }
        if ($status = $request->query('status')) {
            $q->whereHas('status', fn ($s) => $s->whereIn('code', explode(',', (string) $status)));
        }
        if ($request->boolean('mine')) {
            $q->where(fn ($w) => $w->where('organizer_id', $u->id)->orWhere('chair_id', $u->id)->orWhere('secretary_id', $u->id)->orWhereHas('invitations', fn ($i) => $i->where('user_id', $u->id)));
        }
        return $this->respondList($request, $q, MeetingRowResource::class, ['code', 'title'], ['starts_at', 'title', 'code', 'created_at'], ['type_id', 'department_id', 'room_id'],
            ['code', 'title', 'status', 'starts_at', 'ends_at', 'room', 'organizer']);
    }

    // ---- detail ------------------------------------------------------------------------------------------------------------
    private function detail(Request $request, Meeting $m): array
    {
        $u = $request->user();
        $m->load(['type', 'status', 'confidentiality', 'agendaStatus', 'department', 'project', 'organizer', 'chair', 'secretary', 'facilitator', 'room', 'recurrence.frequency',
            'agendaItems.presenter', 'agendaItems.priority', 'invitations.user', 'invitations.response', 'invitations.department', 'statusHistory.to', 'statusHistory.changedBy', 'scheduleHistory.changedBy', 'scheduleHistory.oldRoom']);
        $manage = $this->access->canManage($u, $m);
        $mine = $m->invitations->firstWhere('user_id', $u->id);
        $code = $m->status->code;
        $editable = $manage && in_array($code, ['draft', 'scheduled'], true);

        return ['data' => [
            'id' => $m->id, 'code' => $m->code, 'title' => $m->title, 'purpose' => $m->purpose, 'type' => $m->type?->code, 'type_id' => $m->type_id, 'status' => $code,
            'confidential' => $m->isConfidential(), 'confidentiality_id' => $m->confidentiality_id, 'agenda_status' => $m->agendaStatus?->code,
            'agenda_approved_at' => $m->agenda_approved_at?->toIso8601String(), 'starts_at' => $m->starts_at->toIso8601String(), 'ends_at' => $m->ends_at->toIso8601String(),
            'rsvp_deadline' => $m->rsvp_deadline?->toIso8601String(), 'started_at' => $m->started_at?->toIso8601String(), 'ended_at' => $m->ended_at?->toIso8601String(),
            'cancel_reason' => $m->cancel_reason, 'room' => $m->room ? ['id' => $m->room->id, 'name' => $m->room->name, 'capacity' => $m->room->capacity] : null,
            'venue' => $m->venue, 'online_url' => $m->online_url,
            'department' => $m->department ? ['id' => $m->department->id, 'name' => $m->department->name] : null,
            'project' => $m->project ? ['id' => $m->project->id, 'code' => $m->project->code, 'name' => $m->project->name] : null,
            'organizer' => $this->person($m->organizer), 'chair' => $this->person($m->chair), 'secretary' => $this->person($m->secretary), 'facilitator' => $this->person($m->facilitator),
            'series' => $m->recurrence ? ['frequency' => $m->recurrence->frequency?->code, 'interval' => $m->recurrence->interval_count, 'count' => Meeting::where('recurrence_id', $m->recurrence_id)->count()] : null,
            'agenda' => $m->agendaItems->filter(fn ($i) => $this->access->canSeeItem($u, $m, $i))->values()->map(fn ($i) => [
                'id' => $i->id, 'position' => $i->position, 'title' => $i->title, 'description' => $i->description, 'presenter' => $i->presenter ? $this->person($i->presenter) : null,
                'presenter_name' => $i->presenter_name, 'duration_minutes' => $i->duration_minutes, 'priority' => $i->priority?->code, 'expected_decision' => $i->expected_decision,
                'is_confidential' => $i->is_confidential]),
            'invitations' => $m->invitations->map(fn ($i) => [
                'id' => $i->id, 'name' => $i->displayName(), 'is_guest' => $i->user_id === null, 'response' => $i->response?->code,
                'department' => $i->department?->name, 'responded_at' => $i->responded_at?->toIso8601String(),
            ] + ($manage || $this->access->canChair($u, $m) ? ['email' => $i->email(), 'note' => $i->response_note, 'proposed_starts_at' => $i->proposed_starts_at?->toIso8601String()] : []))->values(),
            'history' => $m->statusHistory->map(fn ($h) => ['status' => $h->to?->code, 'note' => $h->note, 'by' => $h->changedBy?->name, 'at' => $h->created_at->toIso8601String()])->values(),
            'schedule_history' => $m->scheduleHistory->map(fn ($h) => ['starts_at' => $h->old_starts_at->toIso8601String(), 'ends_at' => $h->old_ends_at->toIso8601String(),
                'room' => $h->oldRoom?->name, 'venue' => $h->old_venue, 'reason' => $h->reason, 'by' => $h->changedBy?->name, 'at' => $h->created_at->toIso8601String()])->values(),
            'me' => [
                'is_organizer' => $m->organizer_id === $u->id, 'is_chair' => $m->chair_id === $u->id, 'is_secretary' => $m->secretary_id === $u->id, 'invitation_id' => $mine?->id, 'my_response' => $mine?->response?->code,
                'can_manage' => $manage, 'can_edit' => $editable, 'can_chair' => $this->access->canChair($u, $m),
                'can_rsvp' => $mine !== null && $code === 'scheduled', 'can_schedule' => $editable && $code === 'draft', 'can_cancel' => $editable,
                'can_start' => $manage && $code === 'scheduled', 'can_hold' => $manage && in_array($code, ['scheduled', 'in_progress'], true),
            ],
        ]];
    }

    private function person(?User $u): ?array { return $u ? ['id' => $u->id, 'name' => $u->name] : null; }

    public function show(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $this->access->auditOverride($request->user(), $m);
        return $this->detail($request, $m);
    }

    // ---- write -------------------------------------------------------------------------------------------------------------
    public function store(Request $request)
    {
        $d = $request->validate($this->rules(true) + $this->participantRules() + [
            'recurrence' => 'nullable|array', 'recurrence.frequency' => ['required_with:recurrence', Rule::in(['daily', 'weekly', 'monthly'])],
            'recurrence.interval' => 'nullable|integer|min:1|max:52', 'recurrence.ends_on' => 'nullable|date|required_without:recurrence.occurrences',
            'recurrence.occurrences' => 'nullable|integer|min:2|max:52',
        ]);
        $this->checkProject($request, $d['project_id'] ?? null);
        $r = $this->svc->create($d, $request->user());
        $payload = $this->detail($request, $r['meetings'][0]);
        $payload['meta'] = ['created' => count($r['meetings']), 'warnings' => $r['warnings']];
        return response()->json($payload, 201);
    }

    public function update(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id, ['status']);
        $this->access->requireManage($request->user(), $m);
        $d = $request->validate($this->rules(false));
        $this->checkProject($request, $d['project_id'] ?? null);
        $r = $this->svc->update($m, $d, $request->user());
        return ['data' => $this->detail($request, $r['meeting'])['data'], 'meta' => ['warnings' => $r['warnings']]];
    }

    public function schedule(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $this->access->requireManage($request->user(), $m);
        return $this->detail($request, $this->svc->schedule($m, $request->user()));
    }

    public function cancel(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $this->access->requireManage($request->user(), $m);
        $d = $request->validate(['reason' => 'required|string|min:3|max:1000']);
        return $this->detail($request, $this->svc->cancel($m, $d['reason'], $request->user()));
    }

    public function start(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $this->access->requireManage($request->user(), $m);
        return $this->detail($request, $this->svc->start($m, $request->user()));
    }

    public function hold(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $this->access->requireManage($request->user(), $m);
        return $this->detail($request, $this->svc->hold($m, $request->user()));
    }

    // ---- calendar file -----------------------------------------------------------------------------------------------------
    public function ics(Request $request, string $id)
    {
        $m = $this->access->find($request->user(), $id);
        $inv = $this->access->invitation($request->user(), $m);
        return response($this->notices->ics($m, $inv), 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Content-Disposition' => 'attachment; filename="' . $m->code . '.ics"']);
    }

    /** People who can be picked as chair, secretary, guests of honour or invitees: active members of the organization (name and e-mail only). */
    public function people(Request $request)
    {
        $q = User::query()->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereIn('id', \Illuminate\Support\Facades\DB::table('organization_memberships')->where('organization_id', $this->org())->pluck('user_id'));
        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        return ['data' => $q->orderBy('name')->limit(100)->get(['id', 'name', 'email'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email])];
    }
}
