<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\EquipmentMovement;
use App\Models\LookupValue;
use App\Services\AssetLog;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Services\References;
use App\Support\Lookup;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MovementController extends Controller
{
    private const ACTIVE = ['pending', 'approved', 'checked_out'];

    public function __construct(private OrganizationContext $ctx) {}

    private function with(): array { return ['status.translations', 'requester', 'approver', 'assets']; }

    protected function visible()
    {
        $q = EquipmentMovement::query();
        if (!$this->ctx->can('asset.manage') && !$this->ctx->can('asset.approve_movement')) {
            $q->where('requested_by', request()->user()->id);
        }
        return $q;
    }

    private function row(EquipmentMovement $m, $lang, bool $detail = false): array
    {
        $overdue = $m->status->code === 'checked_out' && $m->expected_return_date && $m->expected_return_date->isPast() && !$m->expected_return_date->isToday();
        $base = [
            'id' => $m->id, 'reference' => $m->reference, 'destination' => $m->destination,
            'status' => ['id' => $m->status->id, 'code' => $m->status->code, 'label' => $m->status->label($lang), 'color' => $m->status->color],
            'requester' => ['id' => $m->requester->id, 'name' => $m->requester->name], 'approver' => $m->approver?->name,
            'departure_date' => $m->departure_date->toDateString(), 'expected_return_date' => $m->expected_return_date?->toDateString(),
            'is_overdue' => $overdue, 'assets_count' => $m->assets->count(), 'created_at' => $m->created_at->toIso8601String(),
            'requester_name' => $m->requester->name, 'status_label' => $m->status->label($lang),
        ];
        if (!$detail) return $base;

        $conds = LookupValue::with('translations')->whereIn('id', $m->assets->flatMap(fn ($a) => [$a->pivot->condition_out_id, $a->pivot->condition_in_id])->filter()->unique())->get()->keyBy('id');
        return $base + [
            'purpose' => $m->purpose, 'decision_note' => $m->decision_note, 'decided_at' => $m->decided_at?->toIso8601String(),
            'checked_out_at' => $m->checked_out_at?->toIso8601String(), 'returned_at' => $m->returned_at?->toIso8601String(), 'return_note' => $m->return_note,
            'assets' => $m->assets->map(fn ($a) => [
                'id' => $a->id, 'asset_tag' => $a->asset_tag, 'name' => $a->name, 'serial_number' => $a->serial_number,
                'condition_out' => $conds[$a->pivot->condition_out_id]?->label($lang), 'condition_in' => $conds[$a->pivot->condition_in_id]?->label($lang),
                'condition_out_id' => $a->pivot->condition_out_id, 'condition_in_id' => $a->pivot->condition_in_id,
            ])->values(),
        ];
    }

    public function index(Request $request)
    {
        $q = $this->visible()->with($this->with());
        if ($request->boolean('mine')) $q->where('requested_by', $request->user()->id);
        if ($request->boolean('overdue')) {
            $q->whereHas('status', fn ($s) => $s->where('code', 'checked_out'))->whereDate('expected_return_date', '<', today());
        }
        return $this->respondListRaw($request, $q, $request->user()->language_id);
    }

    /** List with the same query contract as respondList(), mapping rows with row(). */
    private function respondListRaw(Request $request, $q, $lang)
    {
        if ($s = trim((string) $request->query('search', ''))) {
            $q->where(fn ($w) => $w->where('reference', 'like', "%$s%")->orWhere('destination', 'like', "%$s%")->orWhereHas('requester', fn ($r) => $r->where('name', 'like', "%$s%")));
        }
        foreach ((array) $request->query('filter', []) as $col => $v) {
            if ($col === 'status_id' && $v) $q->where('status_id', $v);
        }
        $sort = in_array($request->query('sort'), ['reference', 'departure_date', 'created_at', 'expected_return_date'], true) ? $request->query('sort') : 'created_at';
        $q->orderBy($sort, strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc');

        if ($fmt = $request->query('export')) {
            abort_unless($this->ctx->can('data.export'), 403);
            abort_unless(in_array($fmt, ['csv', 'xlsx'], true), 422);
            $keys = ['reference', 'requester_name', 'destination', 'status_label', 'departure_date', 'expected_return_date', 'assets_count'];
            $rows = $q->limit(10000)->get()->map(fn ($m) => array_map(fn ($k) => $this->row($m, $lang)[$k] ?? '', $keys))->all();
            return \Maatwebsite\Excel\Facades\Excel::download(new \App\Exports\ListExport($rows, $keys), 'movements-' . now()->format('Ymd-His') . '.' . $fmt);
        }

        $page = $q->paginate(min(max((int) $request->query('per_page', 15), 1), 100))->withQueryString();
        return response()->json([
            'data' => $page->getCollection()->map(fn ($m) => $this->row($m, $lang))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(Request $request, string $id)
    {
        $m = $this->visible()->with($this->with())->findOrFail($id);
        return response()->json(['data' => $this->row($m, $request->user()->id ? $request->user()->language_id : null, true)]);
    }

    /** Assets the current user may put on a movement form (their own, or any available asset for register managers). */
    public function candidates(Request $request)
    {
        $busy = DB::table('equipment_movement_items as i')->join('equipment_movements as m', 'm.id', '=', 'i.movement_id')
            ->join('lookup_values as s', 's.id', '=', 'm.status_id')->whereIn('s.code', self::ACTIVE)->pluck('i.asset_id');
        $q = Asset::query()->whereHas('status', fn ($s) => $s->whereIn('code', ['in_stock', 'assigned']))->whereNotIn('id', $busy);
        if (!$this->ctx->can('asset.manage')) $q->where('assigned_user_id', $request->user()->id);
        return response()->json(['data' => $q->orderBy('asset_tag')->limit(200)->get()->map(fn ($a) => ['id' => $a->id, 'label' => $a->asset_tag . ' — ' . $a->name])]);
    }

    public function store(Request $request)
    {
        $d = $request->validate([
            'asset_ids' => 'required|array|min:1|max:50', 'asset_ids.*' => 'string',
            'destination' => 'required|string|max:250',
            'departure_date' => 'required|date|after_or_equal:today',
            'expected_return_date' => 'nullable|date|after_or_equal:departure_date',
            'description' => 'nullable|string|max:20000',                       // the purpose (purified rich text)
        ]);
        $user = $request->user();

        $assets = Asset::with('status')->whereIn('id', array_unique($d['asset_ids']))->get();
        abort_unless($assets->count() === count(array_unique($d['asset_ids'])), 422, __('api.asset_not_yours'));
        foreach ($assets as $a) {
            abort_unless(in_array($a->status->code, ['in_stock', 'assigned'], true), 422, __('api.asset_assign_blocked'));
            abort_unless($this->ctx->can('asset.manage') || $a->assigned_user_id === $user->id, 403, __('api.asset_not_yours'));
            $busy = DB::table('equipment_movement_items as i')->join('equipment_movements as m', 'm.id', '=', 'i.movement_id')->join('lookup_values as s', 's.id', '=', 'm.status_id')
                ->where('i.asset_id', $a->id)->whereIn('s.code', self::ACTIVE)->exists();
            abort_if($busy, 422, __('api.asset_in_movement'));
        }

        $m = DB::transaction(function () use ($d, $assets, $user) {
            $m = EquipmentMovement::create([
                'reference' => References::next($this->ctx->organizationId(), 'movement', 'MOV', true),
                'requested_by' => $user->id, 'status_id' => Lookup::id('movement_status', 'pending'),
                'purpose' => $d['description'] ?? null, 'destination' => $d['destination'],
                'departure_date' => $d['departure_date'], 'expected_return_date' => $d['expected_return_date'] ?? null,
            ]);
            foreach ($assets as $a) {
                $m->assets()->attach($a->id, ['status_before_id' => $a->status_id, 'condition_out_id' => $a->condition_id]);
            }
            return $m;
        });
        AuditLogger::log('movement.requested', $m, [], ['reference' => $m->reference, 'assets' => (string) $assets->count()]);

        $approvers = Notifier::usersWithPermission($this->ctx->organizationId(), 'asset.approve_movement')->reject(fn ($u) => $u->id === $user->id);
        Notifier::send($approvers, 'movement_requested', ['ref' => $m->reference, 'user' => $user->name, 'dest' => $m->destination], null, ['in_app', 'email'], '/assets/movements?open=' . $m->id, $this->ctx->organizationId());

        return response()->json(['data' => ['id' => $m->id, 'reference' => $m->reference]], 201);
    }

    private function decide(Request $request, string $id, string $decision)
    {
        $m = $this->visible()->with($this->with())->findOrFail($id);
        abort_unless($m->status->code === 'pending', 422, __('api.movement_not_pending'));
        abort_if($m->requested_by === $request->user()->id, 403, __('api.movement_self_approval'));     // four-eyes principle
        $d = $request->validate(['note' => 'nullable|string|max:500']);

        $m->update(['status_id' => Lookup::id('movement_status', $decision), 'decided_by' => $request->user()->id, 'decided_at' => now(), 'decision_note' => $d['note'] ?? null]);
        AuditLogger::log("movement.$decision", $m, [], ['reference' => $m->reference], $d['note'] ?? null);
        Notifier::send($m->requester->loadMissing('language'), 'movement_decided', ['ref' => $m->reference, 'user' => $request->user()->name, 'decision' => __("api.decision_" . ($decision === 'approved' ? 'approved' : 'rejected'))], null, ['in_app', 'email'], '/assets/movements?open=' . $m->id, $this->ctx->organizationId());
        return response()->json(['data' => $this->row($m->refresh()->load($this->with()), $request->user()->language_id, true)]);
    }

    public function approve(Request $request, string $id) { return $this->decide($request, $id, 'approved'); }
    public function reject(Request $request, string $id) { return $this->decide($request, $id, 'rejected'); }

    /** Security / IT hands the approved equipment over: assets become "off site". */
    public function checkout(Request $request, string $id)
    {
        $m = $this->visible()->with($this->with())->findOrFail($id);
        abort_unless($m->status->code === 'approved', 422, __('api.movement_not_approved'));

        DB::transaction(function () use ($m, $request) {
            foreach ($m->assets as $a) {
                $old = $a->status_id;
                $a->update(['status_id' => Lookup::id('asset_status', 'off_site')]);
                AssetLog::event($a, 'movement_out', $old, $a->status_id, $m->reference);
            }
            $m->update(['status_id' => Lookup::id('movement_status', 'checked_out'), 'checked_out_by' => $request->user()->id, 'checked_out_at' => now()]);
        });
        AuditLogger::log('movement.checked_out', $m, [], ['reference' => $m->reference]);
        return response()->json(['data' => $this->row($m->refresh()->load($this->with()), $request->user()->language_id, true)]);
    }

    public function returnBack(Request $request, string $id)
    {
        $m = $this->visible()->with($this->with())->findOrFail($id);
        abort_unless($m->status->code === 'checked_out', 422, __('api.movement_not_out'));
        $d = $request->validate(['return_note' => 'nullable|string|max:500', 'conditions' => 'nullable|array', 'conditions.*.asset_id' => 'required|string', 'conditions.*.condition_id' => 'required|string']);
        $cond = collect($d['conditions'] ?? [])->pluck('condition_id', 'asset_id');

        DB::transaction(function () use ($m, $request, $d, $cond) {
            foreach ($m->assets as $a) {
                $before = $a->pivot->status_before_id;
                $newCond = $cond[$a->id] ?? $a->condition_id;
                $m->assets()->updateExistingPivot($a->id, ['condition_in_id' => $newCond]);
                $a->update(['status_id' => $before ?: Lookup::id('asset_status', 'in_stock'), 'condition_id' => $newCond]);
                AssetLog::event($a, 'movement_back', null, $a->status_id, $m->reference);
            }
            $m->update(['status_id' => Lookup::id('movement_status', 'returned'), 'returned_by' => $request->user()->id, 'returned_at' => now(), 'return_note' => $d['return_note'] ?? null]);
        });
        AuditLogger::log('movement.returned', $m, [], ['reference' => $m->reference]);
        return response()->json(['data' => $this->row($m->refresh()->load($this->with()), $request->user()->language_id, true)]);
    }

    public function cancel(Request $request, string $id)
    {
        $m = $this->visible()->with($this->with())->findOrFail($id);
        abort_unless($m->status->code === 'pending', 422, __('api.movement_not_pending'));
        abort_unless($m->requested_by === $request->user()->id || $this->ctx->can('asset.manage'), 403);
        $m->update(['status_id' => Lookup::id('movement_status', 'cancelled')]);
        AuditLogger::log('movement.cancelled', $m, [], ['reference' => $m->reference]);
        return response()->json(['data' => $this->row($m->refresh()->load($this->with()), $request->user()->language_id, true)]);
    }

    /** Printable Equipment Movement Authorization form (A4, signature blocks). */
    public function pdf(Request $request, string $id)
    {
        $m = $this->visible()->with($this->with())->findOrFail($id);
        app()->setLocale($request->user()->language?->code ?? config('ops.default_language'));

        $html = view('pdf.movement', [
            'm' => $m, 'org' => $this->ctx->organization(), 'purpose' => trim(html_entity_decode(strip_tags((string) $m->purpose))),
            'department' => optional($m->requester->memberships()->where('organization_id', $this->ctx->organizationId())->first()?->department)->name,
        ])->render();

        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);   // Verdana is not bundled with Dompdf
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        AuditLogger::log('movement.pdf_generated', $m, [], ['reference' => $m->reference]);

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $m->reference . '.pdf"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
