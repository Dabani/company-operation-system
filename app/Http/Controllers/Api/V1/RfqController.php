<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RfqRowResource;
use App\Models\PurchaseRequisition;
use App\Models\Rfq;
use App\Models\PurchaseOrder;
use App\Services\OrganizationContext;
use App\Services\Procurement\RfqRules;
use App\Services\Procurement\RfqService;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Requests for quotation. Buyers only (procurement.manage): quote prices are commercially sensitive and never shown to requesters. */
class RfqController extends Controller
{
    use AppliesListQuery;

    public function __construct(private RfqService $svc, private OrganizationContext $ctx) {}

    private function find(string $id): Rfq { return Rfq::findOrFail($id); }

    private function scoped(string $table) { return Rule::exists($table, 'id')->where('organization_id', $this->ctx->organizationId()); }

    private function detail(Rfq $r): array
    {
        $r->loadMissing(['status', 'requisition.lines', 'quotes.supplier', 'quotes.lines', 'history.to', 'history.changedBy', 'creator:id,name']);
        $code = $r->status->code;
        $eligible = $this->svc->eligible($r);
        $lowest = RfqRules::lowest($eligible);
        $lineCount = $r->requisition->lines->count();
        $order = PurchaseOrder::where('rfq_id', $r->id)->where('status_id', '!=', \App\Support\Lookup::id('po_status', 'cancelled'))->first();
        return [
            'id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'instructions' => $r->instructions, 'status' => $code, 'closing_date' => $r->closing_date->toDateString(), 'sole_source_reason' => $r->sole_source_reason,
            'award_reason' => $r->award_reason, 'awarded_quote_id' => $r->awarded_quote_id, 'status_note' => $r->status_note, 'created_by' => $r->creator?->name, 'currency_code' => $r->requisition->currency_code,
            'min_suppliers' => (int) \App\Services\SettingsService::get($r->organization_id, 'procurement.rfq_min_suppliers'),
            'requisition' => ['id' => $r->requisition_id, 'code' => $r->requisition->code, 'total_amount' => $r->requisition->total_amount],
            'order' => $order ? ['id' => $order->id, 'code' => $order->code] : null,
            'lines' => $r->requisition->lines->map(fn ($l) => ['id' => $l->id, 'line_no' => $l->line_no, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit, 'estimated_price' => $l->unit_price])->values(),
            'quotes' => $r->quotes->map(function ($q) use ($eligible, $lowest, $lineCount) {
                return ['id' => $q->id, 'supplier' => ['id' => $q->supplier_id, 'code' => $q->supplier->code, 'name' => $q->supplier->name], 'status' => $q->declined ? 'declined' : ($q->submitted_at ? 'received' : 'invited'),
                    'lead_days' => $q->lead_days, 'valid_until' => $q->valid_until?->toDateString(), 'notes' => $q->notes, 'total_amount' => $q->total_amount, 'decline_reason' => $q->decline_reason,
                    'complete' => RfqRules::complete($lineCount, $q->lines->count()), 'eligible' => isset($eligible[$q->id]), 'lowest' => in_array($q->id, $lowest, true),
                    'prices' => $q->lines->mapWithKeys(fn ($x) => [$x->requisition_line_id => $x->unit_price])];
            })->values(),
            'history' => $r->history->map(fn ($h) => ['status' => $h->to->code, 'by' => $h->changedBy?->name, 'note' => $h->note, 'at' => $h->created_at->toIso8601String()])->values(),
            'me' => ['can_edit' => RfqRules::editable($code), 'can_open' => $code === 'draft' || $code === 'closed', 'can_close' => $code === 'open', 'can_cancel' => RfqRules::allowed($code, 'cancelled'),
                'can_quote' => RfqRules::canQuote($code), 'can_award' => RfqRules::allowed($code, 'awarded') && count($eligible) > 0, 'can_create_order' => $code === 'awarded' && !$order],
        ];
    }

    public function index(Request $request)
    {
        $q = Rfq::query()->with(['status', 'requisition'])->withCount('quotes');
        return $this->respondList($request, $q, RfqRowResource::class, ['code', 'title'], ['created_at', 'closing_date', 'code'], ['status_id', 'requisition_id'], ['code', 'title', 'status', 'requisition', 'closing_date']);
    }

    public function show(string $id) { return ['data' => $this->detail($this->find($id))]; }

    public function store(Request $request)
    {
        $d = $request->validate(['requisition_id' => ['required', $this->scoped('purchase_requisitions')], 'title' => 'nullable|string|max:200', 'instructions' => 'nullable|string|max:20000', 'closing_date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'supplier_ids' => 'required|array|min:1|max:50', 'supplier_ids.*' => ['string', $this->scoped('suppliers')]]);
        $r = $this->svc->create(PurchaseRequisition::findOrFail($d['requisition_id']), $d, $d['supplier_ids'], $request->user());
        return response(['data' => $this->detail($r)], 201);
    }

    public function update(Request $request, string $id)
    {
        $d = $request->validate(['title' => 'sometimes|string|max:200', 'instructions' => 'nullable|string|max:20000', 'closing_date' => 'sometimes|date_format:Y-m-d|after_or_equal:today',
            'supplier_ids' => 'sometimes|array|min:1|max:50', 'supplier_ids.*' => ['string', $this->scoped('suppliers')]]);
        $ids = $d['supplier_ids'] ?? null;
        unset($d['supplier_ids']);
        return ['data' => $this->detail($this->svc->update($this->find($id), $d, $ids, $request->user()))];
    }

    public function status(Request $request, string $id)
    {
        $d = $request->validate(['to' => 'required|string|in:open,closed,cancelled', 'note' => 'nullable|string|max:1000', 'sole_source_reason' => 'nullable|string|max:1000']);
        return ['data' => $this->detail($this->svc->move($this->find($id), $d['to'], $d['note'] ?? null, $d['sole_source_reason'] ?? null, $request->user()))];
    }

    public function quote(Request $request, string $id)
    {
        $d = $request->validate(['supplier_id' => ['required', $this->scoped('suppliers')], 'declined' => 'sometimes|boolean', 'decline_reason' => 'nullable|string|max:500', 'lead_days' => 'nullable|integer|min:0|max:3650',
            'valid_until' => 'nullable|date_format:Y-m-d', 'notes' => 'nullable|string|max:2000', 'prices' => 'nullable|array|max:100', 'prices.*' => 'nullable|numeric|min:0|max:999999999999']);
        $r = $this->find($id);
        if (!empty($d['declined'])) {
            $this->svc->declineQuote($r, $d['supplier_id'], $d['decline_reason'] ?? null, $request->user());
        } else {
            $this->svc->recordQuote($r, $d['supplier_id'], $d, $d['prices'] ?? [], $request->user());
        }
        return ['data' => $this->detail($r->refresh())];
    }

    public function award(Request $request, string $id)
    {
        $d = $request->validate(['quote_id' => 'required|string', 'reason' => 'nullable|string|max:1000']);
        return ['data' => $this->detail($this->svc->award($this->find($id), $d['quote_id'], $d['reason'] ?? null, $request->user()))];
    }

    public function createOrder(Request $request, string $id)
    {
        $o = $this->svc->createOrder($this->find($id), $request->user());
        return response(['data' => ['id' => $o->id, 'code' => $o->code]], 201);
    }
}
