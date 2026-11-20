<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LookupType;
use App\Models\PayComponent;
use App\Models\PayEmployeeItem;
use App\Models\PayScheme;
use App\Models\PaySchemeAssignment;
use App\Models\PaySchemeVersion;
use App\Models\PayTaxBracket;
use App\Services\AuditLogger;
use App\Services\Hr\Payroll\BracketValidator;
use App\Services\Hr\Payroll\PayrollDryRun;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Payroll configuration (Guide 5.6): components, statutory schemes with effective-dated versions and bands, category assignments with approved exceptions,
 * recurring employee items, dry-run. NOTHING here is pre-filled. perm: hr.payroll.view (read) - hr.payroll.configure (prepare) - hr.payroll.verify (second person) - hr.payroll.prepare (dry-run).
 * Every value that changes money is entered unverified and counts only after a DIFFERENT person verifies it; a verified row is ended, never edited.
 */
class PayrollSetupController extends Controller
{
    private function org(): string { return app(OrganizationContext::class)->organizationId(); }
    private function lk(string $type, bool $required = true): array { return [$required ? 'required' : 'nullable', Rule::exists('lookup_values', 'id')->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id'))]; }
    private function fail(string $field, string $key, array $p = []): never { throw ValidationException::withMessages([$field => [__("payroll.err.$key", $p)]]); }
    private function unverified(): string { return Lookup::id('rule_verification_status', 'unverified'); }
    private function verifiedId(): string { return Lookup::id('rule_verification_status', 'verified'); }

    // ---- components ---------------------------------------------------------------------------------------------------------------
    private function comp(PayComponent $c): array
    {
        $c->loadMissing(['kind', 'source']);
        return ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'kind' => $c->kind->code, 'source' => $c->source->code, 'is_taxable' => $c->is_taxable, 'is_pensionable' => $c->is_pensionable,
            'ledger_account' => $c->ledger_account, 'sort_order' => $c->sort_order, 'is_active' => $c->is_active];
    }

    public function components() { return ['data' => PayComponent::with(['kind', 'source'])->orderBy('sort_order')->orderBy('code')->get()->map(fn ($c) => $this->comp($c))]; }

    public function storeComponent(Request $request)
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('pay_components', 'code')->where('organization_id', $this->org())], 'name' => 'required|string|max:150',
            'kind_id' => $this->lk('pay_component_kind'), 'source_id' => $this->lk('pay_component_source'), 'is_taxable' => 'required|boolean', 'is_pensionable' => 'required|boolean',
            'ledger_account' => 'nullable|string|max:40', 'sort_order' => 'nullable|integer|min:0|max:65000']);
        if (\App\Models\LookupValue::find($d['source_id'])?->code === 'basic_salary' && PayComponent::where('is_active', true)->whereHas('source', fn ($q) => $q->where('code', 'basic_salary'))->exists()) {
            $this->fail('source_id', 'one_basic_component');
        }
        $c = PayComponent::create($d + ['created_by' => $request->user()->id]);
        AuditLogger::log('payroll.component_created', $c, [], ['code' => $c->code, 'taxable' => $c->is_taxable, 'pensionable' => $c->is_pensionable]);
        return response()->json(['data' => $this->comp($c)], 201);
    }

    /** The tax flags, kind and source are fixed once created: changing them would rewrite how past payslips were built. Create a new component instead. */
    public function updateComponent(Request $request, string $id)
    {
        $c = PayComponent::findOrFail($id);
        $d = $request->validate(['name' => 'sometimes|required|string|max:150', 'ledger_account' => 'nullable|string|max:40', 'sort_order' => 'nullable|integer|min:0|max:65000', 'is_active' => 'sometimes|boolean']);
        $old = $c->only(array_keys($d));
        $c->update($d);
        AuditLogger::log('payroll.component_updated', $c, $old, $d);
        return ['data' => $this->comp($c->refresh())];
    }

    // ---- schemes, versions, bands -------------------------------------------------------------------------------------------------
    private function ver(PaySchemeVersion $v): array
    {
        $v->loadMissing(['verification', 'brackets', 'preparer', 'verifier']);
        return ['id' => $v->id, 'scheme_id' => $v->scheme_id, 'employee_rate' => $v->employee_rate === null ? null : (string) $v->employee_rate, 'employer_rate' => $v->employer_rate === null ? null : (string) $v->employer_rate,
            'base_cap' => $v->base_cap === null ? null : (string) $v->base_cap, 'valid_from' => $v->valid_from->toDateString(), 'valid_to' => $v->valid_to?->toDateString(), 'source_citation' => $v->source_citation,
            'verification' => $v->verification->code, 'prepared_by' => $v->preparer?->name, 'verified_by' => $v->verifier?->name, 'verified_at' => $v->verified_at?->toIso8601String(),
            'brackets' => $v->brackets->sortBy('lower')->map(fn ($b) => ['lower' => (string) $b->lower, 'upper' => $b->upper === null ? null : (string) $b->upper, 'rate' => (string) $b->rate])->values()];
    }

    private function scheme(PayScheme $s): array
    {
        $s->loadMissing(['statutoryType', 'mode', 'baseKind', 'rounding', 'versions.verification', 'versions.brackets', 'versions.preparer', 'versions.verifier']);
        return ['id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'statutory_type' => $s->statutoryType->code, 'mode' => $s->mode->code, 'calc_order' => $s->calc_order, 'base_kind' => $s->baseKind->code,
            'reduces_taxable_base' => $s->reduces_taxable_base, 'rounding' => $s->rounding->code, 'decimals' => $s->decimals, 'round_each_band' => $s->round_each_band, 'is_active' => $s->is_active,
            'versions' => $s->versions->sortByDesc('valid_from')->map(fn ($v) => $this->ver($v))->values()];
    }

    public function schemes() { return ['data' => PayScheme::with(['statutoryType', 'mode', 'baseKind', 'rounding'])->orderBy('calc_order')->get()->map(fn ($s) => $this->scheme($s))]; }

    public function storeScheme(Request $request)
    {
        $d = $request->validate(['code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('pay_schemes', 'code')->where('organization_id', $this->org())], 'name' => 'required|string|max:150',
            'statutory_type_id' => $this->lk('pay_statutory_type'), 'mode_id' => $this->lk('pay_scheme_mode'), 'calc_order' => 'required|integer|min:0|max:65000', 'base_kind_id' => $this->lk('pay_scheme_base'),
            'reduces_taxable_base' => 'required|boolean', 'rounding_id' => $this->lk('pay_rounding'), 'decimals' => 'required|integer|between:0,2', 'round_each_band' => 'boolean']);
        $s = PayScheme::create($d + ['created_by' => $request->user()->id, 'is_active' => true]);
        AuditLogger::log('payroll.scheme_created', $s, [], ['code' => $s->code]);
        return response()->json(['data' => $this->scheme($s)], 201);
    }

    /** Order, base, rounding and the other structural settings are fixed once created (they define how past payslips were built). Only the name and active flag change. */
    public function updateScheme(Request $request, string $id)
    {
        $s = PayScheme::findOrFail($id);
        $d = $request->validate(['name' => 'sometimes|required|string|max:150', 'is_active' => 'sometimes|boolean']);
        $old = $s->only(array_keys($d));
        $s->update($d);
        AuditLogger::log('payroll.scheme_updated', $s, $old, $d);
        return ['data' => $this->scheme($s->refresh())];
    }

    public function storeVersion(Request $request, string $schemeId)
    {
        $s = PayScheme::with('mode')->findOrFail($schemeId);
        $d = $request->validate(['employee_rate' => 'nullable|numeric|min:0|max:100', 'employer_rate' => 'nullable|numeric|min:0|max:100', 'base_cap' => 'nullable|numeric|min:0|max:9999999999999',
            'valid_from' => 'required|date_format:Y-m-d', 'valid_to' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from', 'source_citation' => 'required|string|max:500',
            'brackets' => 'nullable|array|max:30', 'brackets.*.lower' => 'required_with:brackets|numeric', 'brackets.*.upper' => 'nullable|numeric', 'brackets.*.rate' => 'required_with:brackets|numeric']);
        $isBands = $s->mode->code === 'brackets';
        if ($isBands) {
            if ($err = BracketValidator::error($d['brackets'] ?? [])) {
                $this->fail('brackets', "bracket_$err");
            }
            if (isset($d['employee_rate'])) {
                $this->fail('employee_rate', 'rate_in_bands');
            }
        } else {
            if (!empty($d['brackets'])) {
                $this->fail('brackets', 'bands_not_allowed');
            }
            if (!isset($d['employee_rate']) && !isset($d['employer_rate'])) {
                $this->fail('employee_rate', 'rates_required');
            }
        }
        $overlap = $s->versions()->whereDate('valid_from', '<=', $d['valid_to'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $d['valid_from']))->exists();
        if ($overlap) {
            $this->fail('valid_from', 'version_overlap');
        }
        $v = DB::transaction(function () use ($s, $d, $request, $isBands) {
            $v = PaySchemeVersion::create(['scheme_id' => $s->id, 'employee_rate' => $d['employee_rate'] ?? null, 'employer_rate' => $d['employer_rate'] ?? null, 'base_cap' => $d['base_cap'] ?? null,
                'valid_from' => $d['valid_from'], 'valid_to' => $d['valid_to'] ?? null, 'source_citation' => $d['source_citation'], 'verification_status_id' => $this->unverified(), 'prepared_by' => $request->user()->id]);
            if ($isBands) {
                foreach ($d['brackets'] as $b) {
                    PayTaxBracket::create(['version_id' => $v->id, 'lower' => $b['lower'], 'upper' => ($b['upper'] ?? '') === '' ? null : $b['upper'], 'rate' => $b['rate']]);
                }
            }
            return $v;
        });
        AuditLogger::log('payroll.version_created', $v, [], ['scheme' => $s->code, 'valid_from' => $d['valid_from'], 'employee_rate' => $d['employee_rate'] ?? null, 'employer_rate' => $d['employer_rate'] ?? null, 'bands' => count($d['brackets'] ?? [])]);
        return response()->json(['data' => $this->ver($v->refresh())], 201);
    }

    /** The second person: not the one who prepared it. Until then the value is shown as unverified and the calculation does not use it. */
    private function verify(Request $request, $row, string $audit)
    {
        if ($row->prepared_by === $request->user()->id) {
            $this->fail('id', 'verify_not_author');
        }
        if ($row->verification_status_id === $this->verifiedId()) {
            $this->fail('id', 'already_verified');
        }
        $row->update(['verification_status_id' => $this->verifiedId(), 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        AuditLogger::log($audit, $row, ['verification' => 'unverified'], ['verification' => 'verified']);
    }

    public function verifyVersion(Request $request, string $id) { $v = PaySchemeVersion::findOrFail($id); $this->verify($request, $v, 'payroll.version_verified'); return ['data' => $this->ver($v->refresh())]; }

    public function endVersion(Request $request, string $id)
    {
        $v = PaySchemeVersion::findOrFail($id);
        $d = $request->validate(['valid_to' => 'required|date_format:Y-m-d|after_or_equal:' . $v->valid_from->toDateString()]);
        $old = ['valid_to' => $v->valid_to?->toDateString()];
        $v->update($d);
        AuditLogger::log('payroll.version_ended', $v, $old, $d);
        return ['data' => $this->ver($v->refresh())];
    }

    /** A mistake in something nobody verified yet can be removed; a verified version never can. */
    public function destroyVersion(string $id)
    {
        $v = PaySchemeVersion::findOrFail($id);
        if ($v->verification_status_id === $this->verifiedId()) {
            $this->fail('id', 'verified_is_final');
        }
        AuditLogger::log('payroll.version_deleted', $v, ['valid_from' => $v->valid_from->toDateString()]);
        $v->delete();
        return response()->noContent();
    }

    // ---- assignments ---------------------------------------------------------------------------------------------------------------------
    private function assign(PaySchemeAssignment $a): array
    {
        $a->loadMissing(['scheme', 'category', 'employee', 'verification', 'preparer']);
        return ['id' => $a->id, 'scheme_id' => $a->scheme_id, 'scheme' => $a->scheme->code, 'category' => $a->category?->code, 'employee_id' => $a->employee_id, 'employee' => $a->employee?->full_name, 'applies' => $a->applies,
            'valid_from' => $a->valid_from->toDateString(), 'valid_to' => $a->valid_to?->toDateString(), 'reason' => $a->reason, 'verification' => $a->verification->code, 'prepared_by' => $a->preparer?->name];
    }

    public function assignments() { return ['data' => PaySchemeAssignment::with(['scheme', 'category', 'employee', 'verification', 'preparer'])->orderByDesc('valid_from')->get()->map(fn ($a) => $this->assign($a))]; }

    public function storeAssignment(Request $request)
    {
        $d = $request->validate(['scheme_id' => ['required', Rule::exists('pay_schemes', 'id')->where('organization_id', $this->org())], 'category_id' => $this->lk('employee_category', false),
            'employee_id' => ['nullable', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'applies' => 'required|boolean',
            'valid_from' => 'required|date_format:Y-m-d', 'valid_to' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from', 'reason' => 'nullable|string|max:500']);
        if (!empty($d['category_id']) === !empty($d['employee_id'])) {
            $this->fail('category_id', 'assignment_target');
        }
        if (!empty($d['employee_id']) && trim((string) ($d['reason'] ?? '')) === '') {
            $this->fail('reason', 'exception_reason');
        }
        $same = PaySchemeAssignment::where('scheme_id', $d['scheme_id'])->when(!empty($d['employee_id']), fn ($q) => $q->where('employee_id', $d['employee_id']), fn ($q) => $q->where('category_id', $d['category_id']))
            ->whereDate('valid_from', '<=', $d['valid_to'] ?? '9999-12-31')->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $d['valid_from']))->exists();
        if ($same) {
            $this->fail('valid_from', 'assignment_overlap');
        }
        $a = PaySchemeAssignment::create($d + ['verification_status_id' => $this->unverified(), 'prepared_by' => $request->user()->id]);
        AuditLogger::log('payroll.assignment_created', $a, [], ['applies' => $a->applies, 'valid_from' => $d['valid_from']]);
        return response()->json(['data' => $this->assign($a)], 201);
    }

    public function verifyAssignment(Request $request, string $id) { $a = PaySchemeAssignment::findOrFail($id); $this->verify($request, $a, 'payroll.assignment_verified'); return ['data' => $this->assign($a->refresh())]; }

    public function endAssignment(Request $request, string $id)
    {
        $a = PaySchemeAssignment::findOrFail($id);
        $d = $request->validate(['valid_to' => 'required|date_format:Y-m-d|after_or_equal:' . $a->valid_from->toDateString()]);
        $a->update($d);
        AuditLogger::log('payroll.assignment_ended', $a, [], $d);
        return ['data' => $this->assign($a->refresh())];
    }

    public function destroyAssignment(string $id)
    {
        $a = PaySchemeAssignment::findOrFail($id);
        if ($a->verification_status_id === $this->verifiedId()) {
            $this->fail('id', 'verified_is_final');
        }
        AuditLogger::log('payroll.assignment_deleted', $a);
        $a->delete();
        return response()->noContent();
    }

    // ---- recurring employee items ---------------------------------------------------------------------------------------------------------
    private function item(PayEmployeeItem $i): array
    {
        $i->loadMissing(['component.kind', 'employee', 'verification', 'preparer']);
        return ['id' => $i->id, 'employee_id' => $i->employee_id, 'employee' => $i->employee?->full_name, 'component' => $i->component->code, 'kind' => $i->component->kind->code, 'amount' => (string) $i->amount,
            'valid_from' => $i->valid_from->toDateString(), 'valid_to' => $i->valid_to?->toDateString(), 'reason' => $i->reason, 'verification' => $i->verification->code, 'prepared_by' => $i->preparer?->name];
    }

    public function items(Request $request)
    {
        $q = PayEmployeeItem::with(['component.kind', 'employee', 'verification', 'preparer'])->whereIn('employee_id', Employee::query()->visibleTo($request->user())->select('hr_employees.id'))->orderByDesc('valid_from')->limit(500);
        if ($e = $request->query('employee_id')) {
            $q->where('employee_id', $e);
        }
        AuditLogger::log('payroll.items_viewed', null, [], ['employee_id' => $request->query('employee_id')]);          // recurring pay is a sensitive read
        return ['data' => $q->get()->map(fn ($i) => $this->item($i))];
    }

    public function storeItem(Request $request)
    {
        $d = $request->validate(['employee_id' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'component_id' => ['required', Rule::exists('pay_components', 'id')->where('organization_id', $this->org())],
            'amount' => 'required|numeric|min:0|max:9999999999999', 'valid_from' => 'required|date_format:Y-m-d', 'valid_to' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from', 'reason' => 'nullable|string|max:500']);
        Employee::query()->visibleTo($request->user())->findOrFail($d['employee_id']);
        $c = PayComponent::with('source')->findOrFail($d['component_id']);
        if (!$c->is_active || $c->source->code !== 'recurring') {
            $this->fail('component_id', 'component_not_recurring');
        }
        $i = PayEmployeeItem::create($d + ['verification_status_id' => $this->unverified(), 'prepared_by' => $request->user()->id]);
        AuditLogger::log('payroll.item_created', $i, [], ['employee_id' => $d['employee_id'], 'component' => $c->code, 'valid_from' => $d['valid_from']]);   // the amount stays out of the audit trail
        return response()->json(['data' => $this->item($i)], 201);
    }

    public function verifyItem(Request $request, string $id) { $i = PayEmployeeItem::findOrFail($id); $this->verify($request, $i, 'payroll.item_verified'); return ['data' => $this->item($i->refresh())]; }

    public function endItem(Request $request, string $id)
    {
        $i = PayEmployeeItem::findOrFail($id);
        $d = $request->validate(['valid_to' => 'required|date_format:Y-m-d|after_or_equal:' . $i->valid_from->toDateString()]);
        $i->update($d);
        AuditLogger::log('payroll.item_ended', $i, [], $d);
        return ['data' => $this->item($i->refresh())];
    }

    public function destroyItem(string $id)
    {
        $i = PayEmployeeItem::findOrFail($id);
        if ($i->verification_status_id === $this->verifiedId()) {
            $this->fail('id', 'verified_is_final');
        }
        AuditLogger::log('payroll.item_deleted', $i);
        $i->delete();
        return response()->noContent();
    }

    // ---- dry run and status ---------------------------------------------------------------------------------------------------------------
    /** Full calculation for one employee and period. Writes nothing to a payroll table; the read of salary data is audited (without amounts). */
    public function dryRun(Request $request, PayrollDryRun $svc)
    {
        $d = $request->validate(['employee_id' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'period_from' => 'required|date_format:Y-m-d',
            'period_to' => 'required|date_format:Y-m-d|after_or_equal:period_from', 'lines' => 'nullable|array|max:50', 'lines.*.component_id' => 'required_with:lines|string', 'lines.*.amount' => 'required_with:lines|numeric|min:0']);
        $e = Employee::query()->visibleTo($request->user())->findOrFail($d['employee_id']);
        $r = $svc->run($e, $d['period_from'], $d['period_to'], $d['lines'] ?? []);
        AuditLogger::log('payroll.dry_run', $e, [], ['period_from' => $d['period_from'], 'period_to' => $d['period_to']]);
        $msg = fn (array $items) => array_map(fn ($i) => ['code' => $i['code'], 'message' => __('payroll.check.' . $i['code'], $i['params']), 'params' => $i['params']], $items);
        return ['data' => ['blockers' => $msg($r['blockers']), 'warnings' => $msg($r['warnings']), 'result' => $r['result'], 'meta' => $r['meta']]];
    }

    /** Go-live view: what is still unverified or missing. */
    public function status()
    {
        $un = $this->unverified();
        $today = now()->toDateString();
        $noVersion = PayScheme::where('is_active', true)->whereDoesntHave('versions', fn ($q) => $q->where('verification_status_id', $this->verifiedId())->whereDate('valid_from', '<=', $today)->where(fn ($w) => $w->whereNull('valid_to')->orWhereDate('valid_to', '>=', $today)))->pluck('code');
        $unverified = PaySchemeVersion::where('verification_status_id', $un)->count() + PaySchemeAssignment::where('verification_status_id', $un)->count() + PayEmployeeItem::where('verification_status_id', $un)->count();
        $basic = PayComponent::where('is_active', true)->whereHas('source', fn ($q) => $q->where('code', 'basic_salary'))->count();
        return ['data' => ['components' => PayComponent::where('is_active', true)->count(), 'has_single_basic_component' => $basic === 1, 'schemes' => PayScheme::where('is_active', true)->count(), 'schemes_without_verified_version' => $noVersion,
            'unverified_total' => $unverified, 'ready' => $basic === 1 && $unverified === 0 && $noVersion->isEmpty() && PayScheme::where('is_active', true)->count() > 0]];
    }
}
