<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\ListExport;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\EventRowResource;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventRegistration;
use App\Models\LookupType;
use App\Models\User;
use App\Services\Events\EventAccess;
use App\Services\Events\EventRules;
use App\Services\Events\EventService;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/** Events (Appendix A.4): the event, its lifecycle, registrations with capacity and waitlist, check-in, attendance export. */
class EventController extends Controller
{
    use AppliesListQuery;

    public function __construct(private EventService $svc, private EventAccess $access, private OrganizationContext $ctx) {}

    private function org(): string { return $this->ctx->organizationId(); }
    private function lookup(string $type) { return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id'))); }
    private function member() { return Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->org()); }

    private function rules(bool $create): array
    {
        $org = $this->org();
        $req = $create ? 'required' : 'sometimes';
        return [
            'title' => [$req, 'string', 'max:200'], 'description' => 'nullable|string|max:50000', 'type_id' => [$req, $this->lookup('event_type')],
            'starts_at' => [$req, 'date'], 'ends_at' => [$req, 'date'], 'venue' => 'nullable|string|max:255', 'online_url' => 'nullable|url|max:500',
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $org)], 'project_id' => ['nullable', Rule::exists('projects', 'id')->where('organization_id', $org)],
            'organizer_id' => ['sometimes', $this->member()], 'capacity' => 'nullable|integer|min:1|max:1000000', 'waitlist_enabled' => 'sometimes|boolean', 'allow_guests' => 'sometimes|boolean',
            'registration_opens_at' => 'nullable|date', 'registration_closes_at' => 'nullable|date',
            'budget_amount' => 'nullable|numeric|min:0|max:999999999999', 'currency_code' => ['nullable', 'string', 'size:3', 'required_with:budget_amount'],
        ];
    }

    private function find(Request $r, string $id, array $with = ['type', 'status', 'organizer', 'department']): Event { return $this->access->find($r->user(), $id, $with); }

    // ---- list / people ----------------------------------------------------------------------------------------------------------
    public function index(Request $request)
    {
        $u = $request->user();
        $q = $this->access->scopeVisible(Event::query(), $u)->with(['type', 'status', 'organizer', 'department', 'registrations' => fn ($r) => $r->where('user_id', $u->id)->with('status')])
            ->withCount(['registrations as registered_count' => fn ($r) => $r->where('status_id', Lookup::id('event_registration_status', 'registered'))]);
        if ($request->boolean('upcoming')) {
            $q->where('ends_at', '>=', now()->utc()->format('Y-m-d H:i:s'));
        }
        if ($request->boolean('mine')) {
            $q->where(fn ($w) => $w->where('organizer_id', $u->id)->orWhereHas('registrations', fn ($r) => $r->where('user_id', $u->id)->where('status_id', '!=', Lookup::id('event_registration_status', 'cancelled'))));
        }
        return $this->respondList($request, $q, EventRowResource::class, ['code', 'title', 'venue'], ['starts_at', 'title', 'code', 'created_at'], ['type_id', 'status_id', 'department_id'], ['code', 'title', 'status', 'starts_at', 'ends_at', 'venue', 'organizer', 'registered', 'capacity']);
    }

    public function people(Request $request)
    {
        $q = User::query()->whereHas('status', fn ($s) => $s->where('code', 'active'))->whereIn('id', DB::table('organization_memberships')->where('organization_id', $this->org())->pluck('user_id'));
        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        return ['data' => $q->orderBy('name')->limit(100)->get(['id', 'name', 'email'])->map(fn ($u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email]), 'meta' => ['timezone' => AttendanceService::tz($this->org())]];
    }

    // ---- one event --------------------------------------------------------------------------------------------------------------
    private function detail(Request $request, Event $e): array
    {
        $u = $request->user();
        $e->loadMissing(['type', 'status', 'organizer', 'department', 'project']);
        $manages = $this->access->manages($u, $e);
        $status = $e->status->code;
        $mine = EventRegistration::where('event_id', $e->id)->where('user_id', $u->id)->with('status')->first();
        $mineActive = $mine && $mine->status->code !== 'cancelled';
        $open = EventRules::registrationOpen($status, time(), $e->registration_opens_at?->getTimestamp(), $e->registration_closes_at?->getTimestamp());
        $stats = $this->svc->stats($e);
        return ['id' => $e->id, 'code' => $e->code, 'title' => $e->title, 'description' => $e->description, 'type' => $e->type->code, 'type_id' => $e->type_id, 'status' => $status,
            'timezone' => AttendanceService::tz($e->organization_id), 'starts_at' => $e->starts_at->toIso8601String(), 'ends_at' => $e->ends_at->toIso8601String(),
            'venue' => $e->venue, 'online_url' => $e->online_url, 'department_id' => $e->department_id, 'department' => $e->department?->name, 'project' => $e->project ? ['id' => $e->project->id, 'code' => $e->project->code] : null,
            'organizer' => ['id' => $e->organizer->id, 'name' => $e->organizer->name], 'capacity' => $e->capacity, 'waitlist_enabled' => $e->waitlist_enabled, 'allow_guests' => $e->allow_guests,
            'registration_opens_at' => $e->registration_opens_at?->toIso8601String(), 'registration_closes_at' => $e->registration_closes_at?->toIso8601String(),
            'budget_amount' => $e->budget_amount, 'currency_code' => $e->currency_code, 'cancel_reason' => $e->cancel_reason,
            'stats' => $manages ? $stats : ['registered' => $stats['registered'], 'capacity' => $stats['capacity'], 'free_seats' => $stats['free_seats']],
            'public_url' => $manages && $e->allow_guests ? rtrim((string) config('ops.frontend_url'), '/') . '/event-register/' . $e->public_token : null,
            'history' => $manages ? $e->history()->with(['to', 'changedBy'])->get()->map(fn ($h) => ['status' => $h->to->code, 'note' => $h->note, 'by' => $h->changedBy?->name, 'at' => $h->created_at->toIso8601String()]) : [],
            'me' => ['manages' => $manages, 'registration' => $mine ? ['id' => $mine->id, 'status' => $mine->status->code, 'ticket' => $mineActive ? $mine->ticket_token : null, 'checked_in' => $mine->attendance()->exists()] : null,
                'can_register' => $open && !$mineActive, 'can_cancel_registration' => $mineActive && !in_array($status, ['completed', 'cancelled'], true) && !$mine->attendance()->exists(),
                'moves' => $manages ? array_values(EventRules::TRANSITIONS[$status]) : [], 'can_edit' => $manages && EventRules::editable($status)]];
    }

    public function show(Request $request, string $id) { return ['data' => $this->detail($request, $this->find($request, $id))]; }

    public function store(Request $request)
    {
        $e = $this->svc->create($request->validate($this->rules(true)), $request->user());
        return response(['data' => $this->detail($request, $e)], 201);
    }

    public function update(Request $request, string $id)
    {
        $e = $this->find($request, $id);
        $this->access->requireManage($request->user(), $e);
        $e = $this->svc->update($e, $request->validate($this->rules(false)), $request->user());
        return ['data' => $this->detail($request, $e)];
    }

    public function status(Request $request, string $id)
    {
        $e = $this->find($request, $id);
        $this->access->requireManage($request->user(), $e);
        $d = $request->validate(['to' => ['required', Rule::in(array_keys(EventRules::TRANSITIONS))], 'note' => 'nullable|string|max:1000']);
        $e = $this->svc->transition($e, $d['to'], $d['note'] ?? null, $request->user());
        return ['data' => $this->detail($request, $e)];
    }

    public function rotateLink(Request $request, string $id)
    {
        $e = $this->find($request, $id);
        $this->access->requireManage($request->user(), $e);
        return ['data' => $this->detail($request, $this->svc->rotateLink($e, $request->user()))];
    }

    // ---- registrations ----------------------------------------------------------------------------------------------------------
    private function regRow(EventRegistration $r, bool $full): array
    {
        $r->loadMissing(['user:id,name,email', 'status', 'attendance']);
        return ['id' => $r->id, 'name' => $r->displayName(), 'email' => $full ? ($r->user?->email ?? $r->guest_email) : null, 'phone' => $full ? $r->guest_phone : null, 'organization' => $full ? $r->guest_organization : null,
            'guest' => $r->user_id === null, 'status' => $r->status->code, 'registered_at' => $r->registered_at->toIso8601String(), 'checked_in_at' => $r->attendance?->checked_in_at?->toIso8601String(), 'note' => $full ? $r->note : null];
    }

    public function registrations(Request $request, string $id)
    {
        $e = $this->find($request, $id);
        $this->access->requireManage($request->user(), $e);
        $rows = EventRegistration::where('event_id', $e->id)->with(['user:id,name,email', 'status', 'attendance'])->orderBy('registered_at')->get();
        return ['data' => $rows->map(fn ($r) => $this->regRow($r, true)), 'meta' => $this->svc->stats($e)];
    }

    public function register(Request $request, string $id)
    {
        $e = $this->find($request, $id, ['status']);
        $r = $this->svc->register($e, $request->user(), null, $request->user());
        return response(['data' => $this->detail($request, $e->refresh())], 201);
    }

    public function cancelMine(Request $request, string $id)
    {
        $e = $this->find($request, $id, ['status']);
        $r = EventRegistration::where('event_id', $e->id)->where('user_id', $request->user()->id)->where('status_id', '!=', Lookup::id('event_registration_status', 'cancelled'))->firstOrFail();
        $this->svc->cancelRegistration($r, $e, $request->user());
        return ['data' => $this->detail($request, $e->refresh())];
    }

    /** The organizer adds colleagues and/or external guests (skipping the registration window). */
    public function addRegistrations(Request $request, string $id)
    {
        $e = $this->find($request, $id, ['status']);
        $this->access->requireManage($request->user(), $e);
        $d = $request->validate(['user_ids' => 'nullable|array|max:300', 'user_ids.*' => ['distinct', $this->member()], 'guests' => 'nullable|array|max:100',
            'guests.*.name' => 'required|string|max:150', 'guests.*.email' => 'required|email|max:190', 'guests.*.phone' => 'nullable|string|max:40', 'guests.*.organization' => 'nullable|string|max:150']);
        $added = 0;
        $skipped = [];
        foreach (User::whereIn('id', $d['user_ids'] ?? [])->get() as $u) {
            try { $this->svc->register($e, $u, null, $request->user(), true); $added++; } catch (\Illuminate\Validation\ValidationException $ex) { $skipped[] = $u->name; }
        }
        foreach ($d['guests'] ?? [] as $g) {
            try { $this->svc->register($e, null, $g, $request->user(), true); $added++; } catch (\Illuminate\Validation\ValidationException $ex) { $skipped[] = $g['email']; }
        }
        return ['data' => ['added' => $added, 'skipped' => $skipped]];
    }

    public function cancelRegistration(Request $request, string $registrationId)
    {
        $r = EventRegistration::findOrFail($registrationId);
        $e = $this->find($request, $r->event_id, ['status']);
        $this->access->requireManage($request->user(), $e);
        return ['data' => $this->regRow($this->svc->cancelRegistration($r, $e, $request->user()), true)];
    }

    // ---- check-in ---------------------------------------------------------------------------------------------------------------
    public function checkIn(Request $request, string $registrationId)
    {
        $r = EventRegistration::findOrFail($registrationId);
        $e = $this->find($request, $r->event_id, ['status']);
        $this->access->requireManage($request->user(), $e);
        $this->svc->checkIn($e, $r, $request->user(), 'manual', true, $request->input('note'));
        return ['data' => $this->regRow($r->refresh(), true)];
    }

    /** A ticket (QR or link) is read by the organizer's device: the personal code identifies the registration. */
    public function scan(Request $request, string $id)
    {
        $e = $this->find($request, $id, ['status']);
        $this->access->requireManage($request->user(), $e);
        $d = $request->validate(['ticket' => 'required|string|max:200']);
        $token = trim(basename(parse_url($d['ticket'], PHP_URL_PATH) ?: $d['ticket']));        // accepts the whole link or just the code
        abort_unless(EventRules::ticketValid($token), 404);
        $r = EventRegistration::where('event_id', $e->id)->where('ticket_token', $token)->first();
        abort_unless($r, 404);
        $already = $r->attendance()->exists();
        $this->svc->checkIn($e, $r, $request->user(), 'ticket', true);
        return ['data' => $this->regRow($r->refresh(), true) + ['already' => $already]];
    }

    public function undoCheckIn(Request $request, string $registrationId)
    {
        $r = EventRegistration::findOrFail($registrationId);
        $e = $this->find($request, $r->event_id, ['status']);
        $this->access->requireManage($request->user(), $e);
        $d = $request->validate(['note' => 'required|string|min:3|max:500']);
        $this->svc->undoCheckIn($e, $r, $request->user(), $d['note']);
        return ['data' => $this->regRow($r->refresh(), true)];
    }

    public function export(Request $request, string $id)
    {
        abort_unless($this->ctx->can('data.export'), 403);
        $e = $this->find($request, $id);
        $this->access->requireManage($request->user(), $e);
        $format = $request->query('format', 'xlsx');
        abort_unless(in_array($format, ['csv', 'xlsx'], true), 422);
        $keys = ['name', 'email', 'phone', 'organization', 'status', 'registered_at', 'checked_in_at'];
        $rows = EventRegistration::where('event_id', $e->id)->with(['user:id,name,email', 'status', 'attendance'])->orderBy('registered_at')->get()
            ->map(fn ($r) => array_map(fn ($k) => (string) ($this->regRow($r, true)[$k] ?? ''), $keys))->all();
        \App\Services\AuditLogger::log('event.attendance_exported', $e, [], ['format' => $format, 'rows' => count($rows)]);
        return Excel::download(new ListExport($rows, $keys), 'event-' . $e->code . '.' . $format);
    }
}
