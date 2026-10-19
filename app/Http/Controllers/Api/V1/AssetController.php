<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AssetResource;
use App\Imports\AssetsImport;
use App\Models\Asset;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\Membership;
use App\Models\User;
use App\Models\Location;
use App\Services\AssetLog;
use App\Services\AuditLogger;
use App\Services\IncidentService;
use App\Services\OrganizationContext;
use App\Services\References;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class AssetController extends Controller
{
    use AppliesListQuery;

    private const UNAVAILABLE = ['lost', 'stolen', 'retired', 'off_site'];

    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array
    {
        return ['type.translations', 'status.translations', 'condition.translations', 'assignee', 'department', 'location'];
    }

    /** Register managers/approvers see every asset; everyone else only the equipment assigned to them. */
    protected function visible()
    {
        $q = Asset::query();
        if (!$this->ctx->can('asset.manage') && !$this->ctx->can('asset.approve_movement')) {
            $q->where('assigned_user_id', request()->user()->id);
        }
        return $q;
    }

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    private function orgRule(string $table)
    {
        return Rule::exists($table, 'id')->where('organization_id', $this->ctx->organizationId());
    }

    public function index(Request $request)
    {
        $q = $this->visible()->with($this->with());
        if ($request->boolean('mine')) $q->where('assigned_user_id', $request->user()->id);

        return $this->respondList($request, $q, AssetResource::class,
            ['asset_tag', 'name', 'serial_number', 'manufacturer', 'model', 'assignee.name'], ['asset_tag', 'name', 'created_at', 'purchase_date'],
            ['type_id', 'status_id', 'condition_id', 'department_id', 'location_id', 'assigned_user_id'],
            ['asset_tag', 'name', 'type_label', 'status_label', 'condition_label', 'serial_number', 'assignee_name', 'purchase_date', 'warranty_expires_on']);
    }

    public function show(Request $request, string $id)
    {
        $a = $this->visible()->with($this->with())->findOrFail($id);
        $events = $a->events()->with(['type', 'user'])->orderByDesc('id')->limit(100)->get();
        $lang = $request->user()->language_id;

        $ids = $events->pluck('old_ref')->merge($events->pluck('new_ref'))->filter()->unique();
        $lookups = LookupValue::with('translations')->whereIn('id', $ids)->get()->keyBy('id');
        $users = User::whereIn('id', $ids)->pluck('name', 'id');
        $locs = Location::whereIn('id', $ids)->get()->mapWithKeys(fn ($l) => [$l->id => $l->code . ' — ' . $l->name]);
        $res = fn ($v) => !$v ? null : ($lookups[$v]?->label($lang) ?? $users[$v] ?? $locs[$v] ?? $v);

        return response()->json(['data' => array_merge((new AssetResource($a))->resolve($request), [
            'events' => $events->map(fn ($e) => [
                'id' => $e->id, 'type' => $e->type->code, 'type_label' => $e->type->label($lang), 'user' => $e->user?->name, 'note' => $e->note,
                'from' => $res($e->old_ref), 'to' => $res($e->new_ref), 'created_at' => $e->created_at->toIso8601String(),
            ])->values(),
        ])]);
    }

    private function rules(?Asset $a = null): array
    {
        return [
            'name' => 'required|string|max:200',
            'type_id' => ['required', $this->lookupRule('asset_type')],
            'condition_id' => ['nullable', $this->lookupRule('asset_condition')],
            'manufacturer' => 'nullable|string|max:120', 'model' => 'nullable|string|max:120',
            'serial_number' => ['nullable', 'string', 'max:120', Rule::unique('assets', 'serial_number')->where('organization_id', $this->ctx->organizationId())->ignore($a?->id)],
            'purchase_date' => 'nullable|date', 'purchase_cost' => 'nullable|numeric|min:0|max:999999999',
            'warranty_expires_on' => 'nullable|date|after_or_equal:purchase_date',
            'department_id' => ['nullable', $this->orgRule('departments')], 'location_id' => ['nullable', $this->orgRule('locations')],
            'notes' => 'nullable|string|max:20000',
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules());
        $d['condition_id'] = $d['condition_id'] ?? Lookup::id('asset_condition', 'good');
        $asset = DB::transaction(function () use ($d) {
            $a = Asset::create($d + [
                'asset_tag' => References::next($this->ctx->organizationId(), 'asset', config('ops.asset_prefix'), false),
                'status_id' => Lookup::id('asset_status', 'in_stock'),
            ]);
            AssetLog::event($a, 'registered');
            return $a;
        });
        AuditLogger::log('asset.registered', $asset, [], ['asset_tag' => $asset->asset_tag, 'name' => $asset->name]);
        return (new AssetResource($asset->load($this->with())))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id)
    {
        $a = $this->visible()->findOrFail($id);
        $d = $request->validate($this->rules($a) + ['status_id' => ['sometimes', $this->lookupRule('asset_status')]]);
        if (empty($d['condition_id'])) unset($d['condition_id']);

        if (isset($d['status_id'])) {
            $new = LookupValue::findOrFail($d['status_id']);
            abort_unless(in_array($new->code, ['in_stock', 'in_repair', 'retired'], true), 422, __('api.asset_status_dedicated'));
            abort_if($new->code !== 'in_stock' && $a->assigned_user_id && $new->code === 'retired', 422, __('api.asset_return_first'));
            abort_if(in_array($a->status->code, ['off_site', 'lost', 'stolen'], true), 422, __('api.asset_assign_blocked'));
        }

        $old = $a->only(['status_id', 'condition_id', 'location_id']);
        $a->update($d);
        if ($a->status_id !== $old['status_id']) AssetLog::event($a, 'status_changed', $old['status_id'], $a->status_id);
        if ($a->condition_id !== $old['condition_id']) AssetLog::event($a, 'condition_changed', $old['condition_id'], $a->condition_id);
        if ($a->location_id !== $old['location_id']) AssetLog::event($a, 'location_changed', $old['location_id'], $a->location_id);
        AuditLogger::log('asset.updated', $a, [], ['asset_tag' => $a->asset_tag]);

        return new AssetResource($a->refresh()->load($this->with()));
    }

    /** Assign to a person (user_id) or take back into stock (user_id = null). */
    public function assign(Request $request, string $id)
    {
        $a = $this->visible()->with('status')->findOrFail($id);
        abort_if(in_array($a->status->code, self::UNAVAILABLE, true), 422, __('api.asset_assign_blocked'));
        $d = $request->validate(['user_id' => 'nullable|string', 'location_id' => ['nullable', $this->orgRule('locations')], 'note' => 'nullable|string|max:250']);

        if (!empty($d['user_id'])) {
            abort_unless(Membership::where('organization_id', $this->ctx->organizationId())->where('user_id', $d['user_id'])->exists(), 422, __('api.invalid_role'));
            $prev = $a->assigned_user_id;
            $a->update(['assigned_user_id' => $d['user_id'], 'status_id' => Lookup::id('asset_status', 'assigned')] + (isset($d['location_id']) ? ['location_id' => $d['location_id']] : []));
            AssetLog::event($a, 'assigned', $prev, $d['user_id'], $d['note'] ?? null);
        } else {
            $prev = $a->assigned_user_id;
            $a->update(['assigned_user_id' => null, 'status_id' => Lookup::id('asset_status', 'in_stock')] + (isset($d['location_id']) ? ['location_id' => $d['location_id']] : []));
            AssetLog::event($a, 'returned', $prev, null, $d['note'] ?? null);
        }
        AuditLogger::log('asset.assignment_changed', $a, [], ['asset_tag' => $a->asset_tag, 'assigned_to' => $d['user_id'] ?? 'stock']);
        return new AssetResource($a->refresh()->load($this->with()));
    }

    /** Loss or theft: marks the asset and opens an incident immediately (policy: report without delay). */
    public function reportLost(Request $request, string $id)
    {
        $a = $this->visible()->with(['status', 'type'])->findOrFail($id);
        $d = $request->validate(['kind' => 'required|in:lost,stolen', 'description' => 'required|string|max:20000', 'detected_at' => 'nullable|date']);
        abort_if(in_array($a->status->code, ['lost', 'stolen', 'retired'], true), 422, __('api.asset_assign_blocked'));

        $case = DB::transaction(function () use ($a, $d, $request) {
            $old = $a->status_id;
            $a->update(['status_id' => Lookup::id('asset_status', $d['kind'])]);
            AssetLog::event($a, 'reported_lost', $old, $a->status_id, $d['kind']);
            return IncidentService::report([
                'title' => ucfirst($d['kind']) . ' equipment: ' . $a->asset_tag . ' — ' . $a->name,
                'description' => $d['description'], 'severity_id' => Lookup::id('incident_severity', 'high'),
                'detected_at' => $d['detected_at'] ?? null, 'impact' => null, 'immediate_action' => null,
            ], $request->user(), $a, 'high');
        });
        AuditLogger::log('asset.reported_' . $d['kind'], $a, [], ['asset_tag' => $a->asset_tag, 'incident' => $case->reference]);

        return response()->json(['data' => ['case_id' => $case->id, 'reference' => $case->reference]], 201);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx|max:5120']);
        $import = new AssetsImport($this->ctx);
        Excel::import($import, $request->file('file'));
        AuditLogger::log('asset.imported', null, [], ['created' => $import->created, 'skipped' => count($import->errors)]);
        return response()->json(['created' => $import->created, 'errors' => $import->errors]);
    }
}
