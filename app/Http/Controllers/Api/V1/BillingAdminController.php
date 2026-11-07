<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\TaxRate;
use App\Services\AuditLogger;
use App\Services\Billing\ProjectLifecycle;
use App\Services\OrganizationContext;
use App\Services\Projects\ProjectAccess;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Tax rates (effective-dated, signed off), project billing set-up, and the Delivered / Closed gates. */
class BillingAdminController extends Controller
{
    public function __construct(private ProjectLifecycle $lifecycle, private ProjectAccess $access, private OrganizationContext $ctx) {}

    // ---- tax rates -------------------------------------------------------------------------------------------------------
    public function taxRates()
    {
        return ['data' => TaxRate::with('verification')->orderByDesc('valid_from')->get()->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'rate_percent' => $t->rate_percent,
            'valid_from' => $t->valid_from->toDateString(), 'valid_to' => $t->valid_to?->toDateString(), 'is_default' => $t->is_default, 'source_citation' => $t->source_citation, 'verification' => $t->verification->code])];
    }

    public function storeTaxRate(Request $request)
    {
        $d = $request->validate(['name' => 'required|string|max:100', 'rate_percent' => 'required|numeric|min:0|max:100', 'valid_from' => 'required|date', 'valid_to' => 'nullable|date|after_or_equal:valid_from',
            'is_default' => 'boolean', 'source_citation' => 'required|string|max:255']);
        $t = TaxRate::create($d + ['verification_status_id' => Lookup::id('rule_verification_status', 'unverified')]);
        if ($t->is_default) TaxRate::where('id', '!=', $t->id)->update(['is_default' => false]);
        AuditLogger::log('tax_rate.created', $t, [], $d);
        return response()->json(['id' => $t->id], 201);
    }

    /** Sign-off by someone with rules.signoff; an unverified rate can never be used to issue an invoice. */
    public function verifyTaxRate(Request $request, string $id)
    {
        $t = TaxRate::findOrFail($id);
        $t->update(['verification_status_id' => Lookup::id('rule_verification_status', 'verified'), 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        AuditLogger::log('tax_rate.verified', $t, ['verification' => 'unverified'], ['verification' => 'verified']);
        return response()->noContent();
    }

    // ---- project billing set-up ------------------------------------------------------------------------------------------------
    public function billing(Request $request, string $id)
    {
        [$p] = $this->access->find($request->user(), $id);
        $p->load('milestones');
        return ['data' => [
            'billing_model_id' => $p->billing_model_id, 'billing_model' => $p->billing_model_id ? \App\Models\LookupValue::find($p->billing_model_id)?->code : null,
            'currency_code' => $p->currency_code, 'budget_amount' => $p->budget_amount, 'rate_card_id' => $p->rate_card_id, 'client_id' => $p->client_id,
            'milestones' => $p->milestones->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'completed' => $m->completed_at !== null, 'billing_amount' => $m->billing_amount]),
        ]];
    }

    public function setBilling(Request $request, string $id)
    {
        $p = Project::findOrFail($id);
        $d = $request->validate(['billing_model_id' => ['required', Rule::exists('lookup_values', 'id')], 'currency_code' => 'nullable|string|size:3', 'budget_amount' => 'nullable|numeric|min:0']);
        $p->update(['billing_model_id' => $d['billing_model_id'], 'currency_code' => isset($d['currency_code']) ? strtoupper($d['currency_code']) : $p->currency_code,
            'budget_amount' => $d['budget_amount'] ?? $p->budget_amount]);
        AuditLogger::log('project.billing_set', $p, [], $d);
        return response()->noContent();
    }

    public function setMilestoneAmount(Request $request, string $id, string $milestoneId)
    {
        $d = $request->validate(['billing_amount' => 'nullable|numeric|min:0|max:9999999999999']);
        $m = Milestone::where('project_id', Project::findOrFail($id)->id)->findOrFail($milestoneId);
        $m->update(['billing_amount' => $d['billing_amount'] ?? null]);
        AuditLogger::log('milestone.billing_set', $m, [], $d);
        return response()->noContent();
    }

    // ---- lifecycle ------------------------------------------------------------------------------------------------------------
    public function deliver(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $r = $this->lifecycle->deliver($p, $request->user());
        return ['data' => ['status' => 'delivered', 'invoice_id' => $r['invoice']?->id, 'invoice_error' => $r['invoice_error']]];
    }

    public function close(Request $request, string $id)
    {
        [$p, $level] = $this->access->find($request->user(), $id);
        abort_unless(ProjectAccess::isManager($level), 403, 'forbidden');
        $d = $request->validate(['exception_reason' => 'nullable|string|min:5|max:1000']);
        abort_if(!empty($d['exception_reason']) && !$this->ctx->can('billing.close_exception'), 403, 'forbidden');
        $this->lifecycle->close($p, $d['exception_reason'] ?? null);
        return ['data' => ['status' => 'closed']];
    }
}
