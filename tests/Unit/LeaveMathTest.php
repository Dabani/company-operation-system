<?php

namespace Tests\Unit;

use App\Services\Hr\Leave\LeaveCarryOver as CO;
use App\Services\Hr\Leave\LeaveChecker;
use App\Services\Hr\Leave\LeaveDays as D;
use App\Services\Hr\Leave\LeaveEntitlement as E;
use App\Services\Hr\Leave\LeavePolicy;
use App\Services\Hr\Leave\LeaveYear as Y;
use App\Services\Hr\Leave\LegalLeaveRules as L;
use PHPUnit\Framework\TestCase;

/**
 * Pure leave logic. The numbers below are TEST INPUTS only (an 18-day base, a day per three years, a cap...): they are not a statement of the law.
 * Real legal values are entered, verified and signed off by HR/legal inside COPS.
 */
class LeaveMathTest extends TestCase
{
    // ---- leave year ---------------------------------------------------------------------------------------------------------
    public function test_leave_year_follows_the_start_month(): void
    {
        $this->assertSame(['id' => 2026, 'start' => '2026-01-01', 'end' => '2026-12-31'], Y::of('2026-11-15', 1));
        $this->assertSame(['id' => 2025, 'start' => '2025-04-01', 'end' => '2026-03-31'], Y::of('2026-02-10', 4));
        $this->assertSame(['id' => 2026, 'start' => '2026-04-01', 'end' => '2027-03-31'], Y::of('2026-04-01', 4));
        $this->assertSame(1, Y::monthIndex('2026-04-15', 4));
        $this->assertSame(12, Y::monthIndex('2027-03-31', 4));
        $this->assertSame(11, Y::monthIndex('2026-11-02', 1));
        $this->assertSame('2026-02-28', Y::monthEnd('2026-02-10'));
    }

    // ---- counting days -------------------------------------------------------------------------------------------------------
    public function test_working_days_skip_weekends_and_public_holidays(): void
    {
        $working = D::weekdays('2026-11-02', '2026-11-08');                                   // Mon 2 ... Sun 8
        $this->assertSame('5.00', D::count('2026-11-02', '2026-11-08', $working, [])['days']);
        $this->assertSame('4.00', D::count('2026-11-02', '2026-11-08', $working, ['2026-11-04' => true])['days']);
        $this->assertSame('7.00', D::count('2026-11-02', '2026-11-08', $working, ['2026-11-04' => true], D::CALENDAR)['days']);
        $this->assertSame('0.00', D::count('2026-11-07', '2026-11-08', $working, [])['days']);   // a weekend is no leave
    }

    public function test_half_days_at_the_start_and_end(): void
    {
        $working = D::weekdays('2026-11-02', '2026-11-06');
        $r = D::count('2026-11-02', '2026-11-06', $working, [], D::WORKING, true, true);
        $this->assertSame('4.00', $r['days']);
        $this->assertSame('0.50', $r['dates']['2026-11-02']);
        $this->assertSame('1.00', $r['dates']['2026-11-03']);
        $this->assertSame('0.50', $r['dates']['2026-11-06']);
        $this->assertSame('0.50', D::count('2026-11-03', '2026-11-03', $working, [], D::WORKING, true, false)['days']);
    }

    public function test_a_holiday_on_a_half_day_edge_removes_that_date_only(): void
    {
        $working = D::weekdays('2026-11-02', '2026-11-04');
        $r = D::count('2026-11-02', '2026-11-04', $working, ['2026-11-02' => true], D::WORKING, true, false);
        $this->assertSame('2.00', $r['days']);
        $this->assertArrayNotHasKey('2026-11-02', $r['dates']);
    }

    public function test_range_overlap_is_inclusive(): void
    {
        $this->assertTrue(D::overlaps('2026-11-01', '2026-11-05', '2026-11-05', '2026-11-09'));
        $this->assertFalse(D::overlaps('2026-11-01', '2026-11-04', '2026-11-05', '2026-11-09'));
    }

    // ---- service, age, entitlement --------------------------------------------------------------------------------------------
    public function test_completed_years_months_and_age(): void
    {
        $this->assertSame(2, E::completedYears('2023-02-15', '2026-02-14'));
        $this->assertSame(3, E::completedYears('2023-02-15', '2026-02-15'));
        $this->assertSame(0, E::completedYears('2026-02-15', '2026-01-01'));       // not hired yet
        $this->assertSame(13, E::completedMonths('2025-01-10', '2026-03-09'));
        $this->assertSame(16, E::ageYears('2009-06-30', '2026-06-29'));       // the day before the 17th birthday
        $this->assertSame(17, E::ageYears('2009-06-30', '2026-06-30'));
    }

    private function ent(array $o = []): string
    {
        return E::annualDays($o + ['base' => '18.00', 'increment_days' => '1', 'increment_years' => 3, 'cap' => '21.00', 'service_years' => 0, 'weekly_hours' => null, 'full_time_hours' => null]);
    }

    public function test_entitlement_grows_with_service_up_to_the_cap(): void
    {
        $this->assertSame('18.00', $this->ent(['service_years' => 2]));
        $this->assertSame('19.00', $this->ent(['service_years' => 3]));
        $this->assertSame('20.00', $this->ent(['service_years' => 6]));
        $this->assertSame('21.00', $this->ent(['service_years' => 9]));
        $this->assertSame('21.00', $this->ent(['service_years' => 30]));            // capped
    }

    public function test_a_cap_below_the_base_never_cuts_the_base(): void
    {
        $this->assertSame('18.00', $this->ent(['cap' => '15.00', 'service_years' => 9]));
        $this->assertSame('0.00', $this->ent(['base' => null]));                      // no base, no entitlement
        $this->assertSame('18.00', $this->ent(['increment_days' => null, 'service_years' => 12]));
    }

    public function test_entitlement_is_pro_rated_below_full_time_hours(): void
    {
        $this->assertSame('16.00', $this->ent(['weekly_hours' => '40', 'full_time_hours' => '45']));
        $this->assertSame('18.00', $this->ent(['weekly_hours' => '45', 'full_time_hours' => '45']));
        $this->assertSame('18.00', $this->ent(['weekly_hours' => '48', 'full_time_hours' => '45']));   // never above full time
        $this->assertSame('18.00', $this->ent(['weekly_hours' => null, 'full_time_hours' => '45']));   // unknown hours = full time
        $this->assertSame('9.00', $this->ent(['weekly_hours' => '22.5', 'full_time_hours' => '45']));
    }

    // ---- monthly accrual ---------------------------------------------------------------------------------------------------------
    private function accrueYear(string $annual): array
    {
        $acc = '0.00';
        $posted = [];
        for ($m = 1; $m <= 12; $m++) {
            $a = E::monthlyAccrual($annual, $m, $acc);
            $posted[] = $a;
            $acc = bcadd($acc, $a, 2);
        }
        return [$posted, $acc];
    }

    public function test_accrual_posts_a_twelfth_a_month_and_sums_exactly(): void
    {
        [$posted, $total] = $this->accrueYear('18.00');
        $this->assertSame(array_fill(0, 12, '1.50'), $posted);
        $this->assertSame('18.00', $total);
        [, $total19] = $this->accrueYear('19.00');                                    // 19/12 is not exact: the cents are corrected inside the year
        $this->assertSame('19.00', $total19);
        [, $total21] = $this->accrueYear('21.00');
        $this->assertSame('21.00', $total21);
    }

    public function test_accrual_never_claws_back_when_the_entitlement_falls(): void
    {
        $this->assertSame('0.00', E::monthlyAccrual('12.00', 6, '9.00'));            // target 6.00 < accrued 9.00
        $this->assertSame('1.00', E::monthlyAccrual('12.00', 7, '6.00'));
    }

    public function test_a_mid_year_hire_accrues_from_the_hire_month_only(): void
    {
        $this->assertSame(12, E::eligibleMonths(12, null));
        $this->assertSame(1, E::eligibleMonths(5, 5));
        $this->assertSame(8, E::eligibleMonths(12, 5));
        $this->assertSame(0, E::eligibleMonths(3, 5));                                // not hired yet
        $this->assertSame('1.50', E::monthlyAccrual('18.00', E::eligibleMonths(5, 5), '0.00'));
    }

    // ---- statutory limits over company policy -----------------------------------------------------------------------------------
    private function rules(): array
    {
        $r = fn (array $o) => $o + ['key' => 'entitlement_days', 'bound' => 'floor', 'value' => '18', 'valid_from' => '2026-01-01', 'valid_to' => null,
            'leave_type' => null, 'sex' => null, 'min_age' => null, 'max_age' => null, 'verified' => true];
        return [
            $r([]),
            $r(['value' => '24', 'min_age' => 16, 'max_age' => 18]),                    // eligibility by age
            $r(['value' => '30', 'verified' => false]),                                 // unverified: never applied
            $r(['value' => '98', 'leave_type' => 'maternity', 'sex' => 'female']),     // eligibility by sex
            $r(['key' => 'max_days_per_request', 'bound' => 'ceiling', 'value' => '40']),
            $r(['value' => '12', 'valid_from' => '2024-01-01', 'valid_to' => '2025-12-31']),   // ended last year
        ];
    }

    private function facts(array $o = []): array { return $o + ['leave_type' => 'annual', 'sex' => null, 'age' => 30]; }

    public function test_the_statute_raises_a_company_value_below_the_floor(): void
    {
        $r = L::apply('entitlement_days', '15.00', $this->rules(), $this->facts(), '2026-11-15');
        $this->assertSame('18', $r['value']);
        $this->assertTrue($r['adjusted']);
        $this->assertSame('statutory', $r['source']);
        $this->assertSame('15.00', $r['company']);
    }

    public function test_a_company_value_above_the_floor_is_kept(): void
    {
        $r = L::apply('entitlement_days', '20.00', $this->rules(), $this->facts(), '2026-11-15');
        $this->assertSame('20.00', $r['value']);
        $this->assertFalse($r['adjusted']);
        $this->assertSame('company', $r['source']);
    }

    public function test_with_no_company_policy_the_floor_is_the_entitlement(): void
    {
        $r = L::apply('entitlement_days', null, $this->rules(), $this->facts(), '2026-11-15');
        $this->assertSame('18', $r['value']);
        $this->assertSame('statutory', $r['source']);
        $none = L::apply('notice_days', null, $this->rules(), $this->facts(), '2026-11-15');
        $this->assertNull($none['value']);
        $this->assertSame('none', $none['source']);
    }

    public function test_the_highest_applicable_floor_wins_for_young_workers(): void
    {
        $r = L::apply('entitlement_days', '20.00', $this->rules(), $this->facts(['age' => 17]), '2026-11-15');
        $this->assertSame('24', $r['value']);
        $this->assertSame('24', $r['floor']);
        $adult = L::apply('entitlement_days', '20.00', $this->rules(), $this->facts(['age' => 19]), '2026-11-15');
        $this->assertSame('20.00', $adult['value']);
    }

    public function test_unverified_and_expired_rules_are_never_applied(): void
    {
        $r = L::apply('entitlement_days', '10.00', $this->rules(), $this->facts(['age' => 40]), '2026-11-15');
        $this->assertSame('18', $r['floor']);                                          // not 30 (unverified) and not 12 (expired)
        $earlier = L::apply('entitlement_days', '10.00', $this->rules(), $this->facts(), '2025-06-01');   // the old value applied then, the new one did not exist yet
        $this->assertSame('12', $earlier['floor']);
    }

    public function test_a_rule_that_needs_a_missing_profile_fact_is_reported_not_guessed(): void
    {
        $r = L::apply('entitlement_days', '50.00', $this->rules(), $this->facts(['leave_type' => 'maternity', 'sex' => null]), '2026-11-15');
        $this->assertSame(['sex'], $r['missing']);
        $this->assertSame('50.00', $r['value']);                                      // the sex-restricted floor is not assumed
        $f = L::apply('entitlement_days', '50.00', $this->rules(), $this->facts(['leave_type' => 'maternity', 'sex' => 'female']), '2026-11-15');
        $this->assertSame('98', $f['value']);
        $m = L::apply('entitlement_days', '50.00', $this->rules(), $this->facts(['leave_type' => 'maternity', 'sex' => 'male']), '2026-11-15');
        $this->assertSame('50.00', $m['value']);
        $this->assertSame([], $m['missing']);
        $age = L::apply('entitlement_days', '10.00', $this->rules(), $this->facts(['age' => null]), '2026-11-15');
        $this->assertSame(['date_of_birth'], $age['missing']);
    }

    public function test_a_ceiling_limits_the_company_value(): void
    {
        $r = L::apply('max_days_per_request', '60.00', $this->rules(), $this->facts(), '2026-11-15');
        $this->assertSame('40', $r['value']);
        $this->assertTrue($r['adjusted']);
        $this->assertSame('40', $r['ceiling']);
        $this->assertNull(L::apply('max_days_per_request', null, $this->rules(), $this->facts(), '2026-11-15')['value']);   // a ceiling alone creates no value
    }

    public function test_the_policy_audit_flags_values_that_break_a_limit(): void
    {
        $this->assertSame('below_floor', L::violation('entitlement_days', '15.00', $this->rules(), $this->facts(), '2026-11-15'));
        $this->assertNull(L::violation('entitlement_days', '18.00', $this->rules(), $this->facts(), '2026-11-15'));
        $this->assertSame('above_ceiling', L::violation('max_days_per_request', '41.00', $this->rules(), $this->facts(), '2026-11-15'));
        $this->assertNull(L::violation('entitlement_days', null, $this->rules(), $this->facts(), '2026-11-15'));
    }

    // ---- carry-over ---------------------------------------------------------------------------------------------------------------
    public function test_year_end_forfeits_only_above_the_limit(): void
    {
        $this->assertSame('2.50', CO::forfeitAtYearEnd('12.50', '10.00'));
        $this->assertSame('0.00', CO::forfeitAtYearEnd('9.00', '10.00'));
        $this->assertSame('0.00', CO::forfeitAtYearEnd('40.00', null));              // no limit in the policy: nothing is lost
        $this->assertSame('0.00', CO::forfeitAtYearEnd('-2.00', '10.00'));
    }

    public function test_carried_over_days_expire_only_when_unused(): void
    {
        $this->assertSame('5.00', CO::forfeitAtExpiry('8.00', '3.00', '20.00'));
        $this->assertSame('0.00', CO::forfeitAtExpiry('8.00', '9.00', '20.00'));      // used them all first
        $this->assertSame('2.00', CO::forfeitAtExpiry('8.00', '0.00', '2.00'));       // cannot forfeit more than the balance
        $this->assertSame('0.00', CO::forfeitAtExpiry('8.00', '0.00', '-1.00'));
    }

    // ---- policy catalogue -----------------------------------------------------------------------------------------------------------
    public function test_policy_outcomes_are_typed_and_missing_keys_stay_null(): void
    {
        $p = LeavePolicy::fromOutcomes(['entitlement_days' => '18', 'notice_days' => '5', 'allow_negative_balance' => true, 'max_days_per_request' => 10, 'bogus' => 'x']);
        $this->assertSame('18.00', $p['entitlement_days']);
        $this->assertSame(5, $p['notice_days']);
        $this->assertTrue($p['allow_negative_balance']);
        $this->assertSame('10.00', $p['max_days_per_request']);
        $this->assertNull($p['carry_over_max_days']);
        $this->assertArrayNotHasKey('bogus', $p);
        $this->assertSame(count(LeavePolicy::KEYS), count($p));
    }

    // ---- request checker -------------------------------------------------------------------------------------------------------------
    private function req(array $o = []): array
    {
        return $o + ['start' => '2026-12-07', 'end' => '2026-12-11', 'days' => '5.00', 'today' => '2026-11-15', 'privileged' => false, 'hired_on' => '2026-01-05', 'ended_on' => null,
            'uses_balance' => true, 'ignores_blackout' => false, 'allow_retroactive' => false, 'requires_document' => false,
            'policy' => LeavePolicy::fromOutcomes([]), 'balance_available' => '10.00', 'taken_this_year' => '0.00', 'service_months' => 11,
            'overlap' => false, 'blackout' => null, 'validated_month' => false, 'team_peak' => 0];
    }

    private function codes(array $r, string $list = 'blockers'): array { return array_column($r[$list], 'code'); }

    public function test_a_clean_request_has_no_blockers_and_no_warnings(): void
    {
        $r = LeaveChecker::check($this->req());
        $this->assertSame([], $r['blockers']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_balance_and_negative_balance(): void
    {
        $this->assertSame(['insufficient_balance'], $this->codes(LeaveChecker::check($this->req(['balance_available' => '3.00']))));
        $policy = LeavePolicy::fromOutcomes(['allow_negative_balance' => true]);
        $this->assertSame([], $this->codes(LeaveChecker::check($this->req(['balance_available' => '3.00', 'policy' => $policy]))));
        $this->assertSame([], $this->codes(LeaveChecker::check($this->req(['uses_balance' => false, 'balance_available' => null]))));   // event leave has no balance
    }

    public function test_notice_retroactive_and_hr_privilege(): void
    {
        $policy = LeavePolicy::fromOutcomes(['notice_days' => 30]);
        $this->assertSame(['notice'], $this->codes(LeaveChecker::check($this->req(['policy' => $policy]))));                   // 22 days of notice
        $this->assertSame([], $this->codes(LeaveChecker::check($this->req(['policy' => $policy, 'privileged' => true]))));
        $past = $this->req(['start' => '2026-11-09', 'end' => '2026-11-10', 'days' => '2.00']);
        $this->assertSame(['retroactive'], $this->codes(LeaveChecker::check($past)));
        $this->assertSame([], $this->codes(LeaveChecker::check(['allow_retroactive' => true] + $past)));
    }

    public function test_length_limits(): void
    {
        $p = LeavePolicy::fromOutcomes(['max_days_per_request' => 4, 'min_days_per_request' => 2, 'max_days_per_year' => 8]);
        $this->assertSame(['max_per_request'], $this->codes(LeaveChecker::check($this->req(['policy' => $p]))));
        $this->assertSame(['min_per_request'], $this->codes(LeaveChecker::check($this->req(['policy' => $p, 'days' => '1.00', 'end' => '2026-12-07']))));
        $this->assertSame(['max_per_year'], $this->codes(LeaveChecker::check($this->req(['policy' => $p, 'days' => '3.00', 'taken_this_year' => '6.00']))));
    }

    public function test_blackout_is_blocking_except_for_exempt_types_and_hr(): void
    {
        $this->assertSame(['blackout'], $this->codes(LeaveChecker::check($this->req(['blackout' => 'Year end']))));
        $this->assertSame([], $this->codes(LeaveChecker::check($this->req(['blackout' => 'Year end', 'ignores_blackout' => true]))));
        $this->assertSame([], $this->codes(LeaveChecker::check($this->req(['blackout' => 'Year end', 'privileged' => true]))));
    }

    public function test_service_dates_overlap_and_locked_month(): void
    {
        $p = LeavePolicy::fromOutcomes(['min_service_months_to_take' => 12]);
        $r = LeaveChecker::check($this->req(['policy' => $p, 'overlap' => true, 'validated_month' => true, 'hired_on' => '2026-12-09', 'ended_on' => '2026-12-09']));
        foreach (['min_service', 'overlap', 'period_validated', 'before_hired', 'after_ended'] as $code) {
            $this->assertContains($code, $this->codes($r));
        }
        $this->assertContains('dates_inverted', $this->codes(LeaveChecker::check($this->req(['start' => '2026-12-11', 'end' => '2026-12-07']))));
        $this->assertContains('no_days', $this->codes(LeaveChecker::check($this->req(['days' => '0.00']))));
    }

    public function test_warnings_for_team_conflict_and_missing_document(): void
    {
        $p = LeavePolicy::fromOutcomes(['max_simultaneous_absences' => 2, 'requires_document_after_days' => 2]);
        $r = LeaveChecker::check($this->req(['policy' => $p, 'team_peak' => 2]));
        $this->assertSame([], $r['blockers']);
        $this->assertSame(['team_conflict', 'document_required'], $this->codes($r, 'warnings'));
        $this->assertSame(['team_conflict'], $this->codes(LeaveChecker::check($this->req(['policy' => $p, 'team_peak' => 2, 'days' => '1.00'])), 'warnings'));
        $this->assertSame([], $this->codes(LeaveChecker::check($this->req(['policy' => $p, 'team_peak' => 1, 'days' => '1.00'])), 'warnings'));
        $this->assertSame(['document_required'], $this->codes(LeaveChecker::check($this->req(['requires_document' => true])), 'warnings'));
    }
}
