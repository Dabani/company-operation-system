<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FileResource;
use App\Http\Resources\Api\V1\RecordResource;
use App\Imports\RecordsImport;
use App\Models\ArchiveAccessLog;
use App\Models\ArchiveRecord;
use App\Models\Department;
use App\Models\FileAsset;
use App\Models\FileClassificationRule;
use App\Models\Location;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FileAccess;
use App\Services\FileService;
use App\Services\OrganizationContext;
use App\Services\RecordRules;
use App\Services\References;
use App\Support\AppliesListQuery;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

class RecordController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array
    {
        return ['format.translations', 'category.translations', 'status.translations', 'classification.translations', 'department', 'custodian', 'location'];
    }

    /** Classification levels decide visibility (same table as files); custodian and creator always see their records. */
    protected function visible()
    {
        $allowed = FileClassificationRule::where('min_level', '<=', $this->ctx->level())->pluck('classification_id');
        $me = request()->user()->id;
        return ArchiveRecord::query()->where(fn ($w) => $w->whereIn('classification_id', $allowed)->orWhere('custodian_id', $me)->orWhere('created_by', $me));
    }

    private function lookupRule(string $type)
    {
        return Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id')));
    }

    private function orgRule(string $table) { return Rule::exists($table, 'id')->where('organization_id', $this->ctx->organizationId()); }

    private function isPhysical(ArchiveRecord $r): bool { return in_array($r->format->code, ['physical', 'hybrid'], true); }

    public function index(Request $request)
    {
        $q = $this->visible()->with($this->with());
        match ($request->query('due')) {
            'transfer' => $q->whereHas('status', fn ($s) => $s->where('code', 'inactive'))->whereDate('transfer_due_on', '<=', today()),
            'disposal' => $q->whereHas('status', fn ($s) => $s->where('code', 'archived'))->whereDate('retention_until', '<=', today())->where('legal_hold', false),
            default => null,
        };
        if ($request->boolean('hold')) $q->where('legal_hold', true);

        return $this->respondList($request, $q, RecordResource::class, ['reference', 'title', 'client_name', 'project_name', 'index_code'],
            ['record_date', 'retention_until', 'reference', 'title', 'created_at'], ['category_id', 'format_id', 'status_id', 'department_id', 'location_id', 'custodian_id'],
            ['reference', 'title', 'index_code', 'category_label', 'format_label', 'status_label', 'client_name', 'project_name', 'record_date', 'retention_until', 'custodian_name']);
    }

    public function show(Request $request, string $id)
    {
        $r = $this->visible()->with($this->with())->findOrFail($id);
        $lang = $request->user()->language_id;
        $events = $r->events()->with(['type', 'user'])->orderByDesc('id')->limit(100)->get();

        $ids = $events->pluck('old_ref')->merge($events->pluck('new_ref'))->filter()->unique();
        $lookups = LookupValue::with('translations')->whereIn('id', $ids)->get()->keyBy('id');
        $locs = Location::whereIn('id', $ids)->get()->mapWithKeys(fn ($l) => [$l->id => $l->code . ' — ' . $l->name]);
        $res = fn ($v) => !$v ? null : ($lookups[$v]?->label($lang) ?? $locs[$v] ?? $v);

        [$years, $rule] = RecordRules::retention($this->ctx->organizationId(), $r->category_id);
        $out = ArchiveAccessLog::with('requester')->where('record_id', $r->id)->whereHas('status', fn ($s) => $s->where('code', 'authorized'))->first();

        return response()->json(['data' => array_merge((new RecordResource($r->setRelation('events', $events)))->resolve($request), [
            'retention_years' => $years,
            'checked_out_to' => $out ? ['name' => $out->requester->name, 'since' => $out->accessed_at?->toIso8601String(), 'due_back_on' => $out->due_back_on?->toDateString()] : null,
            'events' => $events->map(fn ($e) => ['id' => $e->id, 'type' => $e->type->code, 'type_label' => $e->type->label($lang), 'user' => $e->user?->name, 'note' => $e->note,
                'from' => $res($e->old_ref), 'to' => $res($e->new_ref), 'created_at' => $e->created_at->toIso8601String()])->values(),
        ])]);
    }

    private function rules(): array
    {
        return [
            'title' => 'required|string|max:250', 'description' => 'nullable|string|max:50000',
            'format_id' => ['required', $this->lookupRule('record_format')], 'category_id' => ['required', $this->lookupRule('record_category')],
            'classification_id' => ['nullable', $this->lookupRule('file_classification')],
            'department_id' => ['nullable', $this->orgRule('departments')], 'custodian_id' => ['nullable', Rule::exists('organization_memberships', 'user_id')->where('organization_id', $this->ctx->organizationId())],
            'location_id' => ['nullable', $this->orgRule('locations')], 'record_date' => 'required|date|before_or_equal:today',
            'client_name' => 'nullable|string|max:150', 'project_name' => 'nullable|string|max:150',
        ];
    }

    public function store(Request $request)
    {
        $d = $request->validate($this->rules() + ['status_code' => 'nullable|in:active,inactive,archived']);
        $status = $d['status_code'] ?? 'active';
        $date = Carbon::parse($d['record_date']);
        $dept = !empty($d['department_id']) ? Department::find($d['department_id']) : null;
        $cat = LookupValue::find($d['category_id']);
        if ($status === 'archived' && $this->isPhysicalId($d['format_id'])) abort_unless(!empty($d['location_id']), 422, __('api.location_required'));

        $r = DB::transaction(function () use ($d, $status, $date, $dept, $cat, $request) {
            $r = new ArchiveRecord(collect($d)->except('status_code')->all() + [
                'reference' => References::next($this->ctx->organizationId(), 'record', 'REC', true),
                'status_id' => Lookup::id('record_status', $status), 'classification_id' => $d['classification_id'] ?? Lookup::id('file_classification', 'internal'),
                'index_code' => RecordRules::indexCode($dept?->code, $cat->code, $date), 'created_by' => $request->user()->id,
            ]);
            $r->organization_id = $this->ctx->organizationId();
            if ($status !== 'active') $r->became_inactive_on = $date->toDateString();       // legacy records enter already inactive/archived
            if ($status === 'archived') $r->archived_on = today()->toDateString();
            $r->load(['status', 'format']);
            RecordRules::recompute($r);
            $r->save();
            RecordRules::event($r, 'created');
            return $r;
        });
        AuditLogger::log('record.created', $r, [], ['reference' => $r->reference, 'title' => $r->title]);
        return (new RecordResource($r->load($this->with())))->response()->setStatusCode(201);
    }

    private function isPhysicalId(string $formatId): bool { return in_array(LookupValue::find($formatId)->code, ['physical', 'hybrid'], true); }

    public function update(Request $request, string $id)
    {
        $r = $this->visible()->with($this->with())->findOrFail($id);
        abort_if(in_array($r->status->code, ['disposal_pending', 'disposed'], true), 422, __('api.record_status_invalid'));
        $d = $request->validate($this->rules());
        if (empty($d['classification_id'])) unset($d['classification_id']);

        $oldUntil = $r->retention_until?->toDateString();
        $r->fill($d);
        $dept = $r->department_id ? Department::find($r->department_id) : null;
        $r->index_code = RecordRules::indexCode($dept?->code, LookupValue::find($r->category_id)->code, $r->record_date);
        $r->load(['status', 'format']);
        RecordRules::recompute($r);
        $r->save();

        RecordRules::event($r, 'updated');
        if (($r->retention_until?->toDateString()) !== $oldUntil) RecordRules::event($r, 'retention_changed', $oldUntil, $r->retention_until?->toDateString());
        AuditLogger::log('record.updated', $r, [], ['reference' => $r->reference]);
        return new RecordResource($r->refresh()->load($this->with()));
    }

    /** Active -> inactive: starts the retention clock and the transfer-to-archive window. */
    public function inactive(Request $request, string $id)
    {
        $r = $this->visible()->with($this->with())->findOrFail($id);
        abort_unless($r->status->code === 'active', 422, __('api.record_status_invalid'));
        $d = $request->validate(['date' => 'nullable|date|before_or_equal:today']);
        $old = $r->status_id;
        $r->status_id = Lookup::id('record_status', 'inactive');
        $r->became_inactive_on = Carbon::parse($d['date'] ?? today())->toDateString();
        $r->load(['status', 'format']); $r->setRelation('status', LookupValue::find($r->status_id));
        RecordRules::recompute($r);
        $r->save();
        RecordRules::event($r, 'marked_inactive', $old, $r->status_id);
        AuditLogger::log('record.marked_inactive', $r, [], ['reference' => $r->reference]);
        return new RecordResource($r->refresh()->load($this->with()));
    }

    /** Inactive -> archived (physical/hybrid records need their central-archive location). */
    public function transfer(Request $request, string $id)
    {
        $r = $this->visible()->with($this->with())->findOrFail($id);
        abort_unless($r->status->code === 'inactive', 422, __('api.record_status_invalid'));
        $d = $request->validate(['location_id' => ['nullable', $this->orgRule('locations')]]);
        if ($this->isPhysical($r)) abort_unless(!empty($d['location_id']), 422, __('api.location_required'));

        $old = $r->status_id; $oldLoc = $r->location_id;
        $r->status_id = Lookup::id('record_status', 'archived');
        $r->archived_on = today()->toDateString();
        if (!empty($d['location_id'])) $r->location_id = $d['location_id'];
        $r->setRelation('status', LookupValue::find($r->status_id));
        RecordRules::recompute($r);
        $r->save();
        RecordRules::event($r, 'transferred', $old, $r->status_id);
        if ($r->location_id !== $oldLoc) RecordRules::event($r, 'moved', $oldLoc, $r->location_id);
        AuditLogger::log('record.transferred', $r, [], ['reference' => $r->reference]);
        return new RecordResource($r->refresh()->load($this->with()));
    }

    public function move(Request $request, string $id)
    {
        $r = $this->visible()->with($this->with())->findOrFail($id);
        abort_if(in_array($r->status->code, ['disposal_pending', 'disposed'], true), 422, __('api.record_status_invalid'));
        abort_if(ArchiveAccessLog::where('record_id', $r->id)->whereHas('status', fn ($s) => $s->where('code', 'authorized'))->exists(), 422, __('api.access_record_out'));
        $d = $request->validate(['location_id' => ['required', $this->orgRule('locations')]]);
        $old = $r->location_id;
        $r->update(['location_id' => $d['location_id']]);
        RecordRules::event($r, 'moved', $old, $d['location_id']);
        AuditLogger::log('record.moved', $r, [], ['reference' => $r->reference]);
        return new RecordResource($r->refresh()->load($this->with()));
    }

    public function hold(Request $request, string $id)
    {
        $r = $this->visible()->with($this->with())->findOrFail($id);
        abort_if($r->status->code === 'disposed', 422, __('api.record_status_invalid'));
        $d = $request->validate(['reason' => 'required|string|max:500']);
        $r->update(['legal_hold' => true, 'hold_reason' => $d['reason']]);
        RecordRules::event($r, 'hold_placed', null, null, $d['reason']);
        AuditLogger::log('record.hold_placed', $r, [], ['reference' => $r->reference], $d['reason']);
        return new RecordResource($r->refresh()->load($this->with()));
    }

    public function release(Request $request, string $id)
    {
        $r = $this->visible()->with($this->with())->findOrFail($id);
        $d = $request->validate(['reason' => 'nullable|string|max:500']);
        $r->update(['legal_hold' => false, 'hold_reason' => null]);
        RecordRules::event($r, 'hold_released', null, null, $d['reason'] ?? null);
        AuditLogger::log('record.hold_released', $r, [], ['reference' => $r->reference], $d['reason'] ?? null);
        return new RecordResource($r->refresh()->load($this->with()));
    }

    public function attachments(string $id)
    {
        $r = $this->visible()->findOrFail($id);
        $q = FileAccess::scopeVisible(FileAsset::query())->with(['classification.translations', 'status.translations', 'owner'])
            ->whereHas('links', fn ($l) => $l->where('link_type_id', Lookup::id('file_link_type', 'record'))->where('link_id', $r->id))->orderByDesc('created_at');
        return FileResource::collection($q->get());
    }

    public function attach(Request $request, string $id)
    {
        $r = $this->visible()->findOrFail($id);
        abort_if($r->status->code === 'disposed', 422, __('api.record_status_invalid'));
        $request->validate(['file' => 'required|file', 'classification_id' => ['nullable', $this->lookupRule('file_classification')]]);
        $asset = FileService::store($request->file('file'), $request->user(), ['classification_id' => $request->input('classification_id', $r->classification_id)], ['type' => 'record', 'id' => $r->id]);
        $asset->load(['classification.translations', 'status.translations', 'owner']);
        AuditLogger::log('file.uploaded', $asset, [], ['name' => $asset->name, 'record' => $r->reference, 'sha256' => $asset->currentVersion->sha256]);
        RecordRules::event($r, 'updated', null, null, 'attachment: ' . $asset->name);
        return (new FileResource($asset))->response()->setStatusCode(201);
    }

    public function import(Request $request)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx|max:5120']);
        $import = new RecordsImport($this->ctx);
        Excel::import($import, $request->file('file'));
        AuditLogger::log('record.imported', null, [], ['created' => $import->created, 'skipped' => count($import->errors)]);
        return response()->json(['created' => $import->created, 'errors' => $import->errors]);
    }
}
