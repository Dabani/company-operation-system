<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArchiveRecord;
use App\Models\InventoryCount;
use App\Models\InventoryCountItem;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\ObligationService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Periodic inventory of physical records. Missing items are discrepancies reported to the compliance role. */
class InventoryController extends Controller
{
    public function __construct(private OrganizationContext $ctx) {}

    private function row(InventoryCount $c, $lang): array
    {
        return [
            'id' => $c->id, 'title' => $c->title, 'status' => ['code' => $c->status->code, 'label' => $c->status->label($lang), 'color' => $c->status->color],
            'location' => $c->location ? $c->location->code . ' — ' . $c->location->name : null, 'started_by' => $c->starter?->name,
            'started_at' => $c->started_at?->toIso8601String(), 'completed_at' => $c->completed_at?->toIso8601String(),
            'total_expected' => $c->total_expected, 'total_found' => $c->total_found, 'total_missing' => $c->total_missing, 'notes' => $c->notes,
            'checked' => $c->items_checked ?? null,
        ];
    }

    public function index(Request $request)
    {
        $rows = InventoryCount::with(['status.translations', 'starter', 'location'])->withCount(['items as items_checked' => fn ($q) => $q->whereNotNull('found')])->orderByDesc('started_at')->limit(100)->get();
        return response()->json(['data' => $rows->map(fn ($c) => $this->row($c, $request->user()->language_id))]);
    }

    public function store(Request $request)
    {
        $d = $request->validate(['title' => 'required|string|max:200', 'location_id' => ['nullable', Rule::exists('locations', 'id')->where('organization_id', $this->ctx->organizationId())]]);
        abort_if(InventoryCount::whereHas('status', fn ($s) => $s->where('code', 'in_progress'))->exists(), 422, __('api.inventory_open'));

        $c = DB::transaction(function () use ($d, $request) {
            $records = ArchiveRecord::query()->whereHas('format', fn ($f) => $f->whereIn('code', ['physical', 'hybrid']))->whereHas('status', fn ($s) => $s->whereIn('code', ['inactive', 'archived', 'disposal_pending']))
                ->when(!empty($d['location_id']), fn ($q) => $q->where('location_id', $d['location_id']))->pluck('id');
            $c = InventoryCount::create(['title' => $d['title'], 'location_id' => $d['location_id'] ?? null, 'status_id' => Lookup::id('inventory_status', 'in_progress'), 'started_by' => $request->user()->id, 'total_expected' => $records->count()]);
            foreach ($records->chunk(500) as $chunk) {
                InventoryCountItem::insert($chunk->map(fn ($id) => ['count_id' => $c->id, 'record_id' => $id])->all());
            }
            return $c;
        });
        AuditLogger::log('inventory.started', $c, [], ['title' => $c->title, 'expected' => (string) $c->total_expected]);
        return response()->json(['data' => ['id' => $c->id]], 201);
    }

    public function show(Request $request, string $id)
    {
        $c = InventoryCount::with(['status.translations', 'starter', 'location'])->withCount(['items as items_checked' => fn ($q) => $q->whereNotNull('found')])->findOrFail($id);
        return response()->json(['data' => $this->row($c, $request->user()->language_id)]);
    }

    /** Checklist rows. ?filter=unchecked|missing, ?search= */
    public function items(Request $request, string $id)
    {
        $c = InventoryCount::findOrFail($id);
        $q = InventoryCountItem::with(['record.location'])->where('count_id', $c->id);
        match ($request->query('state')) { 'unchecked' => $q->whereNull('found'), 'missing' => $q->where('found', false), 'found' => $q->where('found', true), default => null };
        if ($s = trim((string) $request->query('search', ''))) $q->whereHas('record', fn ($r) => $r->where('reference', 'like', "%$s%")->orWhere('title', 'like', "%$s%")->orWhere('index_code', 'like', "%$s%"));
        $p = $q->orderBy('id')->paginate(min(max((int) $request->query('per_page', 25), 1), 100));

        return response()->json(['data' => $p->getCollection()->map(fn ($i) => [
            'id' => $i->id, 'found' => $i->found, 'note' => $i->note,
            'record' => ['id' => $i->record->id, 'reference' => $i->record->reference, 'title' => $i->record->title, 'index_code' => $i->record->index_code,
                'location' => $i->record->location ? $i->record->location->code . ' — ' . $i->record->location->name : null],
        ])->values(), 'meta' => ['current_page' => $p->currentPage(), 'last_page' => $p->lastPage(), 'per_page' => $p->perPage(), 'total' => $p->total()]]);
    }

    public function mark(Request $request, string $id, int $itemId)
    {
        $c = InventoryCount::with('status')->findOrFail($id);
        abort_unless($c->status->code === 'in_progress', 422, __('api.request_not_pending'));
        $d = $request->validate(['found' => 'required|boolean', 'note' => 'nullable|string|max:250']);
        $i = InventoryCountItem::where('count_id', $c->id)->findOrFail($itemId);
        $i->update(['found' => $d['found'], 'note' => $d['note'] ?? null, 'checked_at' => now(), 'checked_by' => $request->user()->id]);
        return response()->json(['data' => ['id' => $i->id, 'found' => $i->found]]);
    }

    public function complete(Request $request, string $id)
    {
        $c = InventoryCount::with('status')->findOrFail($id);
        abort_unless($c->status->code === 'in_progress', 422, __('api.request_not_pending'));
        abort_if(InventoryCountItem::where('count_id', $c->id)->whereNull('found')->exists(), 422, __('api.inventory_unchecked'));
        $d = $request->validate(['notes' => 'nullable|string|max:1000']);

        $found = InventoryCountItem::where('count_id', $c->id)->where('found', true)->count();
        $missing = InventoryCountItem::where('count_id', $c->id)->where('found', false)->count();
        $c->update(['status_id' => Lookup::id('inventory_status', 'completed'), 'completed_at' => now(), 'total_found' => $found, 'total_missing' => $missing, 'notes' => $d['notes'] ?? null]);
        AuditLogger::log('inventory.completed', $c, [], ['found' => (string) $found, 'missing' => (string) $missing]);
        ObligationService::autoComplete($this->ctx->organizationId(), 'records_inventory', $request->user(), $c->title);

        if ($missing > 0) {     // discrepancies are reported to the compliance role
            Notifier::send(Notifier::usersWithPermission($this->ctx->organizationId(), 'compliance.manage'), 'inventory_discrepancy', ['title' => $c->title, 'missing' => $missing, 'user' => $request->user()->name], null, ['in_app', 'email'], '/archive/inventory', $this->ctx->organizationId());
        }
        return $this->show($request, $id);
    }
}
