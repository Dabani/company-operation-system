<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RequisitionRowResource;
use App\Models\LookupType;
use App\Models\PurchaseRequisition;
use App\Services\OrganizationContext;
use App\Services\Procurement\ProcurementAccess;
use App\Services\Procurement\ProcurementRules;
use App\Services\Procurement\RequisitionService;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Purchase requisitions: a requester raises and follows their own; buyers see all. Approval is in the approvals inbox. */
class PurchaseRequisitionController extends Controller
{
    use AppliesListQuery;

    public function __construct(private RequisitionService $svc, private ProcurementAccess $access, private OrganizationContext $ctx) {}

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        $org = $this->ctx->organizationId();
        $scoped = fn (string $table) => Rule::exists($table, 'id')->where('organization_id', $org);
        return [
            'title' => [$req, 'string', 'max:200'], 'purpose' => 'nullable|string|max:20000',
            'procurement_type_id' => [$req, Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'procurement_type')->pluck('id')))],
            'department_id' => [$req, $scoped('departments')], 'project_id' => ['nullable', $scoped('projects')],
            'preferred_supplier_id' => ['nullable', $scoped('suppliers')], 'preferred_supplier_reason' => 'nullable|string|max:1000',
            'needed_by' => [$req, 'date_format:Y-m-d'], 'delivery_location' => [$req, 'string', 'max:300'], 'currency_code' => [$req, 'string', 'size:3', 'alpha'],
            'exception_reason' => 'nullable|string|max:1000',
            'lines' => [$create ? 'required' : 'sometimes', 'array', 'min:1', 'max:100'],
            'lines.*.description' => 'required|string|max:500', 'lines.*.quantity' => 'required|numeric|gt:0|max:999999999', 'lines.*.unit' => 'nullable|string|max:30', 'lines.*.unit_price' => 'required|numeric|min:0|max:999999999999',
        ];
    }

    private function split(array $d): array
    {
        $lines = $d['lines'] ?? null;
        unset($d['lines']);
        if (isset($d['currency_code'])) {
            $d['currency_code'] = strtoupper($d['currency_code']);
        }
        return [$d, $lines];
    }

    private function detail(PurchaseRequisition $r, \Illuminate\Http\Request $request): array
    {
        $r->loadMissing(['type', 'status', 'department', 'project', 'requester:id,name', 'preferredSupplier:id,code,name', 'lines', 'history.to', 'history.changedBy']);
        $code = $r->status->code;
        $buyer = $this->access->buyer();
        $mine = $r->requester_id === $request->user()->id;
        return [
            'id' => $r->id, 'code' => $r->code, 'title' => $r->title, 'purpose' => $r->purpose, 'type' => $r->type->code, 'procurement_type_id' => $r->procurement_type_id, 'status' => $code,
            'department' => $r->department?->name, 'department_id' => $r->department_id, 'project' => $r->project ? ['id' => $r->project->id, 'code' => $r->project->code, 'name' => $r->project->name] : null,
            'requester' => ['id' => $r->requester_id, 'name' => $r->requester?->name], 'preferred_supplier' => $r->preferredSupplier ? ['id' => $r->preferredSupplier->id, 'code' => $r->preferredSupplier->code, 'name' => $r->preferredSupplier->name] : null,
            'preferred_supplier_reason' => $r->preferred_supplier_reason, 'needed_by' => $r->needed_by->toDateString(), 'delivery_location' => $r->delivery_location, 'currency_code' => $r->currency_code, 'total_amount' => $r->total_amount,
            'over_budget' => $r->over_budget, 'exception_reason' => $r->exception_reason, 'split_suspected' => $buyer ? $r->split_suspected : false, 'approval_request_id' => $r->approval_request_id, 'decision_note' => $r->decision_note,
            'lines' => $r->lines->map(fn ($l) => ['line_no' => $l->line_no, 'description' => $l->description, 'quantity' => $l->quantity, 'unit' => $l->unit, 'unit_price' => $l->unit_price, 'line_total' => $l->line_total])->values(),
            'history' => $r->history->map(fn ($h) => ['status' => $h->to->code, 'by' => $h->changedBy?->name, 'note' => $h->note, 'at' => $h->created_at->toIso8601String()])->values(),
            'me' => ['can_edit' => $mine && ProcurementRules::requisitionEditable($code), 'can_submit' => $mine && $code === 'draft', 'can_cancel' => ($mine || $buyer) && ProcurementRules::requisitionAllowed($code, 'cancelled'),
                'can_reopen' => $mine && $code === 'rejected', 'can_delete' => $mine && $code === 'draft' && !$r->approval_request_id],
        ];
    }

    private function mine(Request $request, string $id): PurchaseRequisition
    {
        $r = $this->access->requisition($request->user(), $id);
        abort_unless($r->requester_id === $request->user()->id, 403, 'forbidden');
        return $r;
    }

    public function index(Request $request)
    {
        $q = $this->access->scopeRequisitions(PurchaseRequisition::query(), $request->user())->with(['type', 'status', 'department', 'requester:id,name']);
        if ($request->boolean('mine')) {
            $q->where('requester_id', $request->user()->id);
        }
        return $this->respondList($request, $q, RequisitionRowResource::class, ['code', 'title'], ['created_at', 'needed_by', 'total_amount', 'code'], ['status_id', 'procurement_type_id', 'department_id'], ['code', 'title', 'type', 'status', 'department', 'requester', 'needed_by', 'currency_code', 'total_amount', 'over_budget']);
    }

    public function show(Request $request, string $id)
    {
        return ['data' => $this->detail($this->access->requisition($request->user(), $id), $request)];
    }

    public function store(Request $request)
    {
        [$d, $lines] = $this->split($request->validate($this->rules(true)));
        return response(['data' => $this->detail($this->svc->create($d, $lines, $request->user()), $request)], 201);
    }

    public function update(Request $request, string $id)
    {
        [$d, $lines] = $this->split($request->validate($this->rules(false)));
        return ['data' => $this->detail($this->svc->update($this->mine($request, $id), $d, $lines, $request->user()), $request)];
    }

    public function destroy(Request $request, string $id)
    {
        $this->svc->delete($this->mine($request, $id));
        return response()->noContent();
    }

    public function submit(Request $request, string $id)
    {
        return ['data' => $this->detail($this->svc->submit($this->mine($request, $id), $request->user()), $request)];
    }

    public function status(Request $request, string $id)
    {
        $d = $request->validate(['to' => 'required|string|in:cancelled,draft', 'note' => 'nullable|string|max:1000']);
        $r = $this->access->requisition($request->user(), $id);
        abort_unless($r->requester_id === $request->user()->id || ($d['to'] === 'cancelled' && $this->access->buyer()), 403, 'forbidden');
        return ['data' => $this->detail($this->svc->move($r, $d['to'], $d['note'] ?? null, $request->user()), $request)];
    }
}
