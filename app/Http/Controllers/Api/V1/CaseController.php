<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CaseResource;
use App\Models\CaseComment;
use App\Models\CaseEvent;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\SupportCase;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\CaseReference;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CaseController extends Controller
{
    use AppliesListQuery;

    private const FINAL = ['resolved', 'closed', 'cancelled'];

    public function __construct(private OrganizationContext $ctx) {}

    /** Staff with case.view_all see every case; everyone else only cases they raised or are assigned to. */
    protected function visible()
    {
        $q = SupportCase::query();
        if (!$this->ctx->can('case.view_all')) {
            $me = request()->user()->id;
            $q->where(fn ($w) => $w->where('requester_id', $me)->orWhere('assignee_id', $me));
        }
        return $q;
    }

    private function with(): array
    {
        return ['type.translations', 'status.translations', 'priority.translations', 'requester', 'assignee', 'department'];
    }

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    public function index(Request $request)
    {
        $q = $this->visible()->with($this->with());
        if ($request->boolean('mine')) {
            $q->where('requester_id', $request->user()->id);
        }
        return $this->respondList($request, $q, CaseResource::class,
            ['reference', 'title', 'requester.name'], ['created_at', 'reference', 'title', 'updated_at'],
            ['status_id', 'type_id', 'priority_id', 'assignee_id'],
            ['reference', 'title', 'type_label', 'status_label', 'priority_label', 'requester_name', 'assignee_name', 'created_at']);
    }

    public function show(Request $request, string $id)
    {
        $case = $this->visible()->with($this->with())->findOrFail($id);
        $staff = $this->ctx->can('case.view_all');

        $comments = $case->comments()->with('user')->when(!$staff, fn ($q) => $q->where('is_internal', false))->orderBy('created_at')->get();
        $events = $case->events()->with(['type', 'user'])->orderBy('id')->get();

        // Resolve reference ids carried by events into labels (status/priority -> lookup label, assignee -> user name).
        $lang = $request->user()->language_id;
        $lookups = LookupValue::with('translations')->whereIn('id', $events->pluck('old_ref')->merge($events->pluck('new_ref'))->filter()->unique())->get()->keyBy('id');
        $users = User::whereIn('id', $events->where('type.code', 'assigned')->pluck('old_ref')->merge($events->where('type.code', 'assigned')->pluck('new_ref'))->filter()->unique())->pluck('name', 'id');

        $ref = fn ($e, $v) => !$v ? null : ($e->type->code === 'assigned' ? ($users[$v] ?? '—') : ($e->type->code === 'due_changed' ? $v : ($lookups[$v]?->label($lang) ?? '—')));

        return response()->json(['data' => array_merge((new CaseResource($case))->withDescription()->resolve($request), [
            'comments' => $comments->map(fn ($c) => [
                'id' => $c->id, 'body' => $c->body, 'is_internal' => $c->is_internal, 'created_at' => $c->created_at->toIso8601String(),
                'user' => ['id' => $c->user->id, 'name' => $c->user->name, 'has_avatar' => (bool) $c->user->avatar_path],
            ])->values(),
            'events' => $events->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type->code, 'created_at' => $e->created_at->toIso8601String(),
                'user' => $e->user?->name, 'from' => $ref($e, $e->old_ref), 'to' => $ref($e, $e->new_ref),
            ])->values(),
        ])]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'title' => 'required|string|max:200',
            'type_id' => ['required', $this->lookupRule('case_type')],
            'priority_id' => ['nullable', $this->lookupRule('case_priority')],
            'department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $this->ctx->organizationId())],
            'description' => 'nullable|string|max:50000',     // already purified by SanitizeRichText
        ]);
        $user = $request->user();

        $case = DB::transaction(function () use ($d, $user) {
            $case = SupportCase::create([
                'reference' => CaseReference::next($this->ctx->organizationId()),
                'type_id' => $d['type_id'],
                'status_id' => Lookup::id('case_status', 'new'),
                'priority_id' => $d['priority_id'] ?? Lookup::id('case_priority', 'normal'),
                'title' => $d['title'], 'description' => $d['description'] ?? null,
                'department_id' => $d['department_id'] ?? null, 'requester_id' => $user->id,
            ]);
            CaseEvent::create(['case_id' => $case->id, 'user_id' => $user->id, 'event_type_id' => Lookup::id('case_event_type', 'created')]);
            return $case;
        });
        $case->load($this->with());
        AuditLogger::log('case.created', $case, [], ['reference' => $case->reference, 'title' => $case->title]);

        // Feedback to the sender + alert to the admins (email + SMS + live).
        $params = ['ref' => $case->reference, 'title' => $case->title, 'user' => $user->name, 'priority' => $case->priority->label(), 'app' => $this->ctx->organization()->name];
        Notifier::send($user->loadMissing('language'), 'case_created_user', $params, $case, ['in_app', 'email']);
        $admins = Notifier::usersWithPermission($this->ctx->organizationId(), 'case.notify_new')->reject(fn ($u) => $u->id === $user->id);
        Notifier::send($admins, 'case_created_admin', $params, $case, ['in_app', 'email', 'sms']);

        return (new CaseResource($case))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $case = $this->visible()->with($this->with())->findOrFail($id);
        $d = $request->validate([
            'status_id' => ['sometimes', $this->lookupRule('case_status')],
            'priority_id' => ['sometimes', $this->lookupRule('case_priority')],
            'assignee_id' => ['sometimes', 'nullable', 'exists:users,id'],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $workflow = array_intersect_key($d, array_flip(['status_id', 'priority_id', 'due_at']));
        abort_if($workflow && !$this->ctx->can('case.update_status'), 403);
        abort_if(array_key_exists('assignee_id', $d) && !$this->ctx->can('case.assign'), 403);

        $newStatus = isset($d['status_id']) ? LookupValue::findOrFail($d['status_id']) : null;
        abort_if($newStatus && in_array($newStatus->code, ['closed', 'cancelled'], true) && !$this->ctx->can('case.close'), 403);

        if (isset($d['assignee_id'])) {
            $ok = Notifier::usersWithPermission($this->ctx->organizationId(), 'case.update_status')->contains('id', $d['assignee_id']);
            abort_unless($ok, 422, __('api.invalid_role'));
        }

        $actor = $request->user();
        $changes = [];
        DB::transaction(function () use ($case, $d, $newStatus, $actor, &$changes) {
            $log = function (string $type, ?string $old, ?string $new) use ($case, $actor, &$changes) {
                CaseEvent::create(['case_id' => $case->id, 'user_id' => $actor->id, 'event_type_id' => Lookup::id('case_event_type', $type), 'old_ref' => $old, 'new_ref' => $new]);
                $changes[] = $type;
            };

            if (isset($d['status_id']) && $d['status_id'] !== $case->status_id) {
                $log('status_changed', $case->status_id, $d['status_id']);
                $case->status_id = $d['status_id'];
                $case->resolved_at = in_array($newStatus->code, ['resolved', 'closed'], true) ? ($case->resolved_at ?? now()) : null;
                $case->closed_at = in_array($newStatus->code, ['closed', 'cancelled'], true) ? now() : null;
            }
            if (isset($d['priority_id']) && $d['priority_id'] !== $case->priority_id) { $log('priority_changed', $case->priority_id, $d['priority_id']); $case->priority_id = $d['priority_id']; }
            if (array_key_exists('assignee_id', $d) && $d['assignee_id'] !== $case->assignee_id) { $log('assigned', $case->assignee_id, $d['assignee_id']); $case->assignee_id = $d['assignee_id']; }
            if (array_key_exists('due_at', $d)) {
                $new = $d['due_at'] ? \Illuminate\Support\Carbon::parse($d['due_at']) : null;
                if (($new?->toIso8601String()) !== ($case->due_at?->toIso8601String())) { $log('due_changed', $case->due_at?->toDateString(), $new?->toDateString()); $case->due_at = $new; }
            }
            $case->save();
        });

        $case->refresh()->load($this->with());
        foreach ($changes as $c) { AuditLogger::log("case.$c", $case, [], ['reference' => $case->reference]); }

        $params = ['ref' => $case->reference, 'title' => $case->title, 'status' => $case->status->label(), 'user' => $actor->name];
        if (in_array('status_changed', $changes, true) && $case->requester_id !== $actor->id) {
            Notifier::send($case->requester->loadMissing('language'), 'case_status', $params, $case, ['in_app', 'email']);
        }
        if (in_array('assigned', $changes, true) && $case->assignee && $case->assignee_id !== $actor->id) {
            Notifier::send($case->assignee->loadMissing('language'), 'case_assigned', $params, $case, ['in_app', 'email']);
        }

        return new CaseResource($case);
    }

    public function comment(Request $request, string $id)
    {
        $case = $this->visible()->with($this->with())->findOrFail($id);
        $d = $request->validate(['body' => 'required|string|max:50000', 'is_internal' => 'boolean']);
        $actor = $request->user();
        $staff = $this->ctx->can('case.view_all');

        $internal = (bool) ($d['is_internal'] ?? false);
        abort_if($internal && !$staff, 403);
        abort_if(!$staff && in_array($case->status->code, ['closed', 'cancelled'], true), 422, __('api.in_use'));

        $c = CaseComment::create(['case_id' => $case->id, 'user_id' => $actor->id, 'body' => $d['body'], 'is_internal' => $internal]);
        AuditLogger::log('case.commented', $case, [], ['internal' => $internal ? 'yes' : 'no']);

        $params = ['ref' => $case->reference, 'title' => $case->title, 'user' => $actor->name];
        if ($internal) {
            if ($case->assignee && $case->assignee_id !== $actor->id) Notifier::send($case->assignee->loadMissing('language'), 'case_comment', $params, $case, ['in_app']);
        } elseif ($actor->id === $case->requester_id) {
            $to = $case->assignee ? collect([$case->assignee->loadMissing('language')]) : Notifier::usersWithPermission($this->ctx->organizationId(), 'case.notify_new');
            Notifier::send($to->reject(fn ($u) => $u->id === $actor->id), 'case_comment', $params, $case, ['in_app', 'email']);
        } else {
            Notifier::send($case->requester->loadMissing('language'), 'case_comment', $params, $case, ['in_app', 'email']);
        }

        return response()->json(['data' => [
            'id' => $c->id, 'body' => $c->body, 'is_internal' => $c->is_internal, 'created_at' => $c->created_at->toIso8601String(),
            'user' => ['id' => $actor->id, 'name' => $actor->name, 'has_avatar' => (bool) $actor->avatar_path],
        ]], 201);
    }

    /** Agents a case can be assigned to (users holding case.update_status). */
    public function assignees()
    {
        return response()->json(['data' => Notifier::usersWithPermission($this->ctx->organizationId(), 'case.update_status')->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])->values()]);
    }
}
