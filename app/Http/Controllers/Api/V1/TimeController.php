<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Services\OrganizationContext;
use App\Services\Projects\ProjectAccess;
use App\Services\SettingsService;
use App\Services\Time\TimerMath;
use App\Services\Time\TimesheetService;
use App\Services\Time\TimeService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TimeController extends Controller
{
    public function __construct(private TimeService $time, private TimesheetService $sheets, private OrganizationContext $ctx, private ProjectAccess $access) {}

    private function row(TimeEntry $e): array
    {
        return [
            'id' => $e->id, 'project_id' => $e->project_id, 'project' => $e->project?->code, 'task_id' => $e->task_id,
            'task' => $e->task ? "{$e->project?->code}-{$e->task->number} {$e->task->title}" : null, 'work_date' => $e->work_date->toDateString(),
            'started_at' => $e->started_at->toIso8601String(), 'ended_at' => $e->ended_at?->toIso8601String(), 'duration_seconds' => $e->duration_seconds,
            'is_manual' => $e->is_manual, 'manual_reason' => $e->manual_reason, 'is_billable' => $e->is_billable, 'description' => $e->description,
            'status' => $e->status->code, 'adjusts_entry_id' => $e->adjusts_entry_id, 'auto_stopped' => $e->auto_stopped,
            'editable' => $e->status->code === 'draft' && $e->ended_at !== null,
        ];
    }

    // ---- timer -----------------------------------------------------------------------------------------------------------
    public function timer(Request $request)
    {
        $e = $this->time->current($request->user());
        $org = $this->ctx->organizationId();
        $cfg = ['max_hours' => SettingsService::get($org, 'time.max_hours'), 'idle_warning_minutes' => SettingsService::get($org, 'time.idle_warning_minutes'),
            'manual_allowed' => SettingsService::get($org, 'time.manual_allowed')];
        if (!$e) return ['data' => null, 'server_now' => gmdate('c'), 'settings' => $cfg];
        $segs = $e->segments->map(fn ($s) => ['start' => $s->started_at->format('Y-m-d H:i:s'), 'end' => $s->ended_at?->format('Y-m-d H:i:s')])->all();
        $running = collect($segs)->contains(fn ($s) => $s['end'] === null);
        return ['data' => [
            'id' => $e->id, 'state' => $running ? 'running' : 'paused', 'project_id' => $e->project_id, 'project' => $e->project?->code,
            'task_id' => $e->task_id, 'task' => $e->task ? "{$e->project?->code}-{$e->task->number} {$e->task->title}" : null, 'description' => $e->description,
            'is_billable' => $e->is_billable, 'elapsed_seconds' => TimerMath::seconds($segs, gmdate('Y-m-d H:i:s')),
            'segment_started_at' => $running ? collect($e->segments)->first(fn ($s) => $s->ended_at === null)->started_at->toIso8601String() : null,
        ], 'server_now' => gmdate('c'), 'settings' => $cfg];
    }

    public function start(Request $request)
    {
        $d = $request->validate(['project_id' => 'required|string', 'task_id' => 'nullable|string', 'description' => 'nullable|string|max:500', 'is_billable' => 'nullable|boolean', 'stop_existing' => 'boolean']);
        $this->time->start($request->user(), $d['project_id'], $d['task_id'] ?? null, $d['description'] ?? null, $d['is_billable'] ?? null, $d['stop_existing'] ?? false);
        return response()->json($this->timer($request), 201);
    }

    public function pause(Request $request) { $this->time->pause($request->user()); return $this->timer($request); }
    public function resume(Request $request) { $this->time->resume($request->user()); return $this->timer($request); }
    public function stop(Request $request) { $this->time->stop($request->user()); return $this->timer($request); }

    // ---- entries ---------------------------------------------------------------------------------------------------------
    public function week(Request $request)
    {
        $d = $request->validate(['date' => 'nullable|date_format:Y-m-d']);
        $u = $request->user();
        $ts = $this->sheets->week($u, $d['date'] ?? $this->time->workDate(gmdate('Y-m-d H:i:s')));
        $start = $ts->week_start->toDateString();
        $entries = TimeEntry::with(['status', 'project:id,code', 'task:id,number,title'])->where('user_id', $u->id)
            ->whereBetween('work_date', [$start, date('Y-m-d', strtotime("$start +6 days"))])->orderBy('started_at')->get();
        return ['data' => [
            'week_start' => $start, 'timesheet' => ['id' => $ts->id, 'status' => $ts->status->code, 'total_seconds' => $ts->total_seconds, 'approval_request_id' => $ts->approval_request_id],
            'entries' => $entries->map(fn ($e) => $this->row($e)), 'total_seconds' => (int) $entries->sum('duration_seconds'),
            'billable_seconds' => (int) $entries->where('is_billable', true)->sum('duration_seconds'),
        ]];
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'project_id' => 'required|string', 'task_id' => 'nullable|string', 'started_at' => 'required|date_format:Y-m-d H:i:s', 'ended_at' => 'required|date_format:Y-m-d H:i:s',
            'reason' => 'required|string|min:5|max:1000', 'is_billable' => 'nullable|boolean', 'description' => 'nullable|string|max:500',
        ]);
        return response()->json(['data' => $this->row($this->time->manual($request->user(), $d)->load(['status', 'project:id,code', 'task:id,number,title']))], 201);
    }

    public function update(Request $request, string $id)
    {
        $e = TimeEntry::with('status')->where('user_id', $request->user()->id)->findOrFail($id);
        $d = $request->validate(['description' => 'nullable|string|max:500', 'is_billable' => 'sometimes|boolean', 'task_id' => 'nullable|string',
            'started_at' => 'sometimes|date_format:Y-m-d H:i:s', 'ended_at' => 'required_with:started_at|date_format:Y-m-d H:i:s', 'reason' => 'nullable|string|max:1000']);
        return ['data' => $this->row($this->time->update($e, $request->user(), $d)->load(['status', 'project:id,code', 'task:id,number,title']))];
    }

    public function destroy(Request $request, string $id)
    {
        $this->time->delete(TimeEntry::with('status')->where('user_id', $request->user()->id)->findOrFail($id), $request->user());
        return response()->noContent();
    }

    /** perm: time.adjust */
    public function adjust(Request $request, string $id)
    {
        $d = $request->validate(['seconds' => 'required|integer', 'reason' => 'required|string|min:5|max:1000', 'work_date' => 'nullable|date_format:Y-m-d']);
        $orig = TimeEntry::with('status')->findOrFail($id);
        $p = Project::findOrFail($orig->project_id);
        abort_unless($this->access->level($request->user(), $p) === 'manager' || $this->ctx->can('time.view_team'), 404);
        return response()->json(['data' => $this->row($this->time->adjust($orig, $d['seconds'], $d['reason'], $request->user(), $d['work_date'] ?? null)->load(['status', 'project:id,code', 'task:id,number,title']))], 201);
    }

    // ---- timesheets --------------------------------------------------------------------------------------------------------
    public function submit(Request $request, string $week)
    {
        validator(['week' => $week], ['week' => 'required|date_format:Y-m-d'])->validate();
        $ts = $this->sheets->submit($request->user(), $week);
        return ['data' => ['id' => $ts->id, 'status' => $ts->status->code, 'total_seconds' => $ts->total_seconds, 'approval_request_id' => $ts->approval_request_id]];
    }

    /** Team view: timesheets of people the viewer may see through department scope. perm: time.view_team */
    public function team(Request $request)
    {
        $u = $request->user();
        $people = Employee::query()->visibleTo($u)->whereNotNull('user_id')->pluck('user_id')->push($u->id)->unique();
        $q = Timesheet::with(['user:id,name', 'status'])->whereIn('user_id', $people)->orderByDesc('week_start');
        if ($request->filled('status')) $q->whereHas('status', fn ($s) => $s->where('code', $request->query('status')));
        return ['data' => $q->limit(200)->get()->map(fn ($t) => ['id' => $t->id, 'user' => $t->user?->name, 'week_start' => $t->week_start->toDateString(), 'status' => $t->status->code,
            'total_seconds' => $t->total_seconds, 'approval_request_id' => $t->approval_request_id])];
    }

    /** Hours per person and task for a project. perm: project manager or time.view_team */
    public function projectSummary(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level) || $this->ctx->can('time.view_team'), 403, 'forbidden');
        $rows = TimeEntry::where('project_id', $p->id)->join('lookup_values as s', 's.id', '=', 'time_entries.status_id')->join('users as u', 'u.id', '=', 'time_entries.user_id')
            ->selectRaw('u.name as person, s.code as status, time_entries.is_billable as billable, sum(duration_seconds) as seconds')->groupBy('u.name', 's.code', 'time_entries.is_billable')->get();
        return ['data' => $rows->map(fn ($r) => ['person' => $r->person, 'status' => $r->status, 'billable' => (bool) $r->billable, 'seconds' => (int) $r->seconds])];
    }

    /** Project manager marks a task billable / non-billable. */
    public function setBillable(Request $request, string $id)
    {
        $d = $request->validate(['is_billable' => 'required|boolean']);
        $t = Task::findOrFail($id);
        [, $level] = $this->access->find($request->user(), $t->project_id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $t->update(['is_billable' => $d['is_billable']]);
        \App\Services\AuditLogger::log('task.billable_set', $t, [], $d);
        return response()->noContent();
    }
}
