<?php

namespace App\Services\Rules;

use App\Models\ApprovalRequest;
use App\Models\RuleActivation;
use App\Models\RuleSet;
use App\Models\RuleVersion;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Draft -> Impact check -> Pending approval -> Active -> Superseded / Expired (Guide 3.7.2). */
class RuleLifecycle
{
    public function __construct(private RuleValidator $validator, private ApprovalEngine $approvals, private RuleResolver $resolver) {}

    public function createVersion(RuleSet $set, array $data, User $by): RuleVersion
    {
        return DB::transaction(function () use ($set, $data, $by) {
            $next = (int) RuleVersion::where('rule_set_id', $set->id)->max('version') + 1;
            $v = RuleVersion::create([
                'rule_set_id' => $set->id, 'version' => $next, 'status_id' => Lookup::id('rule_status', 'draft'),
                'priority' => $data['priority'] ?? 100, 'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
                'rationale' => $data['rationale'] ?? null, 'source_reference' => $data['source_reference'] ?? null,
                'verification_status_id' => Lookup::id('rule_verification_status', $set->is_legal_profile ? 'unverified' : 'not_required'),
                'created_by' => $by->id,
            ]);
            $this->replaceContent($v, $data);
            $this->record($v, 'draft', $by, 'created');
            AuditLogger::log('rule.version_created', $v, [], ['rule_set' => $set->code, 'version' => $next]);
            return $v;
        });
    }

    /** Only drafts and impact-checked drafts may change; editing sends an impact-checked draft back to draft. */
    public function replaceContent(RuleVersion $v, array $data): void
    {
        if (!$v->isDraftLike()) {
            $this->fail('not_editable');
        }
        $v->conditions()->delete();
        $v->outcomes()->delete();
        foreach ($data['conditions'] ?? [] as $c) {
            $v->conditions()->create([
                'attribute_id' => Lookup::id('rule_attribute', $c['attribute']), 'operator_id' => Lookup::id('rule_operator', $c['operator']),
                'value_text' => $c['text'] ?? null, 'value_decimal' => $c['decimal'] ?? null, 'value_decimal_to' => $c['decimal_to'] ?? null,
            ]);
        }
        foreach ($data['outcomes'] ?? [] as $o) {
            $v->outcomes()->create([
                'outcome_key' => $o['key'], 'approval_flow_id' => $o['approval_flow_id'] ?? null, 'value_text' => $o['text'] ?? null,
                'value_decimal' => $o['decimal'] ?? null, 'value_integer' => $o['integer'] ?? null, 'value_boolean' => $o['boolean'] ?? null,
            ]);
        }
        if ($v->status?->code === 'impact_check') {
            $this->setStatus($v, 'draft', null, 'edited');
        }
    }

    /** What-if simulation on sample transactions: which version decides today vs. with this version included. */
    public function impactCheck(RuleVersion $v, array $samples, User $by): array
    {
        if (!$v->isDraftLike()) $this->fail('not_editable');
        $v->load(['ruleSet.domain', 'conditions.attribute', 'conditions.operator', 'outcomes']);
        $domain = $v->ruleSet->domain->code;
        $current = $this->resolver->candidates($v->organization_id, $domain);
        $mine = [
            'id' => $v->id, 'version' => $v->version, 'rule_set' => $v->ruleSet->code, 'priority' => $v->priority,
            'effective_from' => $v->effective_from->toDateString(), 'effective_to' => $v->effective_to?->toDateString(),
            'conditions' => $this->validator->conditionArrays($v), 'outcomes' => $v->outcomes->mapWithKeys(fn ($o) => [$o->outcome_key => $o->value()])->all(),
        ];
        $on = $v->effective_from->isFuture() ? $v->effective_from->toDateString() : now()->toDateString();

        $rows = [];
        foreach ($samples as $attrs) {
            $before = RuleMatcher::pick($current, $attrs, $on);
            $after = RuleMatcher::pick(array_merge($current, [$mine]), $attrs, $on);
            $rows[] = [
                'sample' => $attrs, 'on' => $on,
                'current' => $before ? ['rule_set' => $before['rule_set'], 'version' => $before['version'], 'outcomes' => $before['outcomes']] : null,
                'proposed' => $after ? ['rule_set' => $after['rule_set'], 'version' => $after['version'], 'outcomes' => $after['outcomes']] : null,
                'changes' => ($before['id'] ?? null) !== ($after['id'] ?? null),
            ];
        }
        $this->setStatus($v, 'impact_check', $by, 'impact check on ' . count($samples) . ' sample(s)');
        return ['samples' => $rows, 'validation' => $this->validator->check($v)];
    }

    public function submit(RuleVersion $v, User $by): ApprovalRequest
    {
        if ($v->status->code !== 'impact_check') $this->fail($v->status->code === 'draft' ? 'impact_check_required' : 'not_editable');
        $problems = $this->validator->check($v);
        if ($problems['errors']) {
            throw ValidationException::withMessages(['rule' => array_map(fn ($e) => __("foundation.rule.$e"), $problems['errors'])]);
        }
        $v->loadMissing('ruleSet');
        $req = $this->approvals->submit('rule_activation', $v->id, $by, $v->ruleSet->name . ' v' . $v->version, ['department_id' => null]);
        $v->update(['approval_request_id' => $req->id, 'submitted_at' => now()]);
        $this->setStatus($v, 'pending_approval', $by, 'submitted for approval');
        return $req;
    }

    public function signOff(RuleVersion $v, User $by, ?string $note): RuleVersion
    {
        $v->loadMissing('ruleSet');
        if (!$v->ruleSet->is_legal_profile) $this->fail('not_legal_profile');
        if ($v->created_by === $by->id) $this->fail('author_cannot_signoff');
        $v->update(['verification_status_id' => Lookup::id('rule_verification_status', 'verified'), 'signed_off_by' => $by->id, 'signed_off_at' => now(), 'signoff_note' => $note]);
        $this->record($v, $v->status->code, $by, 'legal sign-off');
        AuditLogger::log('rule.signed_off', $v, [], ['note' => $note]);
        return $v->refresh();
    }

    /** Called by the approval handler once the independent approver has approved. */
    public function activate(RuleVersion $v): void
    {
        $v->loadMissing(['ruleSet', 'verification']);
        $problems = $this->validator->check($v);
        $blockers = array_merge($problems['errors'], $this->validator->activationBlockers($v));
        if ($blockers) {
            $this->revert($v, 'activation blocked: ' . implode(', ', $blockers));
            return;
        }
        DB::transaction(function () use ($v) {
            $previous = RuleVersion::where('rule_set_id', $v->rule_set_id)->where('id', '!=', $v->id)
                ->whereHas('status', fn ($q) => $q->where('code', 'active'))->get();
            foreach ($previous as $p) {
                $end = $v->effective_from->copy()->subDay();
                if ($end->lt($p->effective_from)) $end = $p->effective_from->copy()->subDay();
                $p->update(['status_id' => Lookup::id('rule_status', 'superseded'), 'effective_to' => $p->effective_to && $p->effective_to->lt($end) ? $p->effective_to : $end]);
                $this->record($p, 'superseded', null, 'superseded by v' . $v->version);
            }
            $v->update(['status_id' => Lookup::id('rule_status', 'active'), 'activated_at' => now()]);
            $this->record($v, 'active', null, 'approved and activated');
            AuditLogger::log('rule.activated', $v, [], ['version' => $v->version, 'effective_from' => $v->effective_from->toDateString()]);
        });
        RuleResolver::flush($v->organization_id);
    }

    public function revert(RuleVersion $v, string $note): void
    {
        $this->setStatus($v, 'draft', null, $note);
        $v->update(['approval_request_id' => null]);
        AuditLogger::log('rule.reverted', $v, [], ['note' => $note]);
    }

    public function expireDue(): int
    {
        $n = 0;
        RuleVersion::withoutGlobalScopes()->whereHas('status', fn ($q) => $q->where('code', 'active'))
            ->whereNotNull('effective_to')->whereDate('effective_to', '<', now()->toDateString())->get()
            ->each(function (RuleVersion $v) use (&$n) {
                $this->setStatus($v, 'expired', null, 'effective period ended');
                RuleResolver::flush($v->organization_id);
                $n++;
            });
        return $n;
    }

    private function setStatus(RuleVersion $v, string $code, ?User $by, ?string $note): void
    {
        $v->update(['status_id' => Lookup::id('rule_status', $code)]);
        $v->setRelation('status', null)->load('status');
        $this->record($v, $code, $by, $note);
    }

    private function record(RuleVersion $v, string $statusCode, ?User $by, ?string $note): void
    {
        RuleActivation::create(['organization_id' => $v->organization_id, 'rule_version_id' => $v->id, 'status_id' => Lookup::id('rule_status', $statusCode), 'actor_id' => $by?->id, 'note' => $note]);
    }

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['rule' => [__("foundation.rule.$key")]]);
    }
}
