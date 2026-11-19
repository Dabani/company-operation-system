<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierInvoiceRowResource;
use App\Models\PurchaseOrder;
use App\Models\SupplierInvoice;
use App\Services\OrganizationContext;
use App\Services\Procurement\InvoiceRules;
use App\Services\Procurement\InvoiceService;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Supplier invoices: recorded against an issued order, matched automatically, held on discrepancy, approved by someone independent, then marked paid. */
class SupplierInvoiceController extends Controller
{
    use AppliesListQuery;

    public function __construct(private InvoiceService $svc, private OrganizationContext $ctx) {}

    private function find(string $id): SupplierInvoice
    {
        return SupplierInvoice::findOrFail($id);
    }

    private function detail(SupplierInvoice $i, Request $request): array
    {
        $i->loadMissing(['status', 'supplier', 'order', 'recorder:id,name', 'lines.orderLine', 'issues', 'history.to', 'history.changedBy']);
        $code = $i->status->code;
        $uid = $request->user()->id;
        $manage = $this->ctx->can('procurement.invoice');
        $approve = $this->ctx->can('procurement.invoice.approve');
        $pay = $this->ctx->can('procurement.invoice.pay');
        return [
            'id' => $i->id, 'code' => $i->code, 'invoice_number' => $i->invoice_number, 'status' => $code, 'invoice_date' => $i->invoice_date->toDateString(), 'due_date' => $i->due_date?->toDateString(),
            'currency_code' => $i->currency_code, 'subtotal' => $i->subtotal, 'tax_amount' => $i->tax_amount, 'total_amount' => $i->total_amount, 'exception_approved' => $i->exception_approved,
            'exception_note' => $i->exception_note, 'decision_note' => $i->decision_note, 'approved_at' => $i->approved_at?->toIso8601String(), 'paid_on' => $i->paid_on?->toDateString(), 'payment_reference' => $i->payment_reference,
            'supplier' => ['id' => $i->supplier_id, 'code' => $i->supplier->code, 'name' => $i->supplier->name],
            'order' => ['id' => $i->order_id, 'code' => $i->order->code, 'title' => $i->order->title, 'total_amount' => $i->order->total_amount, 'created_by' => $i->order->created_by], 'recorded_by' => $i->recorder?->name,
            'lines' => $i->lines->map(fn ($l) => ['id' => $l->id, 'order_line_id' => $l->order_line_id, 'description' => $l->orderLine?->description, 'quantity' => $l->quantity, 'unit_price' => $l->unit_price,
                'line_total' => $l->line_total, 'ordered_price' => $l->orderLine?->unit_price, 'accepted_quantity' => $l->orderLine?->accepted_quantity])->values(),
            'issues' => $i->issues->map(fn ($x) => ['order_line_id' => $x->order_line_id, 'code' => $x->code, 'detail' => $x->detail])->values(),
            'history' => $i->history->map(fn ($h) => ['status' => $h->to->code, 'by' => $h->changedBy?->name, 'note' => $h->note, 'at' => $h->created_at->toIso8601String()])->values(),
            'me' => [
                'can_rematch' => $manage && $code === 'on_hold',
                'can_decide_exception' => $approve && $code === 'on_hold' && InvoiceRules::exceptionDeciderAllowed($i->recorded_by, $uid),
                'can_approve' => $approve && $code === 'matched' && InvoiceRules::approverAllowed($i->recorded_by, $i->order->created_by, $uid),
                'can_reject' => $approve && $code === 'matched',
                'can_pay' => $pay && $code === 'approved' && InvoiceRules::payerAllowed($i->approved_by, $uid),
                'can_cancel' => $manage && InvoiceRules::allowed($code, 'cancelled'),
            ],
        ];
    }

    public function index(Request $request)
    {
        $q = SupplierInvoice::query()->with(['status', 'supplier', 'order']);
        return $this->respondList($request, $q, SupplierInvoiceRowResource::class, ['code', 'invoice_number'], ['created_at', 'invoice_date', 'due_date', 'total_amount', 'code'], ['status_id', 'supplier_id', 'order_id'],
            ['code', 'invoice_number', 'status', 'supplier', 'order', 'invoice_date', 'due_date', 'currency_code', 'total_amount']);
    }

    public function show(Request $request, string $id)
    {
        return ['data' => $this->detail($this->find($id), $request)];
    }

    public function store(Request $request)
    {
        $org = $this->ctx->organizationId();
        $d = $request->validate(['order_id' => ['required', Rule::exists('purchase_orders', 'id')->where('organization_id', $org)], 'invoice_number' => 'required|string|max:80', 'invoice_date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'due_date' => 'nullable|date_format:Y-m-d|after_or_equal:invoice_date', 'tax_amount' => 'nullable|numeric|min:0|max:999999999999',
            'lines' => 'required|array|min:1|max:100', 'lines.*.order_line_id' => 'required|string', 'lines.*.quantity' => 'required|numeric|gt:0|max:999999999', 'lines.*.unit_price' => 'required|numeric|min:0|max:999999999999']);
        $i = $this->svc->record(PurchaseOrder::findOrFail($d['order_id']), $d, $d['lines'], $request->user());
        return response(['data' => $this->detail($i, $request)], 201);
    }

    public function rematch(Request $request, string $id)
    {
        return ['data' => $this->detail($this->svc->rematch($this->find($id), $request->user()), $request)];
    }

    public function exception(Request $request, string $id)
    {
        $d = $request->validate(['accept' => 'required|boolean', 'note' => 'required|string|min:10|max:1000']);
        return ['data' => $this->detail($this->svc->decideException($this->find($id), (bool) $d['accept'], $d['note'], $request->user()), $request)];
    }

    public function reject(Request $request, string $id)
    {
        $d = $request->validate(['note' => 'required|string|min:3|max:1000']);
        return ['data' => $this->detail($this->svc->reject($this->find($id), $d['note'], $request->user()), $request)];
    }

    public function approve(Request $request, string $id)
    {
        return ['data' => $this->detail($this->svc->approve($this->find($id), $request->user()), $request)];
    }

    public function pay(Request $request, string $id)
    {
        $d = $request->validate(['payment_reference' => 'required|string|min:2|max:120', 'paid_on' => 'required|date_format:Y-m-d|before_or_equal:today']);
        return ['data' => $this->detail($this->svc->pay($this->find($id), $d['payment_reference'], $d['paid_on'], $request->user()), $request)];
    }

    public function cancel(Request $request, string $id)
    {
        $d = $request->validate(['note' => 'required|string|min:3|max:1000']);
        return ['data' => $this->detail($this->svc->cancel($this->find($id), $d['note'], $request->user()), $request)];
    }
}
