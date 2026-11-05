<?php

namespace App\Services\Rules;

use App\Models\LegalParameter;
use App\Models\RuleVersion;

/**
 * Save-time checks (Guide 3.7.2). errors block activation; warnings are shown in the impact check.
 * Messages are translation keys under foundation.rule.*
 */
class RuleValidator
{
    /** @return array{errors: string[], warnings: string[]} */
    public function check(RuleVersion $v): array
    {
        $v->loadMissing(['ruleSet.domain', 'conditions.attribute', 'conditions.operator', 'outcomes']);
        $errors = [];
        $warnings = [];

        if ($v->outcomes->isEmpty()) $errors[] = 'no_outcome';
        if ($v->effective_to && $v->effective_to->lt($v->effective_from)) $errors[] = 'dates_inverted';

        $conds = $this->conditionArrays($v);
        foreach ($conds as $c) {
            $numeric = in_array($c['operator'], ['gte', 'gt', 'lte', 'lt', 'between'], true);
            if ($numeric && $c['decimal'] === null) $errors[] = 'numeric_value_missing';
            if (in_array($c['operator'], ['eq', 'ne', 'in'], true) && $c['decimal'] === null && ($c['text'] === null || $c['text'] === '')) $errors[] = 'value_missing';
            if ($c['operator'] === 'between' && ($c['decimal_to'] === null || bccomp((string) $c['decimal'], (string) $c['decimal_to'], 2) > 0)) $errors[] = 'thresholds_out_of_order';
        }
        [$lo, $hi] = RuleMatcher::amountInterval($conds);
        if ($lo > $hi) $errors[] = 'thresholds_out_of_order';

        // Ambiguity: another rule set in the same domain with the same condition signature, same priority, overlapping dates.
        $sig = $this->signature($conds);
        $others = RuleVersion::query()->where('id', '!=', $v->id)
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['active', 'superseded', 'pending_approval']))
            ->whereHas('ruleSet', fn ($q) => $q->where('id', '!=', $v->rule_set_id)->where('domain_id', $v->ruleSet->domain_id))
            ->where('priority', $v->priority)
            ->with(['conditions.attribute', 'conditions.operator'])->get();
        foreach ($others as $o) {
            if ($this->signature($this->conditionArrays($o)) === $sig && $this->overlaps($v, $o)) { $errors[] = 'overlap'; break; }
        }

        // Statutory floors/ceilings stored in legal_parameters are enforced (COPS cannot certify lawfulness, only enforce stored limits).
        foreach ($v->outcomes as $o) {
            $val = $o->value_decimal ?? $o->value_integer;
            if ($val === null) continue;
            $params = LegalParameter::query()->where('code', $o->outcome_key)
                ->whereDate('valid_from', '<=', $v->effective_from)
                ->where(fn ($q) => $q->whereNull('valid_to')->orWhereDate('valid_to', '>=', $v->effective_from))->with('boundType')->get();
            foreach ($params as $p) {
                $below = bccomp((string) $val, (string) $p->value_decimal, 4) < 0;
                $above = bccomp((string) $val, (string) $p->value_decimal, 4) > 0;
                if (($p->boundType->code === 'floor' && $below) || ($p->boundType->code === 'ceiling' && $above)) $errors[] = 'statutory_limit';
            }
        }

        // Coverage of amount bands within the rule set (blocks only when the set demands full coverage).
        $intervals = [RuleMatcher::amountInterval($conds)];
        $siblings = RuleVersion::query()->where('rule_set_id', $v->rule_set_id)->where('id', '!=', $v->id)
            ->whereHas('status', fn ($q) => $q->whereIn('code', ['active', 'pending_approval']))->with(['conditions.attribute', 'conditions.operator'])->get();
        foreach ($siblings as $s) {
            if ($this->overlaps($v, $s) && $this->signature($this->conditionArrays($s), true) === $this->signature($conds, true)) {
                $intervals[] = RuleMatcher::amountInterval($this->conditionArrays($s));
            }
        }
        if (RuleMatcher::coverageGaps($intervals)) {
            $v->ruleSet->requires_full_coverage ? $errors[] = 'coverage_gap' : $warnings[] = 'coverage_gap';
        }

        return ['errors' => array_values(array_unique($errors)), 'warnings' => array_values(array_unique($warnings))];
    }

    /** Legal-profile rules cannot go live until verified and signed off by a named person (Guide 3.7.3). */
    public function activationBlockers(RuleVersion $v): array
    {
        $v->loadMissing(['ruleSet', 'verification']);
        $b = [];
        if ($v->ruleSet->is_legal_profile && ($v->verification?->code !== 'verified' || !$v->signed_off_by)) $b[] = 'unverified_legal_profile';
        return $b;
    }

    public function conditionArrays(RuleVersion $v): array
    {
        return $v->conditions->map(fn ($c) => [
            'attribute' => $c->attribute->code, 'operator' => $c->operator->code,
            'text' => $c->value_text, 'decimal' => $c->value_decimal, 'decimal_to' => $c->value_decimal_to,
        ])->all();
    }

    private function signature(array $conds, bool $ignoreAmount = false): string
    {
        $parts = array_map(fn ($c) => implode('|', [$c['attribute'], $c['operator'], $c['text'], $c['decimal'], $c['decimal_to']]),
            array_values(array_filter($conds, fn ($c) => !$ignoreAmount || $c['attribute'] !== 'amount')));
        sort($parts);
        return implode(';', $parts);
    }

    private function overlaps(RuleVersion $a, RuleVersion $b): bool
    {
        $aEnd = $a->effective_to?->toDateString() ?? '9999-12-31';
        $bEnd = $b->effective_to?->toDateString() ?? '9999-12-31';
        return $a->effective_from->toDateString() <= $bEnd && $b->effective_from->toDateString() <= $aEnd;
    }
}
