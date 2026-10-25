<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArchiveAccessLog;
use App\Models\ArchiveRecord;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Services\RecordRules;
use App\Support\Lookup;
use Illuminate\Http\Request;

/** Physical archive access register: request -> authorised by someone else -> returned. */
class ArchiveAccessController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array { return ['record', 'requester', 'authorizer', 'status.translations']; }

    private function row(ArchiveAccessLog $l, $lang): array
    {
        $overdue = $l->status->code === 'authorized' && $l->due_back_on && $l->due_back_on->lt(today());
        return [
            'id' => $l->id, 'record' => ['id' => $l->record->id, 'reference' => $l->record->reference, 'title' => $l->record->title, 'index_code' => $l->record->index_code],
            'requester' => ['id' => $l->requester->id, 'name' => $l->requester->name], 'purpose' => $l->purpose,
            'status' => ['id' => $l->status->id, 'code' => $l->status->code, 'label' => $l->status->label($lang), 'color' => $l->status->color],
            'authorized_by' => $l->authorizer?->name, 'decided_at' => $l->decided_at?->toIso8601String(), 'decision_note' => $l->decision_note,
            'accessed_at' => $l->accessed_at?->toIso8601String(), 'due_back_on' => $l->due_back_on?->toDateString(), 'returned_at' => $l->returned_at?->toIso8601String(),
            'overdue' => $overdue, 'created_at' => $l->created_at->toIso8601String(),
            'record_reference' => $l->record->reference, 'record_title' => $l->record->title, 'requested_by_name' => $l->requester->name, 'status_label' => $l->status->label($lang), 'authorized_by_name' => $l->authorizer?->name,
        ];
    }

    public function index(Request $request)
    {
        $q = ArchiveAccessLog::query()->with($this->with());
        if (!$this->ctx->can('archive.manage')) $q->where('requested_by', $request->user()->id);      // others only see their own requests
        if ($request->boolean('open')) $q->whereHas('status', fn ($s) => $s->whereIn('code', ['requested', 'authorized']));
        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->whereHas('record', fn ($r) => $r->where('reference', 'like', "%$s%")->orWhere('title', 'like', "%$s%"))->orWhereHas('requester', fn ($r) => $r->where('name', 'like', "%$s%")));
        }
        if ($f = data_get($request->query('filter'), 'status_id')) $q->where('status_id', $f);
        $q->orderBy('created_at', 'desc');
        $lang = $request->user()->language_id;

        if ($fmt = $request->query('export')) {
            abort_unless($this->ctx->can('data.export'), 403);
            $keys = ['record_reference', 'record_title', 'requested_by_name', 'purpose', 'status_label', 'authorized_by_name', 'accessed_at', 'due_back_on', 'returned_at'];
            $rows = $q->limit(10000)->get()->map(fn ($l) => array_map(fn ($k) => $this->row($l, $lang)[$k] ?? '', $keys))->all();
            return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\ListExport($rows, $keys), 'archive-access-' . now()->format('Ymd-His') . '.' . $fmt);
        }
        $p = $q->paginate(min(max((int) $request->query('per_page', 15), 1), 100));
        return response()->json(['data' => $p->getCollection()->map(fn ($l) => $this->row($l, $lang))->values(),
            'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['record_id' => 'required|string', 'purpose' => 'required|string|max:500', 'due_back_on' => 'nullable|date|after_or_equal:today']);
        $rec = ArchiveRecord::with(['format', 'status'])->findOrFail($d['record_id']);
        abort_unless(in_array($rec->format->code, ['physical', 'hybrid'], true) && in_array($rec->status->code, ['inactive', 'archived'], true), 422, __('api.access_requires_physical'));
        abort_if(ArchiveAccessLog::where('record_id', $rec->id)->whereHas('status', fn ($s) => $s->whereIn('code', ['requested', 'authorized']))->exists(), 422, __('api.access_record_out'));

        $l = ArchiveAccessLog::create(['record_id' => $rec->id, 'requested_by' => $request->user()->id, 'purpose' => $d['purpose'], 'due_back_on' => $d['due_back_on'] ?? null, 'status_id' => Lookup::id('access_log_status', 'requested')]);
        AuditLogger::log('archive_access.requested', $l, [], ['record' => $rec->reference]);
        $to = Notifier::usersWithPermission($this->ctx->organizationId(), 'archive.manage')->reject(fn ($u) => $u->id === $request->user()->id);
        Notifier::send($to, 'archive_access_requested', ['ref' => $rec->reference, 'user' => $request->user()->name], null, ['in_app', 'email'], '/archive/access', $this->ctx->organizationId());
        return response()->json(['data' => ['id' => $l->id]], 201);
    }

    private function decide(Request $request, string $id, string $decision)
    {
        $l = ArchiveAccessLog::with($this->with())->findOrFail($id);
        abort_unless($l->status->code === 'requested', 422, __('api.request_not_pending'));
        abort_if($l->requested_by === $request->user()->id, 403, __('api.access_self_authorize'));       // never authorise your own access
        $d = $request->validate(['note' => 'nullable|string|max:500']);

        $l->update(['status_id' => Lookup::id('access_log_status', $decision), 'authorized_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $d['note'] ?? null]
            + ($decision === 'authorized' ? ['accessed_at' => now()] : []));
        if ($decision === 'authorized') RecordRules::event($l->record, 'access_authorized', null, null, $l->requester->name);
        AuditLogger::log("archive_access.$decision", $l, [], ['record' => $l->record->reference], $d['note'] ?? null);
        Notifier::send($l->requester->loadMissing('language'), 'archive_access_decided', ['ref' => $l->record->reference, 'user' => $request->user()->name, 'decision' => __('api.decision_' . ($decision === 'authorized' ? 'approved' : 'rejected'))], null, ['in_app', 'email'], '/archive/access', $this->ctx->organizationId());
        return response()->json(['data' => $this->row($l->refresh()->load($this->with()), $request->user()->language_id)]);
    }

    public function authorizeAccess(Request $request, string $id) { return $this->decide($request, $id, 'authorized'); }
    public function deny(Request $request, string $id) { return $this->decide($request, $id, 'denied'); }

    public function returned(Request $request, string $id)
    {
        $l = ArchiveAccessLog::with($this->with())->findOrFail($id);
        abort_unless($l->status->code === 'authorized', 422, __('api.request_not_pending'));
        $l->update(['status_id' => Lookup::id('access_log_status', 'returned'), 'returned_at' => now(), 'received_by' => $request->user()->id]);
        RecordRules::event($l->record, 'returned', null, null, $l->requester->name);
        AuditLogger::log('archive_access.returned', $l, [], ['record' => $l->record->reference]);
        return response()->json(['data' => $this->row($l->refresh()->load($this->with()), $request->user()->language_id)]);
    }
}
