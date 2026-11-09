<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LegalParameter;
use App\Models\RuleSet;
use App\Models\RuleVersion;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;
use App\Services\Rules\RuleLifecycle;
use App\Services\Rules\RuleValidator;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** perm: rules.view (read) - rules.configure (draft, impact check, submit) - rules.signoff (legal verification) - rules.approve (via approvals). */
class RuleController extends Controller
{
    public function __construct(private RuleLifecycle $lifecycle, private RuleValidator $validator) {}

    private function version(RuleVersion $v): array
    {
        $v->loadMissing(['status', 'verification', 'conditions.attribute', 'conditions.operator', 'outcomes.flow', 'history.status', 'history.actor']);
        return [
            'id' => $v->id, 'version' => $v->version, 'status' => $v->status->code, 'priority' => $v->priority,
            'effective_from' => $v->effective_from->toDateString(), 'effective_to' => $v->effective_to?->toDateString(),
            'rationale' => $v->rationale, 'source_reference' => $v->source_reference, 'verification' => $v->verification->code,
            'signed_off_by' => $v->signed_off_by, 'signed_off_at' => $v->signed_off_at?->toIso8601String(), 'approval_request_id' => $v->approval_request_id,
            'conditions' => $v->conditions->map(fn ($c) => ['attribute' => $c->attribute->code, 'operator' => $c->operator->code, 'text' => $c->value_text, 'decimal' => $c->value_decimal, 'decimal_to' => $c->value_decimal_to]),
            'outcomes' => $v->outcomes->map(fn ($o) => ['key' => $o->outcome_key, 'approval_flow_id' => $o->approval_flow_id, 'flow' => $o->flow?->name, 'text' => $o->value_text, 'decimal' => $o->value_decimal, 'integer' => $o->value_integer, 'boolean' => $o->value_boolean]),
            'history' => $v->history->map(fn ($h) => ['status' => $h->status->code, 'actor' => $h->actor?->name, 'note' => $h->note, 'at' => $h->created_at?->toIso8601String()]),
        ];
    }

    private function contentRules(): array
    {
        $org = app(OrganizationContext::class)->organizationId();
        return [
            'priority' => 'nullable|integer|min:0|max:100000', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'rationale' => 'nullable|string|max:5000', 'source_reference' => 'nullable|string|max:255',
            'conditions' => 'array|max:12',
            'conditions.*.attribute' => ['required', Rule::exists('lookup_values', 'code')->whereIn('lookup_type_id', \App\Models\LookupType::where('code', 'rule_attribute')->pluck('id'))],
            'conditions.*.operator' => ['required', Rule::exists('lookup_values', 'code')->whereIn('lookup_type_id', \App\Models\LookupType::where('code', 'rule_operator')->pluck('id'))],
            'conditions.*.text' => 'nullable|string|max:255', 'conditions.*.decimal' => 'nullable|numeric', 'conditions.*.decimal_to' => 'nullable|numeric',
            'outcomes' => 'required|array|min:1|max:20', 'outcomes.*.key' => 'required|string|max:80|distinct',
            'outcomes.*.approval_flow_id' => ['nullable', Rule::exists('approval_flows', 'id')->where('organization_id', $org)],
            'outcomes.*.text' => 'nullable|string|max:255', 'outcomes.*.decimal' => 'nullable|numeric', 'outcomes.*.integer' => 'nullable|integer', 'outcomes.*.boolean' => 'nullable|boolean',
        ];
    }

    // ---- sets ----------------------------------------------------------------------------------------------------------
    public function sets()
    {
        return ['data' => RuleSet::with(['domain', 'versions.status', 'versions.verification'])->orderBy('code')->get()->map(fn ($s) => [
            'id' => $s->id, 'code' => $s->code, 'name' => $s->name, 'description' => $s->description, 'domain' => $s->domain->code, 'domain_id' => $s->domain_id,
            'is_legal_profile' => $s->is_legal_profile, 'requires_full_coverage' => $s->requires_full_coverage,
            'versions' => $s->versions->map(fn ($v) => ['id' => $v->id, 'version' => $v->version, 'status' => $v->status->code, 'verification' => $v->verification->code,
                'effective_from' => $v->effective_from->toDateString(), 'effective_to' => $v->effective_to?->toDateString(), 'priority' => $v->priority]),
        ])];
    }

    public function storeSet(Request $request)
    {
        $org = app(OrganizationContext::class)->organizationId();
        $d = $request->validate([
            'domain_id' => ['required', Rule::exists('lookup_values', 'id')],      // the set code (RUL-0001) is system-generated
            'name' => 'required|string|max:255', 'description' => 'nullable|string|max:3000',
            'is_legal_profile' => 'boolean', 'requires_full_coverage' => 'boolean',
        ]);
        $set = RuleSet::create($d + ['owner_user_id' => $request->user()->id, 'created_by' => $request->user()->id]);
        AuditLogger::log('rule.set_created', $set, [], $d);
        return response()->json(['id' => $set->id], 201);
    }

    // ---- versions ------------------------------------------------------------------------------------------------------
    public function showVersion(string $id) { return ['data' => $this->version(RuleVersion::findOrFail($id))]; }

    public function storeVersion(Request $request, string $setId)
    {
        $set = RuleSet::findOrFail($setId);
        $v = $this->lifecycle->createVersion($set, $request->validate($this->contentRules()), $request->user());
        return response()->json(['data' => $this->version($v)], 201);
    }

    public function updateVersion(Request $request, string $id)
    {
        $v = RuleVersion::findOrFail($id);
        $d = $request->validate($this->contentRules());
        $v->update(['priority' => $d['priority'] ?? $v->priority, 'effective_from' => $d['effective_from'], 'effective_to' => $d['effective_to'] ?? null,
            'rationale' => $d['rationale'] ?? null, 'source_reference' => $d['source_reference'] ?? null]);
        $this->lifecycle->replaceContent($v, $d);
        AuditLogger::log('rule.version_updated', $v);
        return ['data' => $this->version($v->refresh())];
    }

    public function impact(Request $request, string $id)
    {
        $d = $request->validate(['samples' => 'required|array|min:1|max:50', 'samples.*' => 'array']);
        return $this->lifecycle->impactCheck(RuleVersion::findOrFail($id), $d['samples'], $request->user());
    }

    public function validateVersion(string $id) { return $this->validator->check(RuleVersion::findOrFail($id)); }

    public function submit(Request $request, string $id)
    {
        $req = $this->lifecycle->submit(RuleVersion::findOrFail($id), $request->user());
        return response()->json(['approval_request_id' => $req->id], 202);
    }

    public function signOff(Request $request, string $id)
    {
        $d = $request->validate(['note' => 'nullable|string|max:2000']);
        return ['data' => $this->version($this->lifecycle->signOff(RuleVersion::findOrFail($id), $request->user(), $d['note'] ?? null))];
    }

    // ---- legal parameters (statutory floors and ceilings) --------------------------------------------------------------------
    public function legal()
    {
        return ['data' => LegalParameter::with(['domain', 'boundType', 'verification'])->orderBy('code')->orderByDesc('valid_from')->get()->map(fn ($p) => [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'domain' => $p->domain->code, 'bound_type' => $p->boundType->code, 'value' => $p->value_decimal, 'unit' => $p->unit,
            'valid_from' => $p->valid_from->toDateString(), 'valid_to' => $p->valid_to?->toDateString(), 'source_citation' => $p->source_citation,
            'verification' => $p->verification->code, 'verified_at' => $p->verified_at?->toIso8601String()])];
    }

    public function storeLegal(Request $request)
    {
        $d = $request->validate([
            'domain_id' => ['required', Rule::exists('lookup_values', 'id')], 'bound_type_id' => ['required', Rule::exists('lookup_values', 'id')],
            'name' => 'required|string|max:255', 'value_decimal' => 'required|numeric', 'unit' => 'nullable|string|max:30',
            'new_value_of' => ['nullable', Rule::exists('legal_parameters', 'id')->where('organization_id', app(OrganizationContext::class)->organizationId())],
            'valid_from' => 'required|date', 'valid_to' => 'nullable|date|after_or_equal:valid_from', 'source_citation' => 'required|string|max:255',
        ]);
        // The key comes from the name (annual_leave_minimum, _2, _3...). A new value of an existing parameter keeps that parameter's key.
        $org = app(OrganizationContext::class)->organizationId();
        $code = isset($d['new_value_of'])
            ? LegalParameter::findOrFail($d['new_value_of'])->code
            : \App\Services\CodeGenerator::key($d['name'], fn ($c) => LegalParameter::withoutGlobalScopes()->where('organization_id', $org)->where('code', $c)->exists());
        $p = LegalParameter::create(collect($d)->except('new_value_of')->all() + ['code' => $code, 'verification_status_id' => Lookup::id('rule_verification_status', 'unverified')]);
        AuditLogger::log('legal_parameter.created', $p, [], $d);
        return response()->json(['id' => $p->id], 201);
    }

    /** Sign-off by someone other than the person who entered the value is enforced by permission separation (rules.signoff). */
    public function verifyLegal(Request $request, string $id)
    {
        $p = LegalParameter::findOrFail($id);
        $p->update(['verification_status_id' => Lookup::id('rule_verification_status', 'verified'), 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        AuditLogger::log('legal_parameter.verified', $p, ['verification' => 'unverified'], ['verification' => 'verified']);
        return response()->noContent();
    }
}
