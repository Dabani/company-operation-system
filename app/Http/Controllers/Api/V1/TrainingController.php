<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TrainingSessionResource;
use App\Models\Membership;
use App\Models\TrainingAttendee;
use App\Models\TrainingSession;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\ObligationService;
use App\Services\OrganizationContext;
use App\Services\SettingsService;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Models\LookupType;

/** Mandatory training: sessions, attendance (recorded by the trainer, confirmed by the participant), retention, quarterly report. */
class TrainingController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array { return ['topic.translations', 'status.translations', 'attendees']; }

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    private function memberRule() { return Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->ctx->organizationId()); }

    private function visible()
    {
        $q = TrainingSession::query();
        if (!$this->ctx->can('training.manage')) $q->whereHas('attendees', fn ($a) => $a->where('user_id', request()->user()->id));
        return $q;
    }

    public function index(Request $request)
    {
        $q = $this->visible()->with($this->with());
        if ($y = $request->query('year')) $q->whereYear('session_date', (int) $y);
        return $this->respondList($request, $q, TrainingSessionResource::class, ['title', 'trainer'], ['session_date', 'title'], ['topic_id', 'status_id'], ['title', 'topic_label', 'status_label', 'session_date', 'trainer', 'invited_count', 'attended_count']);
    }

    private function people(TrainingSession $s): array
    {
        $members = Membership::with('department')->where('organization_id', $this->ctx->organizationId())->whereIn('user_id', $s->attendees->pluck('user_id'))->get()->keyBy('user_id');
        $me = request()->user()->id; $manage = $this->ctx->can('training.manage');
        return $s->attendees->load('user')->filter(fn ($a) => $manage || $a->user_id === $me)->map(fn ($a) => [
            'user_id' => $a->user_id, 'name' => $a->user->name, 'department' => $members[$a->user_id]?->department?->name, 'attended' => $a->attended, 'signed_at' => $a->signed_at?->toIso8601String(),
        ])->values()->all();
    }

    public function show(Request $request, string $id)
    {
        $s = $this->visible()->with($this->with())->findOrFail($id);
        return response()->json(['data' => array_merge((new TrainingSessionResource($s))->resolve($request), ['notes' => $s->notes, 'attendees' => $this->people($s)])]);
    }

    private function rules(): array
    {
        return [
            'title' => 'required|string|max:200', 'topic_id' => ['required', $this->lookupRule('training_topic')], 'session_date' => 'required|date',
            'duration_minutes' => 'nullable|integer|min:5|max:1440', 'trainer' => 'nullable|string|max:150', 'venue' => 'nullable|string|max:150',
            'is_mandatory' => 'boolean', 'notes' => 'nullable|string|max:20000',
        ];
    }

    private function retainUntil(Carbon $date): string
    {
        return $date->copy()->addYears(SettingsService::get($this->ctx->organizationId(), 'training.retention_years'))->toDateString();
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules() + ['attendee_ids' => 'array', 'attendee_ids.*' => ['string', $this->memberRule()], 'invite_all' => 'boolean']);
        $ids = collect($d['attendee_ids'] ?? []);
        if (!empty($d['invite_all'])) {
            $ids = Membership::where('organization_id', $this->ctx->organizationId())->whereHas('status', fn ($s) => $s->where('code', 'active'))->pluck('user_id');
        }
        $s = DB::transaction(function () use ($d, $ids, $request) {
            $s = TrainingSession::create(collect($d)->except(['attendee_ids', 'invite_all'])->all() + [
                'status_id' => Lookup::id('training_status', 'planned'), 'created_by' => $request->user()->id, 'retain_until' => $this->retainUntil(Carbon::parse($d['session_date'])),
            ]);
            foreach ($ids->unique() as $uid) TrainingAttendee::create(['session_id' => $s->id, 'user_id' => $uid]);
            return $s;
        });
        AuditLogger::log('training.created', $s, [], ['title' => $s->title, 'invited' => (string) $ids->unique()->count()]);
        $this->invite($s, $ids->unique()->reject(fn ($u) => $u === $request->user()->id));
        return (new TrainingSessionResource($s->load($this->with())))->response()->setStatusCode(201);
    }

    private function invite(TrainingSession $s, $userIds): void
    {
        if ($userIds->isEmpty()) return;
        $users = \App\Models\User::with('language')->whereIn('id', $userIds)->get();
        Notifier::send($users, 'training_invited', ['title' => $s->title, 'date' => $s->session_date->format('Y-m-d H:i')], null, ['in_app', 'email'], '/compliance/training?open=' . $s->id, $this->ctx->organizationId());
    }

    public function update(Request $request, string $id)
    {
        $s = TrainingSession::with($this->with())->findOrFail($id);
        abort_if($s->status->code !== 'planned', 422, __('api.training_locked'));
        $d = $request->validate($this->rules());
        $s->update($d + ['retain_until' => $this->retainUntil(Carbon::parse($d['session_date']))]);
        AuditLogger::log('training.updated', $s, [], ['title' => $s->title]);
        return new TrainingSessionResource($s->refresh()->load($this->with()));
    }

    public function setAttendees(Request $request, string $id)
    {
        $s = TrainingSession::with($this->with())->findOrFail($id);
        abort_if($s->status->code === 'cancelled', 422, __('api.training_locked'));
        $d = $request->validate(['user_ids' => 'present|array', 'user_ids.*' => ['string', $this->memberRule()]]);
        $want = collect($d['user_ids'])->unique();
        $have = $s->attendees->pluck('user_id');

        $new = $want->diff($have);
        foreach ($new as $uid) TrainingAttendee::create(['session_id' => $s->id, 'user_id' => $uid]);
        // Only people whose attendance has not been recorded can be removed (evidence is never deleted).
        TrainingAttendee::where('session_id', $s->id)->whereNull('attended')->whereNull('signed_at')->whereNotIn('user_id', $want)->delete();
        AuditLogger::log('training.attendees_changed', $s, [], ['added' => (string) $new->count()]);
        $this->invite($s, $new->reject(fn ($u) => $u === $request->user()->id));
        return $this->show($request, $id);
    }

    /** Trainer records who attended. */
    public function mark(Request $request, string $id)
    {
        $s = TrainingSession::with('status')->findOrFail($id);
        abort_if($s->status->code === 'cancelled', 422, __('api.training_locked'));
        $d = $request->validate(['user_id' => 'required|string', 'attended' => 'required|boolean']);
        $a = TrainingAttendee::where('session_id', $s->id)->where('user_id', $d['user_id'])->firstOrFail();
        $a->update(['attended' => $d['attended'], 'recorded_by' => $request->user()->id]);
        AuditLogger::log('training.attendance_recorded', $s, [], ['user' => $d['user_id'], 'attended' => $d['attended'] ? 'yes' : 'no']);
        return response()->json(['data' => ['user_id' => $a->user_id, 'attended' => $a->attended]]);
    }

    /** Participant's own confirmation = their "signature" on the attendance register. */
    public function confirm(Request $request, string $id)
    {
        $s = TrainingSession::with('status')->findOrFail($id);
        $a = TrainingAttendee::where('session_id', $s->id)->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($s->status->code === 'held' || ($s->status->code === 'planned' && $s->session_date->isToday()), 422, __('api.training_not_open'));
        $a->update(['attended' => true, 'signed_at' => now()]);
        AuditLogger::log('training.attendance_confirmed', $s, [], ['user' => $request->user()->id]);
        return $this->show($request, $id);
    }

    public function complete(Request $request, string $id)
    {
        $s = TrainingSession::with($this->with())->findOrFail($id);
        abort_unless($s->status->code === 'planned', 422, __('api.training_locked'));
        $s->update(['status_id' => Lookup::id('training_status', 'held')]);
        AuditLogger::log('training.held', $s, [], ['title' => $s->title]);
        if ($s->is_mandatory) ObligationService::autoComplete($this->ctx->organizationId(), 'training_quarterly', $request->user(), $s->title);
        return $this->show($request, $id);
    }

    public function cancel(Request $request, string $id)
    {
        $s = TrainingSession::with($this->with())->findOrFail($id);
        abort_unless($s->status->code === 'planned', 422, __('api.training_locked'));
        $s->update(['status_id' => Lookup::id('training_status', 'cancelled')]);
        AuditLogger::log('training.cancelled', $s, [], ['title' => $s->title]);
        return $this->show($request, $id);
    }

    /** Attendance register (one row per participant per session) - list, export, print. */
    public function register(Request $request)
    {
        $q = TrainingAttendee::with(['session.topic.translations', 'user'])->whereHas('session', fn ($s) => $s->whereHas('status', fn ($st) => $st->where('code', '!=', 'cancelled')));
        if (!$this->ctx->can('training.manage') && !$this->ctx->can('compliance.view')) $q->where('user_id', $request->user()->id);
        if ($y = $request->query('year')) $q->whereHas('session', fn ($s) => $s->whereYear('session_date', (int) $y));
        if ($sid = $request->query('session_id')) $q->where('session_id', $sid);
        if ($s = trim((string) $request->query('search', ''))) $q->where(fn ($w) => $w->whereHas('user', fn ($u) => $u->where('name', 'like', "%$s%"))->orWhereHas('session', fn ($x) => $x->where('title', 'like', "%$s%")));
        $q->orderByDesc(TrainingSession::select('session_date')->whereColumn('training_sessions.id', 'training_attendees.session_id'));

        $lang = $request->user()->language_id;
        $members = Membership::with('department')->where('organization_id', $this->ctx->organizationId())->get()->keyBy('user_id');
        $row = fn ($a) => [
            'id' => (string) $a->id, 'date' => $a->session->session_date->toDateString(), 'topic' => $a->session->topic->label($lang), 'title' => $a->session->title,
            'participant' => $a->user->name, 'department' => $members[$a->user_id]?->department?->name, 'attended' => $a->attended, 'signed_at' => $a->signed_at?->toIso8601String(),
            'attended_label' => $a->attended === null ? '' : ($a->attended ? 'yes' : 'no'), 'retain_until' => $a->session->retain_until?->toDateString(),
        ];

        if ($fmt = $request->query('export')) {
            abort_unless($this->ctx->can('data.export'), 403);
            $keys = ['date', 'topic', 'title', 'participant', 'department', 'attended_label', 'signed_at', 'retain_until'];
            $rows = $q->limit(10000)->get()->map(fn ($a) => array_map(fn ($k) => $row($a)[$k] ?? '', $keys))->all();
            return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\ListExport($rows, $keys), 'training-attendance-' . now()->format('Ymd-His') . '.' . $fmt);
        }
        $p = $q->paginate(min(max((int) $request->query('per_page', 25), 1), 100));
        return response()->json(['data' => $p->getCollection()->map($row)->values(), 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]]);
    }

    private function quarterStats(int $year, int $quarter): array
    {
        $from = Carbon::create($year, ($quarter - 1) * 3 + 1, 1)->startOfDay(); $to = $from->copy()->endOfQuarter();
        $sessions = TrainingSession::with(['topic.translations', 'attendees'])->whereHas('status', fn ($s) => $s->where('code', 'held'))->whereBetween('session_date', [$from, $to])->get();
        $mandatory = $sessions->where('is_mandatory', true);
        $invited = $sessions->sum(fn ($s) => $s->attendees->count()); $attended = $sessions->sum(fn ($s) => $s->attendees->where('attended', true)->count());
        return ['quarter' => $quarter, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'sessions_held' => $sessions->count(), 'mandatory_held' => $mandatory->count(),
            'invited' => $invited, 'attended' => $attended, 'rate' => $invited ? round($attended / $invited * 100) : null, 'sessions' => $sessions, 'mandatory' => $mandatory];
    }

    /** Report for senior management: per quarter, with the people who have not completed mandatory training in the selected quarter. */
    public function report(Request $request)
    {
        $d = $request->validate(['year' => 'required|integer|min:2000|max:2100', 'quarter' => 'nullable|integer|min:1|max:4']);
        $lang = $request->user()->language_id;
        $quarters = collect([1, 2, 3, 4])->map(fn ($q) => Arr::except($this->quarterStats($d['year'], $q), ['sessions', 'mandatory']))->all();

        $detail = null;
        if (!empty($d['quarter'])) {
            $st = $this->quarterStats($d['year'], $d['quarter']);
            $attendedIds = $st['mandatory']->flatMap(fn ($s) => $s->attendees->where('attended', true)->pluck('user_id'))->unique();
            $members = Membership::with(['user', 'department'])->where('organization_id', $this->ctx->organizationId())->whereHas('status', fn ($s) => $s->where('code', 'active'))->get();
            $detail = [
                'sessions' => $st['sessions']->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'topic' => $s->topic->label($lang), 'date' => $s->session_date->toDateString(), 'invited' => $s->attendees->count(), 'attended' => $s->attendees->where('attended', true)->count()])->values(),
                'gaps' => $st['mandatory_held'] > 0 ? $members->reject(fn ($m) => $attendedIds->contains($m->user_id))->map(fn ($m) => ['id' => $m->user_id, 'name' => $m->user->name, 'department' => $m->department?->name])->values() : [],
            ];
        }
        return response()->json(['data' => ['year' => $d['year'], 'quarters' => $quarters, 'detail' => $detail]]);
    }

    public function submitReport(Request $request)
    {
        $d = $request->validate(['year' => 'required|integer|min:2000|max:2100', 'quarter' => 'required|integer|min:1|max:4', 'description' => 'nullable|string|max:20000']);
        $data = json_decode($this->report($request)->getContent(), true)['data'];
        $q = $data['quarters'][$d['quarter'] - 1]; $detail = $data['detail'];

        $esc = fn ($s) => e((string) $s);
        $html = '<p><strong>' . $esc("{$d['year']} Q{$d['quarter']}") . '</strong>: ' . $q['sessions_held'] . ' session(s) held (' . $q['mandatory_held'] . ' mandatory); '
            . $q['attended'] . '/' . $q['invited'] . ' attended' . ($q['rate'] !== null ? ' (' . $q['rate'] . '%)' : '') . '.</p>';
        if (!empty($detail['sessions'])) $html .= '<ul>' . collect($detail['sessions'])->map(fn ($s) => '<li>' . $esc($s['date'] . ' - ' . $s['title'] . ' (' . $s['attended'] . '/' . $s['invited'] . ')') . '</li>')->implode('') . '</ul>';
        if (!empty($detail['gaps'])) $html .= '<p>' . count($detail['gaps']) . ' person(s) without attendance at a mandatory session:</p><ul>' . collect($detail['gaps'])->map(fn ($g) => '<li>' . $esc($g['name'] . ($g['department'] ? ' (' . $g['department'] . ')' : '')) . '</li>')->implode('') . '</ul>';
        if (!empty($d['description'])) $html .= $d['description'];

        $r = ManagementReportController::submit('training', "Training report {$d['year']} Q{$d['quarter']}", "{$d['year']} Q{$d['quarter']}", $html, $request->user());
        return response()->json(['data' => ['id' => $r->id, 'recipients' => $r->recipients_count]], 201);
    }
}
