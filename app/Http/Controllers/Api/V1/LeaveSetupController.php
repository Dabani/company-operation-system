<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeavePeriod;
use App\Models\LeaveProfile;
use App\Models\LeaveTypeSetting;
use App\Models\LegalLeaveRule;
use App\Models\LegalParameter;
use App\Models\LookupType;
use App\Models\RuleVersion;
use App\Services\AuditLogger;
use App\Services\CodeGenerator;
use App\Services\Hr\Leave\LeavePolicy;
use App\Services\Hr\Leave\LeavePolicyResolver;
use App\Services\Hr\Leave\LegalLeaveRules;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Leave setup: types, recommended/blackout periods, leave profiles, statutory (legal) rules, policy preview and audit.
 * perm: hr.leave.manage (types, periods) - hr.leave.profile (date of birth, sex, weekly hours) - hr.leave.legal.manage (enter/end legal rules) - rules.signoff (verify).
 */
class LeaveSetupController extends Controller
{
    public function __construct(private LeavePolicyResolver $policy) {}

    private function org(): string { return app(OrganizationContext::class)->organizationId(); }
    private function lookupRule(string $type, bool $required = true): array
    {
        return [$required ? 'required' : 'nullable', Rule::exists('lookup_values', 'id')->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id'))];
    }

    // ---- types -------------------------------------------------------------------------------------------------------------------
    private function typeShape(LeaveTypeSetting $s): array
    {
        return ['id' => $s->id, 'leave_type_id' => $s->leave_type_id, 'leave_type' => $s->type->code, 'day_basis' => $s->dayBasis->code, 'uses_balance' => $s->uses_balance, 'accrues' => $s->accrues,
            'is_paid' => $s->is_paid, 'requires_document' => $s->requires_document, 'allow_retroactive' => $s->allow_retroactive, 'ignores_blackout' => $s->ignores_blackout,
            'allow_half_days' => $s->allow_half_days, 'is_requestable' => $s->is_requestable, 'is_active' => $s->is_active];
    }

    public function types() { return ['data' => LeaveTypeSetting::with(['type', 'dayBasis'])->get()->sortBy('type.sort_order')->values()->map(fn ($s) => $this->typeShape($s))]; }

    public function updateType(Request $request, string $id)
    {
        $s = LeaveTypeSetting::with(['type', 'dayBasis'])->findOrFail($id);
        $d = $request->validate(['day_basis_id' => $this->lookupRule('leave_day_basis', false), 'uses_balance' => 'boolean', 'accrues' => 'boolean', 'is_paid' => 'boolean', 'requires_document' => 'boolean',
            'allow_retroactive' => 'boolean', 'ignores_blackout' => 'boolean', 'allow_half_days' => 'boolean', 'is_requestable' => 'boolean', 'is_active' => 'boolean']);
        if (!empty($d['accrues']) && isset($d['uses_balance']) && !$d['uses_balance']) {
            throw ValidationException::withMessages(['accrues' => [__('leave.accrues_needs_balance')]]);
        }
        $old = $s->only(array_keys($d));
        $s->update($d);
        AuditLogger::log('leave.type_updated', $s, $old, $d);
        return ['data' => $this->typeShape($s->refresh()->load(['type', 'dayBasis']))];
    }

    // ---- recommended and blackout periods ------------------------------------------------------------------------------------------
    private function periodShape(LeavePeriod $p): array
    {
        $p->loadMissing(['kind', 'leaveType']);
        return ['id' => $p->id, 'kind' => $p->kind->code, 'name' => $p->name, 'starts_on' => $p->starts_on->toDateString(), 'ends_on' => $p->ends_on->toDateString(), 'leave_type' => $p->leaveType?->code, 'leave_type_id' => $p->leave_type_id, 'is_active' => $p->is_active];
    }

    private function periodRules(): array
    {
        return ['kind_id' => $this->lookupRule('leave_period_kind'), 'name' => 'required|string|max:150', 'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'leave_type_id' => $this->lookupRule('leave_type', false), 'is_active' => 'boolean'];
    }

    public function periods() { return ['data' => LeavePeriod::with(['kind', 'leaveType'])->orderBy('starts_on')->get()->map(fn ($p) => $this->periodShape($p))]; }

    public function storePeriod(Request $request)
    {
        $p = LeavePeriod::create($request->validate($this->periodRules()) + ['created_by' => $request->user()->id]);
        AuditLogger::log('leave.period_created', $p, [], $p->only(['name', 'starts_on', 'ends_on']));
        return response()->json(['data' => $this->periodShape($p)], 201);
    }

    public function updatePeriod(Request $request, string $id)
    {
        $p = LeavePeriod::findOrFail($id);
        $d = $request->validate($this->periodRules());
        $p->update($d);
        AuditLogger::log('leave.period_updated', $p);
        return ['data' => $this->periodShape($p->refresh())];
    }

    public function destroyPeriod(string $id)
    {
        $p = LeavePeriod::findOrFail($id);
        AuditLogger::log('leave.period_deleted', $p, $p->only(['name']));
        $p->delete();
        return response()->noContent();
    }

    // ---- leave profile (sensitive: audited on read) -----------------------------------------------------------------------------------
    public function profile(Request $request, string $employeeId)
    {
        $e = Employee::query()->visibleTo($request->user())->findOrFail($employeeId);
        $p = $this->policy->profile($e);
        AuditLogger::log('leave.profile_viewed', $e);
        return ['data' => ['employee_id' => $e->id, 'date_of_birth' => $p?->date_of_birth ? substr((string) $p->date_of_birth, 0, 10) : null, 'sex' => $p?->sex?->code, 'weekly_hours' => $p?->weekly_hours === null ? null : (string) $p->weekly_hours]];
    }

    public function saveProfile(Request $request, string $employeeId)
    {
        $e = Employee::query()->visibleTo($request->user())->findOrFail($employeeId);
        $d = $request->validate(['date_of_birth' => 'nullable|date_format:Y-m-d|before:today|after:1900-01-01', 'sex_id' => $this->lookupRule('employee_sex', false), 'weekly_hours' => 'nullable|numeric|between:1,168']);
        LeaveProfile::updateOrCreate(['employee_id' => $e->id], $d + ['updated_by' => $request->user()->id]);
        AuditLogger::log('leave.profile_updated', $e, [], ['fields' => implode(',', array_keys($d))]);       // values are never copied into the audit trail
        return response()->noContent();
    }

    // ---- statutory rules ------------------------------------------------------------------------------------------------------------------
    private function ruleShape(LegalLeaveRule $r): array
    {
        $p = $r->parameter;
        return ['id' => $r->id, 'rule_key' => $r->rule_key, 'bound' => $p->boundType->code, 'value' => (string) $p->value_decimal, 'unit' => $p->unit, 'valid_from' => $p->valid_from->toDateString(), 'valid_to' => $p->valid_to?->toDateString(),
            'source_citation' => $p->source_citation, 'verification' => $p->verification->code, 'verified_at' => $p->verified_at?->toIso8601String(), 'leave_type' => $r->leaveType?->code, 'sex' => $r->sex?->code,
            'min_age' => $r->min_age, 'max_age' => $r->max_age, 'created_by' => $r->creator?->name];
    }

    public function legalRules()
    {
        return ['data' => LegalLeaveRule::with(['parameter.boundType', 'parameter.verification', 'leaveType', 'sex', 'creator'])->get()->sortBy(['rule_key'])->values()->map(fn ($r) => $this->ruleShape($r))];
    }

    public function storeLegalRule(Request $request)
    {
        $d = $request->validate(['rule_key' => ['required', Rule::in(array_keys(LeavePolicy::KEYS))], 'bound_type_id' => $this->lookupRule('legal_bound'), 'value' => 'required|numeric|min:0|max:100000',
            'unit' => 'nullable|string|max:30', 'valid_from' => 'required|date_format:Y-m-d', 'valid_to' => 'nullable|date_format:Y-m-d|after_or_equal:valid_from', 'source_citation' => 'required|string|max:255',
            'leave_type_id' => $this->lookupRule('leave_type', false), 'sex_id' => $this->lookupRule('employee_sex', false), 'min_age' => 'nullable|integer|between:0,120', 'max_age' => 'nullable|integer|between:0,120|gte:min_age']);
        $org = $this->org();
        $code = CodeGenerator::key('leave ' . $d['rule_key'], fn ($c) => LegalParameter::withoutGlobalScopes()->where('organization_id', $org)->where('code', $c)->exists());
        $rule = DB::transaction(function () use ($d, $request, $code) {
            $param = LegalParameter::create(['domain_id' => Lookup::id('rule_domain', 'leave'), 'code' => $code, 'name' => 'Leave: ' . $d['rule_key'], 'bound_type_id' => $d['bound_type_id'], 'value_decimal' => $d['value'],
                'unit' => $d['unit'] ?? null, 'valid_from' => $d['valid_from'], 'valid_to' => $d['valid_to'] ?? null, 'source_citation' => $d['source_citation'], 'verification_status_id' => Lookup::id('rule_verification_status', 'unverified')]);
            return LegalLeaveRule::create(['legal_parameter_id' => $param->id, 'leave_type_id' => $d['leave_type_id'] ?? null, 'rule_key' => $d['rule_key'], 'sex_id' => $d['sex_id'] ?? null,
                'min_age' => $d['min_age'] ?? null, 'max_age' => $d['max_age'] ?? null, 'created_by' => $request->user()->id]);
        });
        AuditLogger::log('leave.legal_rule_created', $rule, [], collect($d)->except([])->all());
        $this->policy->forget();
        return response()->json(['data' => $this->ruleShape($rule->load(['parameter.boundType', 'parameter.verification', 'leaveType', 'sex', 'creator']))], 201);
    }

    /** Sign-off by a different person than the one who entered the rule (Guide 3.7.3): until then the value is flagged and never applied. */
    public function verifyLegalRule(Request $request, string $id)
    {
        $rule = LegalLeaveRule::with('parameter')->findOrFail($id);
        if ($rule->created_by === $request->user()->id) {
            throw ValidationException::withMessages(['id' => [__('leave.verify_not_author')]]);
        }
        if ($rule->parameter->verification_status_id === Lookup::id('rule_verification_status', 'verified')) {
            throw ValidationException::withMessages(['id' => [__('leave.already_verified')]]);
        }
        $rule->parameter->update(['verification_status_id' => Lookup::id('rule_verification_status', 'verified'), 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        AuditLogger::log('leave.legal_rule_verified', $rule, ['verification' => 'unverified'], ['verification' => 'verified']);
        $this->policy->forget();
        return ['data' => $this->ruleShape($rule->refresh()->load(['parameter.boundType', 'parameter.verification', 'leaveType', 'sex', 'creator']))];
    }

    /** A verified rule is never edited. When the law changes, the old rule is ended and a new one entered (and verified). */
    public function endLegalRule(Request $request, string $id)
    {
        $rule = LegalLeaveRule::with('parameter')->findOrFail($id);
        $d = $request->validate(['valid_to' => 'required|date_format:Y-m-d|after_or_equal:' . $rule->parameter->valid_from->toDateString()]);
        $old = ['valid_to' => $rule->parameter->valid_to?->toDateString()];
        $rule->parameter->update($d);
        AuditLogger::log('leave.legal_rule_ended', $rule, $old, $d);
        $this->policy->forget();
        return ['data' => $this->ruleShape($rule->refresh()->load(['parameter.boundType', 'parameter.verification', 'leaveType', 'sex', 'creator']))];
    }

    // ---- policy keys, worked examples, audit --------------------------------------------------------------------------------------------------
    public function policyKeys()
    {
        return ['data' => collect(LeavePolicy::KEYS)->map(fn ($d, $k) => ['key' => $k, 'type' => $d[0], 'unit' => $d[1]])->values()];
    }

    /** What really applies to one employee for one leave type on one date: company value, statutory floor/ceiling, effective value. Used for the HR/legal worked examples. */
    public function policyPreview(Request $request)
    {
        $d = $request->validate(['employee_id' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())], 'leave_type_id' => $this->lookupRule('leave_type'), 'date' => 'nullable|date_format:Y-m-d']);
        $e = Employee::query()->visibleTo($request->user())->findOrFail($d['employee_id']);
        $code = \App\Models\LookupValue::findOrFail($d['leave_type_id'])->code;
        $on = $d['date'] ?? now()->toDateString();
        $eff = $this->policy->effective($e, $code, $on);
        return ['data' => ['date' => $on, 'leave_type' => $code, 'values' => $eff['values'], 'detail' => $eff['detail'], 'rule_version_id' => $eff['rule_version_id'], 'missing_profile' => $eff['missing'],
            'annual_entitlement' => $this->policy->entitlementDays($e, $code, $on)]];
    }

    /** "No policy below the statutory floor" (Phase 5 exit criterion): every leave policy version that breaks a verified statutory limit. */
    public function policyViolations()
    {
        $rules = collect($this->policy->legalRules($this->org()))->where('verified', true)->values();
        $versions = RuleVersion::with(['ruleSet.domain', 'status', 'conditions.attribute', 'conditions.operator', 'outcomes'])
            ->whereHas('ruleSet.domain', fn ($q) => $q->where('code', 'leave'))->whereHas('status', fn ($q) => $q->whereIn('code', ['draft', 'impact_check', 'pending_approval', 'active']))->get();
        $out = [];
        foreach ($versions as $v) {
            $typeCond = $v->conditions->first(fn ($c) => $c->attribute->code === 'leave_type' && $c->operator->code === 'eq')?->value_text;
            foreach ($v->outcomes as $o) {
                $val = $o->value_decimal ?? $o->value_integer;
                if ($val === null) {
                    continue;
                }
                foreach ($rules->where('key', $o->outcome_key) as $r) {
                    if ($typeCond && $r['leave_type'] && $typeCond !== $r['leave_type']) {
                        continue;
                    }
                    $facts = ['leave_type' => $r['leave_type'] ?? $typeCond, 'sex' => $r['sex'], 'age' => $r['min_age'] ?? $r['max_age']];
                    $kind = LegalLeaveRules::violation($o->outcome_key, (string) $val, [$r], $facts, $v->effective_from->toDateString());
                    if ($kind) {
                        $out[] = ['rule_set' => $v->ruleSet->code, 'rule_set_name' => $v->ruleSet->name, 'version_id' => $v->id, 'version' => $v->version, 'status' => $v->status->code, 'key' => $o->outcome_key,
                            'value' => (string) $val, 'violation' => $kind, 'statutory_value' => $r['value'], 'statutory_rule_id' => $r['id']];
                    }
                }
            }
        }
        return ['data' => $out];
    }

    /** Go-live checklist for the leave module. */
    public function status()
    {
        $rules = $this->policy->legalRules($this->org());
        $unverified = count(array_filter($rules, fn ($r) => !$r['verified']));
        $activePolicy = RuleVersion::whereHas('ruleSet.domain', fn ($q) => $q->where('code', 'leave'))->whereHas('status', fn ($q) => $q->where('code', 'active'))->count();
        $types = LeaveTypeSetting::where('is_active', true)->count();
        $noProfile = Employee::whereNotIn('id', LeaveProfile::select('employee_id'))->count();
        return ['data' => ['legal_rules' => count($rules), 'unverified_legal_rules' => $unverified, 'active_policy_versions' => $activePolicy, 'active_types' => $types, 'employees_without_profile' => $noProfile,
            'ready' => $unverified === 0 && count($rules) > 0 && $activePolicy > 0]];
    }
}
