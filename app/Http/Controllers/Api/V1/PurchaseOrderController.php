<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PurchaseOrderRowResource;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Services\OrganizationContext;
use App\Services\Procurement\OrderAccess;
use App\Services\Procurement\OrderRules;
use App\Services\Procurement\OrderService;
use App\Services\Procurement\ProcurementAccess;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase orders and goods receipts. Buyers raise and run orders; the requester of the source requisition follows delivery and may confirm receipt. */
class PurchaseOrderController extends Controller
{
    use AppliesListQuery;

    public function __construct(private OrderService $svc, private OrderAccess $access, private ProcurementAccess $proc, private OrganizationContext $ctx) {}

    private function scoped(string $table) { return Rule::exists($table, 'id')->where('organization_id', $this->ctx->organizationId()); }

    private function detail(PurchaseOrder $o, Request $request): array
    {
        $o->loadMissing(['status', 'supplier', 'requisition.requester:id,name', 'lines', 'receipts.receiver:id,name', 'receipts.lines', 'history.to', 'history.changedBy', 'creator:id,name']);
        $code = $o->status->code;
        $u = $request->user();
        $buyer = $this->proc->buyer();
        $canReceive = OrderRules::canReceive($code) && $this->ctx->can('procurement.receive') && OrderRules::receiverAllowed($o->created_by, $u->id);
        return [
            'id' => $o->id, 'code' => $o->code, 'title' => $o->title, 'status' => $code, 'terms' => $o->terms, 'delivery_location' => $o->delivery_location, 'needed_by' => $o->needed_by->toDateString(),
            'currency_code' => $o->currency_code, 'total_amount' => $o->total_amount, 'status_note' => $o->status_note, 'issued_at' => $o->issued_at?->toIso8601String(), 'approval_request_id' => $buyer ? $o->approval_request_id : null,
            'supplier' => ['id' => $o->supplier_id, 'code' => $o->supplier->code, 'name' => $o->supplier->name], 'supplier_id' => $o->supplier_id,
            'requisition' => ['id' => $o->requisition_id, 'code' => $o->requisition->code, 'requester' => $o->requisition->requester?->name, 'total_amount' => $o->requisition->total_amount], 'created_by' => $o->creator?->name,
            'lines' => $o->lines->map(fn ($l) => ['id' => $l->id, 'line_no' => $l->line_no, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit, 'unit_price' => $l->unit_price, 'line_total' => $l->line_total,
                'accepted_quantity' => $l->accepted_quantity, 'remaining' => number_format(OrderRules::remaining($l->quantity, $l->accepted_quantity) / 1000, 3, '.', '')])->values(),
            'receipts' => $o->receipts->map(fn ($g) => ['id' => $g->id, 'code' => $g->code, 'received_on' => $g->received_on->toDateString(), 'delivery_note' => $g->delivery_note, 'note' => $g->note, 'received_by' => $g->receiver?->name,
                'lines' => $g->lines->map(fn ($l) => ['order_line_id' => $l->order_line_id, 'quantity_received' => $l->quantity_received, 'quantity_accepted' => $l->quantity_accepted, 'note' => $l->note])->values()])->values(),
            'history' => $o->history->map(fn ($h) => ['status' => $h->to->code, 'by' => $h->changedBy?->name, 'note' => $h->note, 'at' => $h->created_at->toIso8601String()])->values(),
            'me' => ['can_edit' => $buyer && OrderRules::editable($code), 'can_submit' => $buyer && $code === 'draft', 'can_issue' => $buyer && $code === 'approved',
                'can_cancel' => $buyer && OrderRules::allowed($code, 'cancelled'), 'can_close' => $buyer && OrderRules::allowed($code, 'closed'), 'can_receive' => $canReceive],
        ];
    }

    public function index(Request $request)
    {
        $q = $this->access->scope(PurchaseOrder::query(), $request->user())->with(['status', 'supplier', 'requisition']);
        return $this->respondList($request, $q, PurchaseOrderRowResource::class, ['code', 'title'], ['created_at', 'needed_by', 'total_amount', 'code'], ['status_id', 'supplier_id', 'requisition_id'],
            ['code', 'title', 'status', 'supplier', 'requisition', 'needed_by', 'currency_code', 'total_amount']);
    }

    public function show(Request $request, string $id)
    {
        return ['data' => $this->detail($this->access->find($request->user(), $id), $request)];
    }

    public function store(Request $request)
    {
        $d = $request->validate(['requisition_id' => ['required', $this->scoped('purchase_requisitions')], 'supplier_id' => ['required', $this->scoped('suppliers')], 'terms' => 'nullable|string|max:20000',
            'delivery_location' => 'nullable|string|max:300', 'needed_by' => 'nullable|date_format:Y-m-d']);
        $r = PurchaseRequisition::findOrFail($d['requisition_id']);
        $o = $this->svc->create($r, $d['supplier_id'], $d, $request->user());
        return response(['data' => $this->detail($o, $request)], 201);
    }

    public function update(Request $request, string $id)
    {
        $d = $request->validate(['supplier_id' => ['sometimes', $this->scoped('suppliers')], 'terms' => 'nullable|string|max:20000', 'delivery_location' => 'sometimes|string|max:300', 'needed_by' => 'sometimes|date_format:Y-m-d',
            'lines' => 'sometimes|array|max:100', 'lines.*.id' => 'required|string', 'lines.*.unit_price' => 'required|numeric|min:0|max:999999999999']);
        $prices = $d['lines'] ?? null;
        unset($d['lines']);
        $o = $this->svc->update($this->access->find($request->user(), $id), $d, $prices, $request->user());
        return ['data' => $this->detail($o, $request)];
    }

    public function submit(Request $request, string $id)
    {
        return ['data' => $this->detail($this->svc->submit($this->access->find($request->user(), $id), $request->user()), $request)];
    }

    public function issue(Request $request, string $id)
    {
        return ['data' => $this->detail($this->svc->issue($this->access->find($request->user(), $id), $request->user()), $request)];
    }

    public function status(Request $request, string $id)
    {
        $d = $request->validate(['to' => 'required|string|in:cancelled,closed', 'note' => 'nullable|string|max:1000']);
        return ['data' => $this->detail($this->svc->move($this->access->find($request->user(), $id), $d['to'], $d['note'] ?? null, $request->user()), $request)];
    }

    public function receive(Request $request, string $id)
    {
        $d = $request->validate(['received_on' => 'required|date_format:Y-m-d|before_or_equal:today', 'delivery_note' => 'nullable|string|max:120', 'note' => 'nullable|string|max:1000',
            'lines' => 'required|array|min:1|max:100', 'lines.*.order_line_id' => 'required|string', 'lines.*.quantity_received' => 'required|numeric|min:0', 'lines.*.quantity_accepted' => 'required|numeric|min:0', 'lines.*.note' => 'nullable|string|max:500']);
        $lines = array_values(array_filter($d['lines'], fn ($l) => (float) $l['quantity_received'] > 0));      // lines with nothing delivered are left out
        if (!$lines) {
            throw \Illuminate\Validation\ValidationException::withMessages(['lines' => [__('procurement.receipt_bad_quantity')]]);
        }
        $o = $this->access->find($request->user(), $id);
        $this->svc->receive($o, $d, $lines, $request->user());
        return response(['data' => $this->detail($o->refresh(), $request)], 201);
    }
}
