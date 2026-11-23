<?php

namespace App\Services\Hr\Leave;

/**
 * Statutory floors and ceilings over the company's leave policy (Guide 5.4 and 3.7.3). Pure.
 * A rule bounds one policy key ("entitlement_days", "max_days_per_request"...) for the employees it is eligible for.
 * Only VERIFIED rules count: an unverified value is shown as unverified and never applied (blocked from production, Guide 5.4).
 * Whatever the company policy says, the effective value is never below an applicable floor or above an applicable ceiling.
 *
 * rule  = ['key','bound' => floor|ceiling,'value','valid_from','valid_to','leave_type' => code|null,'sex' => code|null,'min_age' => ?int,'max_age' => ?int,'verified' => bool]
 * facts = ['leave_type' => code,'sex' => code|null,'age' => ?int]
 */
final class LegalLeaveRules
{
    /** @return array{eligible:bool,missing:string[]} missing = employee facts the rule needs but the profile does not have */
    public static function eligibility(array $rule, array $facts, string $on): array
    {
        $missing = [];
        if (empty($rule['verified']) || $rule['valid_from'] > $on || (!empty($rule['valid_to']) && $rule['valid_to'] < $on)) {
            return ['eligible' => false, 'missing' => []];
        }
        if (!empty($rule['leave_type']) && $rule['leave_type'] !== ($facts['leave_type'] ?? null)) {
            return ['eligible' => false, 'missing' => []];
        }
        if (!empty($rule['sex'])) {
            if (empty($facts['sex'])) {
                $missing[] = 'sex';
            } elseif ($facts['sex'] !== $rule['sex']) {
                return ['eligible' => false, 'missing' => []];
            }
        }
        $hasAge = isset($rule['min_age']) || isset($rule['max_age']);
        if ($hasAge) {
            $age = $facts['age'] ?? null;
            if ($age === null) {
                $missing[] = 'date_of_birth';
            } elseif ((isset($rule['min_age']) && $age < $rule['min_age']) || (isset($rule['max_age']) && $age > $rule['max_age'])) {
                return ['eligible' => false, 'missing' => []];
            }
        }
        return ['eligible' => $missing === [], 'missing' => $missing];
    }

    /**
     * @param ?string $company the company policy value for this key (null = the policy does not set it)
     * @return array{value:?string,company:?string,floor:?string,ceiling:?string,source:string,adjusted:bool,missing:string[]}
     *         source: company | statutory | none. adjusted: the statute changed the company value.
     */
    public static function apply(string $key, ?string $company, array $rules, array $facts, string $on): array
    {
        $floor = null;
        $ceiling = null;
        $missing = [];
        foreach ($rules as $r) {
            if ($r['key'] !== $key) {
                continue;
            }
            $e = self::eligibility($r, $facts, $on);
            $missing = array_merge($missing, $e['missing']);
            if (!$e['eligible']) {
                continue;
            }
            if ($r['bound'] === 'floor') {
                $floor = $floor === null || bccomp((string) $r['value'], $floor, 4) > 0 ? (string) $r['value'] : $floor;
            } else {
                $ceiling = $ceiling === null || bccomp((string) $r['value'], $ceiling, 4) < 0 ? (string) $r['value'] : $ceiling;
            }
        }
        $value = $company;
        $source = $company === null ? 'none' : 'company';
        if ($value === null && $floor !== null) {
            $value = $floor;
            $source = 'statutory';
        }
        $adjusted = false;
        if ($value !== null) {
            if ($floor !== null && bccomp($value, $floor, 4) < 0) {
                $value = $floor;
                $adjusted = true;
                $source = 'statutory';
            }
            if ($ceiling !== null && bccomp($value, $ceiling, 4) > 0) {
                $value = $ceiling;
                $adjusted = true;
                $source = 'statutory';
            }
        }
        return ['value' => $value, 'company' => $company, 'floor' => $floor, 'ceiling' => $ceiling, 'source' => $source, 'adjusted' => $adjusted, 'missing' => array_values(array_unique($missing))];
    }

    /** Does the company value break an applicable statutory limit? (what the policy audit lists: a policy must never grant less than the statute). */
    public static function violation(string $key, ?string $company, array $rules, array $facts, string $on): ?string
    {
        if ($company === null) {
            return null;
        }
        $r = self::apply($key, $company, $rules, $facts, $on);
        if ($r['floor'] !== null && bccomp($company, $r['floor'], 4) < 0) {
            return 'below_floor';
        }
        if ($r['ceiling'] !== null && bccomp($company, $r['ceiling'], 4) > 0) {
            return 'above_ceiling';
        }
        return null;
    }
}
