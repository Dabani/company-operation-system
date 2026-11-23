<?php

namespace App\Services\Hr\Leave;

use App\Models\Employee;
use App\Models\LeaveProfile;
use App\Models\LegalLeaveRule;
use App\Services\Rules\RuleResolver;
use Illuminate\Support\Facades\Cache;

/**
 * Company policy (shared rules engine, domain "leave") + statutory limits (verified legal rules) = the effective policy of one employee for one leave type on one date.
 * Resolution is deterministic and never invents a value: a key nobody sets stays null.
 */
class LeavePolicyResolver
{
    public function __construct(private RuleResolver $rules) {}

    private array $legal = [];

    /** Statutory rules of the organization as plain arrays (see LegalLeaveRules). */
    public function legalRules(string $org): array
    {
        return $this->legal[$org] ??= LegalLeaveRule::withoutGlobalScopes()->where('organization_id', $org)
            ->with(['parameter' => fn ($q) => $q->withoutGlobalScopes(), 'parameter.boundType', 'parameter.verification', 'leaveType', 'sex'])->get()
            ->filter(fn ($r) => $r->parameter)
            ->map(fn ($r) => [
                'id' => $r->id, 'key' => $r->rule_key, 'bound' => $r->parameter->boundType->code, 'value' => (string) $r->parameter->value_decimal,
                'valid_from' => $r->parameter->valid_from->toDateString(), 'valid_to' => $r->parameter->valid_to?->toDateString(),
                'leave_type' => $r->leaveType?->code, 'sex' => $r->sex?->code, 'min_age' => $r->min_age, 'max_age' => $r->max_age,
                'verified' => $r->parameter->verification?->code === 'verified',
            ])->values()->all();
    }

    public function forget(): void { $this->legal = []; }

    public function profile(Employee $e): ?LeaveProfile
    {
        return LeaveProfile::withoutGlobalScopes()->with('sex')->where('employee_id', $e->id)->first();
    }

    public function facts(Employee $e, string $typeCode, string $on): array
    {
        $p = $this->profile($e);
        $dob = $p?->date_of_birth;
        return ['leave_type' => $typeCode, 'sex' => $p?->sex?->code, 'age' => $dob ? LeaveEntitlement::ageYears((string) $dob, $on) : null];
    }

    /**
     * @return array{values:array<string,mixed>,detail:array<string,array>,rule_version_id:?string,missing:string[],company:array<string,mixed>}
     */
    public function effective(Employee $e, string $typeCode, string $on): array
    {
        $org = $e->organization_id;
        $e->loadMissing('category');
        $resolved = $this->rules->resolve('leave', array_filter(['leave_type' => $typeCode, 'employee_category' => $e->category?->code]), null, $on, $org);
        $company = LeavePolicy::fromOutcomes($resolved?->outcomes ?? []);
        $facts = $this->facts($e, $typeCode, $on);
        $legal = $this->legalRules($org);
        $values = [];
        $detail = [];
        $missing = [];
        foreach (LeavePolicy::KEYS as $key => [$type]) {
            if ($type === 'bool') {
                $values[$key] = $company[$key];
                continue;
            }
            $r = LegalLeaveRules::apply($key, $company[$key] === null ? null : (string) $company[$key], $legal, $facts, $on);
            $detail[$key] = $r;
            $missing = array_merge($missing, $r['missing']);
            $values[$key] = $r['value'] === null ? null : ($type === 'int' ? (int) $r['value'] : MoneyHelper::two($r['value']));
        }
        return ['values' => $values, 'detail' => $detail, 'rule_version_id' => $resolved?->versionId, 'missing' => array_values(array_unique($missing)), 'company' => $company];
    }

    /** Days per leave year this employee earns for a balance leave type on a date. */
    public function entitlementDays(Employee $e, string $typeCode, string $on): string
    {
        $v = $this->effective($e, $typeCode, $on)['values'];
        $p = $this->profile($e);
        return LeaveEntitlement::annualDays([
            'base' => $v['entitlement_days'], 'increment_days' => $v['service_increment_days'], 'increment_years' => $v['service_increment_years'], 'cap' => $v['entitlement_cap_days'],
            'service_years' => $e->hired_on ? LeaveEntitlement::completedYears($e->hired_on->toDateString(), $on) : 0,
            'weekly_hours' => $p?->weekly_hours === null ? null : (string) $p->weekly_hours, 'full_time_hours' => $v['full_time_weekly_hours'],
        ]);
    }
}
