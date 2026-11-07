<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\InvoiceRowResource;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Project;
use App\Services\Billing\CreditNoteService;
use App\Services\Billing\InvoicePdf;
use App\Services\Billing\InvoiceService;
use App\Services\Billing\PaymentService;
use App\Services\Billing\ProjectLifecycle;
use App\Services\OrganizationContext;
use App\Services\Projects\ProjectAccess;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Invoice visibility: billing.view_all sees every invoice; otherwise only invoices of projects the user may see (department scope applies).
 */
class InvoiceController extends Controller
{
    use AppliesListQuery;

    public function __construct(private InvoiceService $invoices, private PaymentService $payments, private CreditNoteService $notes, private ProjectLifecycle $lifecycle,
        private ProjectAccess $access, private OrganizationContext $ctx) {}

    private function visible(Request $r)
    {
        $q = Invoice::query();
        if (!$this->ctx->can('billing.view_all')) {
            $viewAll = $this->ctx->can('project.view_all');
            $q->whereHas('project', fn ($p) => $p->visibleTo($r->user(), $viewAll));
        }
        return $q;
    }

    private function find(Request $r, string $id): Invoice { return $this->visible($r)->with(['status', 'project:id,code,name', 'client:id,name'])->findOrFail($id); }

    public function index(Request $request)
    {
        return $this->respondList($request, $this->visible($request)->with(['status', 'project:id,code', 'client:id,name']), InvoiceRowResource::class,
            ['number'], ['created_at', 'number', 'due_date', 'total'], ['status_id', 'project_id', 'client_id'], ['number', 'project', 'client', 'status', 'issue_date', 'due_date', 'currency_code', 'total', 'balance']);
    }

    public function show(Request $request, string $id)
    {
        $i = $this->find($request, $id);
        $i->load(['lines.kind', 'payments.method', 'creditNotes.status', 'taxRate.verification']);
        $s = $i->status->code;
        return ['data' => [
            'id' => $i->id, 'number' => $i->number, 'status' => $s, 'project_id' => $i->project_id, 'project' => $i->project?->name, 'project_code' => $i->project?->code,
            'client' => $i->client_name ?? $i->client?->name, 'currency_code' => $i->currency_code, 'issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(),
            'payment_terms_days' => $i->payment_terms_days, 'notes' => $i->notes, 'rounding_minutes' => $i->rounding_minutes, 'rounding_mode' => $i->rounding_mode,
            'tax_rate_id' => $i->tax_rate_id, 'tax_name' => $i->tax_name, 'tax_percent' => $i->tax_percent, 'tax_exempt' => $i->tax_exempt, 'tax_verified' => $i->taxRate?->verification?->code === 'verified',
            'subtotal' => $i->subtotal, 'tax_total' => $i->tax_total, 'total' => $i->total, 'paid_total' => $i->paid_total, 'credited_total' => $i->credited_total, 'balance' => $i->balance,
            'approval_request_id' => $i->approval_request_id,
            'lines' => $i->lines->map(fn ($l) => ['id' => $l->id, 'line_no' => $l->line_no, 'kind' => $l->kind->code, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit,
                'unit_price' => $l->unit_price, 'amount' => $l->amount, 'raw_seconds' => $l->raw_seconds, 'rounded_minutes' => $l->rounded_minutes]),
            'payments' => $i->payments->map(fn ($p) => ['id' => $p->id, 'amount' => $p->amount, 'paid_on' => $p->paid_on->toDateString(), 'method' => $p->method?->code, 'reference' => $p->reference, 'notes' => $p->notes, 'reversal_of_id' => $p->reversal_of_id]),
            'credit_notes' => $i->creditNotes->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'status' => $c->status->code, 'amount' => $c->amount, 'tax_total' => $c->tax_total, 'total' => $c->total, 'reason' => $c->reason]),
            'can' => [
                'edit' => $s === 'draft' && $this->ctx->can('billing.manage'), 'issue' => $s === 'draft' && $this->ctx->can('billing.issue'),
                'pay' => in_array($s, ['issued', 'partially_paid', 'overdue'], true) && $this->ctx->can('billing.payments'),
                'credit' => in_array($s, ['issued', 'partially_paid', 'overdue', 'paid'], true) && $this->ctx->can('billing.credit'),
            ],
        ]];
    }

    // ---- drafts ------------------------------------------------------------------------------------------------------------
    public function generate(Request $request, string $projectId)
    {
        [$p] = $this->access->find($request->user(), $projectId);
        return response()->json(['data' => ['id' => $this->invoices->generateDraft($p, $request->user())->id]], 201);
    }

    public function update(Request $request, string $id)
    {
        $i = $this->find($request, $id);
        $d = $request->validate(['notes' => 'nullable|string|max:3000', 'payment_terms_days' => 'sometimes|integer|min:0|max:365', 'tax_exempt' => 'sometimes|boolean',
            'tax_rate_id' => ['nullable', Rule::exists('tax_rates', 'id')->where('organization_id', $this->ctx->organizationId())]]);
        $this->invoices->updateDraft($i, $d);
        return $this->show($request, $id);
    }

    public function addLine(Request $request, string $id)
    {
        $i = $this->find($request, $id);
        $d = $request->validate(['description' => 'required|string|max:500', 'quantity' => 'required|numeric|min:0.0001|max:99999999', 'unit_price' => 'required|numeric|min:0|max:9999999999999']);
        $this->invoices->addManualLine($i, $d['description'], (string) $d['quantity'], number_format((float) $d['unit_price'], 2, '.', ''));
        return $this->show($request, $id);
    }

    public function removeLine(Request $request, string $id, string $lineId)
    {
        $this->invoices->removeLine($this->find($request, $id), $lineId);
        return $this->show($request, $id);
    }

    public function cancel(Request $request, string $id)
    {
        $this->invoices->cancelDraft($this->find($request, $id));
        return response()->noContent();
    }

    public function issue(Request $request, string $id)
    {
        $this->invoices->requestIssue($this->find($request, $id), $request->user());
        return $this->show($request, $id);
    }

    public function pdf(Request $request, string $id)
    {
        $i = $this->find($request, $id);
        $name = ($i->number ?? 'DRAFT-' . $i->project?->code) . '.pdf';
        return response((new InvoicePdf())->render($i), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => "attachment; filename=\"$name\"", 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
    }

    // ---- payments and credit notes -------------------------------------------------------------------------------------------
    public function pay(Request $request, string $id)
    {
        $i = $this->find($request, $id);
        $d = $request->validate(['amount' => 'required|numeric|gt:0|max:9999999999999', 'paid_on' => 'required|date|before_or_equal:today', 'method_id' => ['required', Rule::exists('lookup_values', 'id')],
            'reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:1000']);
        $this->payments->record($i, number_format((float) $d['amount'], 2, '.', ''), $d['paid_on'], $d['method_id'], $d['reference'] ?? null, $d['notes'] ?? null, $request->user());
        return $this->show($request, $id);
    }

    public function reversePayment(Request $request, string $id, string $paymentId)
    {
        $i = $this->find($request, $id);
        $d = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $this->payments->reverse(InvoicePayment::where('invoice_id', $i->id)->findOrFail($paymentId), $d['reason'], $request->user());
        return $this->show($request, $id);
    }

    public function credit(Request $request, string $id)
    {
        $i = $this->find($request, $id);
        $d = $request->validate(['amount' => 'required|numeric|gt:0|max:9999999999999', 'reason' => 'required|string|min:5|max:2000']);
        $this->notes->request($i, number_format((float) $d['amount'], 2, '.', ''), $d['reason'], $request->user());
        return $this->show($request, $id);
    }
}
