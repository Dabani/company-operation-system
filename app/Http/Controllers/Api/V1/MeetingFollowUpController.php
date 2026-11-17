<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Http\Resources\Api\V1\FollowUpRowResource;
use App\Models\FileAsset;
use App\Models\LookupType;
use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingResolution;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\Meetings\FollowUpRules;
use App\Services\Meetings\FollowUpService;
use App\Services\Meetings\MeetingAccess;
use App\Services\OrganizationContext;
use App\Services\Projects\ProjectAccess;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Resolutions and action items (Guide 6.7): per-meeting registers, status moves with evidence, conversions, evidence files, follow-up dashboard. */
class MeetingFollowUpController extends Controller
{
    public function __construct(private FollowUpService $svc, private MeetingAccess $access, private ProjectAccess $projects, private OrganizationContext $ctx) {}

    private const FREQUENCIES = ['weekly', 'monthly', 'quarterly', 'semi_annual', 'annual'];

    private function org(): string { return $this->ctx->organizationId(); }
    private function runner(User $u, Meeting $m): bool { return $this->access->canManage($u, $m) || $this->access->canChair($u, $m); }
    private function requireRunner(User $u, Meeting $m): void { abort_unless($this->runner($u, $m), 403, 'forbidden'); }

    private function resolution(Request $r, string $id): array
    {
        $res = MeetingResolution::with('status')->findOrFail($id);
        $m = $this->access->find($r->user(), $res->meeting_id, ['status']);
        return [$res, $m];
    }

    private function action(Request $r, string $id): array
    {
        $a = MeetingAction::with('status')->findOrFail($id);
        $m = $this->access->find($r->user(), $a->meeting_id, ['status']);
        return [$a, $m];
    }

    // ---- rows -------------------------------------------------------------------------------------------------------------------
    private function resRow(MeetingResolution $x, Meeting $m, User $u, string $today): array
    {
        $x->loadMissing(['status', 'responsible:id,name', 'department:id,name', 'task.status', 'task.project:id,code,name', 'obligation:id,code,title', 'agendaItem:id,title']);
        $s = $x->status->code;
        $due = $x->due_on?->toDateString();
        $runner = $this->runner($u, $m);
        $mine = $x->responsible_user_id === $u->id;
        return [
            'kind' => 'resolution', 'id' => $x->id, 'code' => $x->code, 'seq' => $x->seq, 'title' => $x->title, 'body' => $x->body, 'status' => $s, 'due_on' => $due,
            'overdue' => FollowUpRules::isOverdue($s, $due, $today), 'days_overdue' => FollowUpRules::isOverdue($s, $due, $today) ? FollowUpRules::daysOverdue($due, $today) : 0,
            'responsible' => $x->responsible ? ['id' => $x->responsible->id, 'name' => $x->responsible->name] : null,
            'department' => $x->department ? ['id' => $x->department->id, 'name' => $x->department->name] : null,
            'agenda_item' => $x->agendaItem ? ['id' => $x->agendaItem->id, 'title' => $x->agendaItem->title] : null,
            'completed_at' => $x->completed_at?->toIso8601String(),
            'task' => $x->task ? ['id' => $x->task->id, 'project_id' => $x->task->project_id, 'project' => $x->task->project?->code, 'number' => $x->task->number, 'status' => $x->task->status?->code] : null,
            'obligation' => $x->obligation ? ['id' => $x->obligation->id, 'code' => $x->obligation->code] : null,
            'meeting' => ['id' => $m->id, 'code' => $m->code, 'title' => $m->title],
            'me' => [
                'can_edit' => $runner && FollowUpRules::editable($s), 'can_delete' => $runner && $s === 'open' && !$x->task_id && !$x->obligation_id,
                'moves' => collect(FollowUpRules::RESOLUTION[$s])->filter(fn ($to) => FollowUpRules::canMove('resolution', $s, $to, $mine, $runner))->values(),
                'can_convert' => $runner && FollowUpRules::isActive($s) && !$x->task_id && !$x->obligation_id,
                'can_attach' => ($runner || $mine) && $s !== 'cancelled',
            ],
        ];
    }

    private function actRow(MeetingAction $x, Meeting $m, User $u, string $today): array
    {
        $x->loadMissing(['status', 'owner:id,name', 'resolution:id,code,title', 'closer:id,name', 'agendaItem:id,title']);
        $s = $x->status->code;
        $due = $x->due_on?->toDateString();
        $runner = $this->runner($u, $m);
        $mine = $x->owner_id === $u->id;
        return [
            'kind' => 'action', 'id' => $x->id, 'code' => $x->code, 'seq' => $x->seq, 'title' => $x->title, 'description' => $x->description, 'status' => $s, 'due_on' => $due,
            'overdue' => FollowUpRules::isOverdue($s, $due, $today), 'days_overdue' => FollowUpRules::isOverdue($s, $due, $today) ? FollowUpRules::daysOverdue($due, $today) : 0,
            'owner' => $x->owner ? ['id' => $x->owner->id, 'name' => $x->owner->name] : null,
            'resolution' => $x->resolution ? ['id' => $x->resolution->id, 'code' => $x->resolution->code, 'title' => $x->resolution->title] : null,
            'agenda_item' => $x->agendaItem ? ['id' => $x->agendaItem->id, 'title' => $x->agendaItem->title] : null,
            'completed_at' => $x->completed_at?->toIso8601String(), 'completion_note' => $x->completion_note, 'closed_at' => $x->closed_at?->toIso8601String(), 'closed_by' => $x->closer?->name,
            'meeting' => ['id' => $m->id, 'code' => $m->code, 'title' => $m->title],
            'me' => [
                'can_edit' => $runner && FollowUpRules::editable($s), 'can_delete' => $runner && $s === 'open',
                'moves' => collect(FollowUpRules::ACTION[$s])->filter(fn ($to) => FollowUpRules::canMove('action', $s, $to, $mine, $runner))->values(),
                'can_attach' => ($runner || $mine) && in_array($s, ['open', 'in_progress', 'done'], true),
            ],
        ];
    }

    // ---- per meeting ------------------------------------------------------------------------------------------------------------
    public function forMeeting(Request $request, string $id)
    {
        $u = $request->user();
        $m = $this->access->find($u, $id, ['status']);
        $today = FollowUpService::today($m->organization_id);
        $res = MeetingResolution::where('meeting_id', $m->id)->orderBy('seq')->get()->map(fn ($x) => $this->resRow($x, $m, $u, $today));
        $act = MeetingAction::where('meeting_id', $m->id)->orderBy('seq')->get()->map(fn ($x) => $this->actRow($x, $m, $u, $today));
        $runner = $this->runner($u, $m);
        return ['data' => ['resolutions' => $res, 'actions' => $act], 'meta' => [
            'can_record' => $runner && FollowUpRules::canRecord($m->status->code), 'is_runner' => $runner, 'today' => $today,
            'can_convert_obligation' => $this->ctx->can('compliance.manage'), 'frequencies' => self::FREQUENCIES,
        ]];
    }

    private function resRules(Meeting $m): array
    {
        return [
            'title' => 'required|string|max:255', 'body' => 'nullable|string|max:20000', 'due_on' => 'nullable|date_format:Y-m-d',
            'agenda_item_id' => ['nullable', Rule::exists('meeting_agenda_items', 'id')->where('meeting_id', $m->id)],
            'responsible_user_id' => 'nullable|string', 'responsible_department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $m->organization_id)],
        ];
    }

    private function actRules(Meeting $m): array
    {
        return [
            'title' => 'required|string|max:255', 'description' => 'nullable|string|max:20000', 'due_on' => 'nullable|date_format:Y-m-d', 'owner_id' => 'nullable|string',
            'agenda_item_id' => ['nullable', Rule::exists('meeting_agenda_items', 'id')->where('meeting_id', $m->id)], 'resolution_id' => 'nullable|string',
        ];
    }

    public function storeResolution(Request $request, string $id)
    {
        $u = $request->user();
        $m = $this->access->find($u, $id, ['status']);
        $this->requireRunner($u, $m);
        $x = $this->svc->createResolution($m, $request->validate($this->resRules($m)), $u);
        return response(['data' => $this->resRow($x, $m, $u, FollowUpService::today($m->organization_id))], 201);
    }

    public function updateResolution(Request $request, string $id)
    {
        $u = $request->user();
        [$x, $m] = $this->resolution($request, $id);
        $this->requireRunner($u, $m);
        $rules = collect($this->resRules($m))->map(fn ($r, $k) => is_string($r) ? 'sometimes|' . $r : array_merge(['sometimes'], $r))->all();
        $x = $this->svc->updateResolution($x, $m, $request->validate($rules), $u);
        return ['data' => $this->resRow($x->refresh(), $m, $u, FollowUpService::today($m->organization_id))];
    }

    public function destroyResolution(Request $request, string $id)
    {
        [$x, $m] = $this->resolution($request, $id);
        $this->requireRunner($request->user(), $m);
        $this->svc->deleteResolution($x);
        return response()->noContent();
    }

    public function resolutionStatus(Request $request, string $id)
    {
        $u = $request->user();
        [$x, $m] = $this->resolution($request, $id);
        $d = $request->validate(['to' => ['required', Rule::in(array_keys(FollowUpRules::RESOLUTION))], 'note' => 'nullable|string|max:2000']);
        $x = $this->svc->transitionResolution($x, $m, $d['to'], $d['note'] ?? null, $u, $this->runner($u, $m));
        return ['data' => $this->resRow($x, $m, $u, FollowUpService::today($m->organization_id))];
    }

    public function convertTask(Request $request, string $id)
    {
        $u = $request->user();
        [$x, $m] = $this->resolution($request, $id);
        $this->requireRunner($u, $m);
        $d = $request->validate(['project_id' => 'required|string', 'assignee_id' => 'nullable|string']);
        [$p, $level] = $this->projects->find($u, $d['project_id']);
        abort_unless(ProjectAccess::canEdit($level), 403, 'forbidden');
        $x = $this->svc->convertToTask($x, $m, $p, $d['assignee_id'] ?? null, $u);
        return ['data' => $this->resRow($x, $m, $u, FollowUpService::today($m->organization_id))];
    }

    public function convertObligation(Request $request, string $id)
    {
        $u = $request->user();
        [$x, $m] = $this->resolution($request, $id);
        $this->requireRunner($u, $m);
        abort_unless($this->ctx->can('compliance.manage'), 403, 'forbidden');
        $d = $request->validate(['frequency' => ['required', Rule::in(self::FREQUENCIES)]]);
        $x = $this->svc->convertToObligation($x, $m, $d['frequency'], $u);
        return ['data' => $this->resRow($x, $m, $u, FollowUpService::today($m->organization_id))];
    }

    public function storeAction(Request $request, string $id)
    {
        $u = $request->user();
        $m = $this->access->find($u, $id, ['status']);
        $this->requireRunner($u, $m);
        $x = $this->svc->createAction($m, $request->validate($this->actRules($m)), $u);
        return response(['data' => $this->actRow($x, $m, $u, FollowUpService::today($m->organization_id))], 201);
    }

    public function updateAction(Request $request, string $id)
    {
        $u = $request->user();
        [$x, $m] = $this->action($request, $id);
        $this->requireRunner($u, $m);
        $rules = collect($this->actRules($m))->map(fn ($r) => is_string($r) ? 'sometimes|' . $r : array_merge(['sometimes'], $r))->all();
        $x = $this->svc->updateAction($x, $m, $request->validate($rules), $u);
        return ['data' => $this->actRow($x->refresh(), $m, $u, FollowUpService::today($m->organization_id))];
    }

    public function destroyAction(Request $request, string $id)
    {
        [$x, $m] = $this->action($request, $id);
        $this->requireRunner($request->user(), $m);
        $this->svc->deleteAction($x);
        return response()->noContent();
    }

    public function actionStatus(Request $request, string $id)
    {
        $u = $request->user();
        [$x, $m] = $this->action($request, $id);
        $d = $request->validate(['to' => ['required', Rule::in(array_keys(FollowUpRules::ACTION))], 'note' => 'nullable|string|max:2000']);
        $x = $this->svc->transitionAction($x, $m, $d['to'], $d['note'] ?? null, $u, $this->runner($u, $m));
        return ['data' => $this->actRow($x, $m, $u, FollowUpService::today($m->organization_id))];
    }

    // ---- supporting / evidence files (Controlled Files) -------------------------------------------------------------------------
    /** @return array{0:Meeting,1:string,2:string,3:bool} */
    private function fileTarget(Request $r, string $kind, string $id): array
    {
        abort_unless(in_array($kind, ['resolution', 'action'], true), 404);
        $u = $r->user();
        if ($kind === 'resolution') {
            [$x, $m] = $this->resolution($r, $id);
            return [$m, 'meeting_resolution', $x->id, ($this->runner($u, $m) || $x->responsible_user_id === $u->id) && $x->status->code !== 'cancelled'];
        }
        [$x, $m] = $this->action($r, $id);
        return [$m, 'meeting_action', $x->id, ($this->runner($u, $m) || $x->owner_id === $u->id) && in_array($x->status->code, ['open', 'in_progress', 'done'], true)];
    }

    public function files(Request $request, string $kind, string $id)
    {
        [, $type, $linkId] = $this->fileTarget($request, $kind, $id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with(['classification.translations', 'status.translations', 'owner'])->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', $type))->where('link_id', $linkId))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function storeFile(Request $request, string $kind, string $id)
    {
        [, $type, $linkId, $may] = $this->fileTarget($request, $kind, $id);
        abort_unless($may, 403, 'forbidden');
        $d = $request->validate(['file' => 'required|file', 'classification_id' => ['nullable', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'file_classification')->pluck('id')))]]);
        abort_if(($d['classification_id'] ?? null) === Lookup::id('file_classification', 'restricted'), 422, __('meetings.file_not_restricted'));
        $asset = FileService::store($request->file('file'), $request->user(), $d, ['type' => $type, 'id' => $linkId])->load(['classification.translations', 'status.translations', 'owner']);
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, $type => $linkId, 'sha256' => $asset->currentVersion->sha256]);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }

    // ---- follow-up dashboard ----------------------------------------------------------------------------------------------------
    private function scoped(string $model, User $u)
    {
        return $model::query()->whereIn('meeting_id', Meeting::query()->visibleTo($u)->select('meetings.id'));
    }

    public function index(Request $request)
    {
        $u = $request->user();
        $kind = $request->query('kind') === 'resolution' ? 'resolution' : 'action';
        $model = $kind === 'resolution' ? MeetingResolution::class : MeetingAction::class;
        $ownerCol = $kind === 'resolution' ? 'responsible_user_id' : 'owner_id';
        $lookup = $kind === 'resolution' ? 'resolution_status' : 'action_status';
        $today = FollowUpService::today($this->org());
        $q = $this->scoped($model, $u)->with(['status', 'meeting.status'])->orderByRaw('due_on is null')->orderBy('due_on')->orderBy('code');
        if ($request->boolean('mine')) {
            $q->where($ownerCol, $u->id);
        }
        if ($request->boolean('overdue')) {
            $q->whereIn('status_id', [Lookup::id($lookup, 'open'), Lookup::id($lookup, 'in_progress')])->whereNotNull('due_on')->where('due_on', '<', $today);
        }
        if ($s = $request->query('status')) {
            $q->where('status_id', Lookup::id($lookup, (string) $s));
        } elseif (!$request->boolean('all')) {
            $q->whereIn('status_id', array_filter([Lookup::id($lookup, 'open'), Lookup::id($lookup, 'in_progress'), $kind === 'action' ? Lookup::id($lookup, 'done') : null]));
        }
        if ($mid = $request->query('meeting_id')) {
            $q->where('meeting_id', $mid);
        }
        if ($t = trim((string) $request->query('search'))) {
            $q->where(fn ($w) => $w->where('title', 'like', "%$t%")->orWhere('code', 'like', "%$t%"));
        }
        $p = $q->paginate(min(100, max(5, (int) $request->query('per_page', 25))));
        $p->through(fn ($x) => $kind === 'resolution' ? $this->resRow($x, $x->meeting, $u, $today) : $this->actRow($x, $x->meeting, $u, $today));
        return FollowUpRowResource::collection($p);
    }

    public function summary(Request $request)
    {
        $u = $request->user();
        $today = FollowUpService::today($this->org());
        $soon = max(0, (int) \App\Services\SettingsService::get($this->org(), 'meeting.followup_due_soon_days'));
        $limit = date('Y-m-d', strtotime($today . " +$soon days"));
        $out = [];
        foreach (['resolution' => [MeetingResolution::class, 'responsible_user_id', 'resolution_status'], 'action' => [MeetingAction::class, 'owner_id', 'action_status']] as $kind => [$model, $ownerCol, $lookup]) {
            $active = [Lookup::id($lookup, 'open'), Lookup::id($lookup, 'in_progress')];
            $base = fn () => $this->scoped($model, $u);
            $overdue = fn () => $base()->whereIn('status_id', $active)->whereNotNull('due_on')->where('due_on', '<', $today);
            $total = $base()->where('status_id', '!=', Lookup::id($lookup, 'cancelled'))->count();
            $finished = $base()->whereIn('status_id', $kind === 'resolution' ? [Lookup::id($lookup, 'completed')] : [Lookup::id($lookup, 'done'), Lookup::id($lookup, 'closed')])->count();
            $out[$kind] = [
                'active' => $base()->whereIn('status_id', $active)->count(), 'overdue' => $overdue()->count(),
                'due_soon' => $base()->whereIn('status_id', $active)->whereNotNull('due_on')->whereBetween('due_on', [$today, $limit])->count(),
                'finished' => $finished, 'total' => $total, 'rate' => $total ? (int) round($finished * 100 / $total) : 0,
                'mine_active' => $base()->whereIn('status_id', $active)->where($ownerCol, $u->id)->count(), 'mine_overdue' => $overdue()->where($ownerCol, $u->id)->count(),
                'by_owner' => $overdue()->whereNotNull($ownerCol)->select($ownerCol . ' as uid', DB::raw('count(*) as n'))->groupBy($ownerCol)->orderByDesc('n')->limit(8)->get()
                    ->map(fn ($r) => ['name' => User::find($r->uid)?->name, 'count' => (int) $r->n])->values(),
            ];
        }
        $out['awaiting_closure'] = $this->scoped(MeetingAction::class, $u)->where('status_id', Lookup::id('action_status', 'done'))->count();
        $out['today'] = $today;
        return ['data' => $out];
    }
}
