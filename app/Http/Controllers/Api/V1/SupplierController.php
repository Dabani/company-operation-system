<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\SupplierRowResource;
use App\Models\LookupType;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Services\Procurement\ProcurementAccess;
use App\Services\Procurement\ProcurementRules;
use App\Services\Procurement\SupplierService;
use App\Support\AppliesListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Supplier master (Guide 7.6). Buyers manage it; everyone else sees approved suppliers by name and category only. Bank details are restricted. */
class SupplierController extends Controller
{
    use AppliesListQuery;

    public function __construct(private SupplierService $svc, private ProcurementAccess $access, private OrganizationContext $ctx) {}

    private function rules(bool $create): array
    {
        $req = $create ? 'required' : 'sometimes';
        return [
            'name' => [$req, 'string', 'max:190'], 'legal_name' => 'nullable|string|max:190', 'tax_number' => 'nullable|string|max:60', 'registration_number' => 'nullable|string|max:60',
            'country_code' => ['nullable', 'string', 'size:2', 'alpha'], 'email' => 'nullable|email|max:190', 'phone' => 'nullable|string|max:40', 'contact_name' => 'nullable|string|max:150', 'address' => 'nullable|string|max:500',
            'category_id' => ['nullable', Rule::exists('lookup_values', 'id')->where(fn ($q) => $q->whereIn('lookup_type_id', LookupType::where('code', 'supplier_category')->pluck('id')))],
        ];
    }

    private function clean(array $d): array
    {
        if (isset($d['country_code'])) {
            $d['country_code'] = strtoupper($d['country_code']);
        }
        return $d;
    }

    private function bankRow(SupplierBankAccount $b, bool $full): array
    {
        $b->loadMissing(['status', 'proposer:id,name', 'verifier:id,name']);
        $n = $b->account_number;
        return ['id' => $b->id, 'bank_name' => $b->bank_name, 'account_name' => $b->account_name, 'account_number' => $full ? $n : ProcurementRules::maskAccount($n), 'swift_code' => $b->swift_code, 'currency_code' => $b->currency_code,
            'status' => $b->status->code, 'proposed_by' => $b->proposer?->name, 'verified_by' => $b->verifier?->name, 'verified_at' => $b->verified_at?->toIso8601String(), 'verification_note' => $b->verification_note,
            'can_verify' => $b->status->code === 'pending_verification' && $this->access->can('procurement.supplier.bank') && ProcurementRules::canVerifyBank((string) $b->proposed_by, (string) request()->user()?->id)];
    }

    private function detail(Supplier $s): array
    {
        $s->loadMissing(['status', 'category', 'history.to', 'history.changedBy']);
        $buyer = $this->access->buyer();
        $code = $s->status->code;
        $base = ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'country_code' => $s->country_code, 'category' => $s->category?->code, 'category_id' => $s->category_id, 'status' => $code];
        if (!$buyer) {
            return $base + ['me' => ['can_edit' => false, 'can_submit' => false, 'moves' => [], 'can_propose_bank' => false]];
        }
        $bankView = $this->access->can('procurement.supplier.bank');
        return $base + [
            'legal_name' => $s->legal_name, 'tax_number' => $s->tax_number, 'registration_number' => $s->registration_number, 'email' => $s->email, 'phone' => $s->phone, 'contact_name' => $s->contact_name, 'address' => $s->address,
            'status_reason' => $s->status_reason, 'approved_at' => $s->approved_at?->toIso8601String(), 'approval_request_id' => $s->approval_request_id,
            'history' => $s->history->map(fn ($h) => ['status' => $h->to->code, 'by' => $h->changedBy?->name, 'note' => $h->note, 'at' => $h->created_at->toIso8601String()])->values(),
            'bank_accounts' => $s->bankAccounts()->with(['status', 'proposer:id,name', 'verifier:id,name'])->get()->map(fn ($b) => $this->bankRow($b, $bankView))->values(),
            'me' => ['can_edit' => ProcurementRules::supplierEditable($code), 'can_submit' => in_array($code, ['draft', 'blocked'], true),
                'moves' => array_values(array_filter(ProcurementRules::SUPPLIER_MOVES[$code] ?? [], fn ($to) => ProcurementRules::supplierManual($code, $to))), 'can_propose_bank' => $code !== 'archived', 'can_verify_bank' => $bankView],
        ];
    }

    public function index(Request $request)
    {
        $q = $this->access->scopeSuppliers(Supplier::query())->with(['status', 'category']);
        return $this->respondList($request, $q, SupplierRowResource::class, ['code', 'name', 'legal_name', 'tax_number'], ['name', 'code', 'created_at'], ['status_id', 'category_id', 'country_code'], ['code', 'name', 'country_code', 'category', 'status', 'email', 'phone', 'contact_name']);
    }

    public function show(Request $request, string $id)
    {
        return ['data' => $this->detail($this->access->supplier($id))];
    }

    public function store(Request $request)
    {
        $s = $this->svc->create($this->clean($request->validate($this->rules(true))), $request->user());
        return response(['data' => $this->detail($s)], 201);
    }

    public function update(Request $request, string $id)
    {
        $s = $this->svc->update($this->access->supplier($id), $this->clean($request->validate($this->rules(false))), $request->user());
        return ['data' => $this->detail($s)];
    }

    public function submit(Request $request, string $id)
    {
        return ['data' => $this->detail($this->svc->submit($this->access->supplier($id), $request->user()))];
    }

    public function status(Request $request, string $id)
    {
        $d = $request->validate(['to' => 'required|string|in:suspended,blocked,approved,archived', 'note' => 'nullable|string|max:1000']);
        return ['data' => $this->detail($this->svc->move($this->access->supplier($id), $d['to'], $d['note'] ?? null, $request->user()))];
    }

    // ---- bank details ---------------------------------------------------------------------------------------------------------
    public function proposeBank(Request $request, string $id)
    {
        $d = $request->validate(['bank_name' => 'required|string|max:150', 'account_name' => 'required|string|max:190', 'account_number' => 'required|string|min:4|max:60', 'swift_code' => 'nullable|string|max:20', 'currency_code' => 'nullable|string|size:3|alpha']);
        if (isset($d['currency_code'])) {
            $d['currency_code'] = strtoupper($d['currency_code']);
        }
        $this->svc->proposeBank($this->access->supplier($id), $d, $request->user());
        return response(['data' => $this->detail($this->access->supplier($id))], 201);
    }

    public function verifyBank(Request $request, string $id)
    {
        $d = $request->validate(['accept' => 'required|boolean', 'note' => 'nullable|string|max:1000']);
        $b = SupplierBankAccount::findOrFail($id);
        $this->svc->verifyBank($b, (bool) $d['accept'], $d['note'] ?? null, $request->user());
        return ['data' => $this->detail($this->access->supplier($b->supplier_id))];
    }

    /** Showing an account number in full is itself audited. */
    public function revealBank(Request $request, string $id)
    {
        $b = SupplierBankAccount::with('supplier')->findOrFail($id);
        AuditLogger::log('supplier.bank_revealed', $b->supplier, [], ['account' => ProcurementRules::maskAccount($b->account_number)]);
        return ['data' => ['account_number' => $b->account_number]];
    }
}
