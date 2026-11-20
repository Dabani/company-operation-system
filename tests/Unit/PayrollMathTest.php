<?php

namespace Tests\Unit;

use App\Services\Hr\Payroll\BracketValidator as BV;
use App\Services\Hr\Payroll\PayRound;
use App\Services\Hr\Payroll\PayrollEngine as E;
use PHPUnit\Framework\TestCase;

/** Pure payroll logic. Every rate and amount is a TEST INPUT (invented round numbers), never a statement of Rwandan law or of any statutory rate. */
class PayrollMathTest extends TestCase
{
    public function test_rounding_modes_to_the_franc(): void
    {
        $this->assertSame('3', PayRound::apply('2.5', 'nearest', 0));          // half away from zero
        $this->assertSame('2', PayRound::apply('2.4999', 'nearest', 0));
        $this->assertSame('-3', PayRound::apply('-2.5', 'nearest', 0));
        $this->assertSame('2', PayRound::apply('2.9999', 'down', 0));
        $this->assertSame('3', PayRound::apply('2.0001', 'up', 0));
        $this->assertSame('2', PayRound::apply('2.0000', 'up', 0));            // exact stays exact
        $this->assertSame('-2', PayRound::apply('-2.9', 'down', 0));           // toward zero
        $this->assertSame('-3', PayRound::apply('-2.1', 'up', 0));             // away from zero
        $this->assertSame('0', PayRound::apply('-0.4', 'nearest', 0));         // no negative zero
        $this->assertSame('1.24', PayRound::apply('1.235', 'nearest', 2));
        $this->assertSame('1.23', PayRound::apply('1.239', 'down', 2));
        $this->assertSame('1.24', PayRound::apply('1.231', 'up', 2));
        $this->assertSame('0.00', PayRound::apply('0', 'nearest', 2));
    }

    private function flat(array $o = []): array
    {
        return $o + ['code' => 'S', 'type' => 'other', 'order' => 1, 'mode' => 'flat', 'base_kind' => 'pensionable_earnings', 'employee_rate' => '7.5', 'employer_rate' => '0', 'base_cap' => null,
            'reduces_taxable_base' => false, 'rounding' => 'nearest', 'decimals' => 0, 'version_id' => 'v'];
    }

    private function earn(string $code, string $amount, bool $taxable, bool $pensionable): array { return ['code' => $code, 'amount' => $amount, 'taxable' => $taxable, 'pensionable' => $pensionable]; }

    private function line(array $r, string $kind, string $code): array
    {
        foreach ($r['lines'] as $l) {
            if ($l['kind'] === $kind && $l['code'] === $code) {
                return $l;
            }
        }
        $this->fail("no $kind line $code");
    }

    public function test_flat_rate_rounding_depends_on_the_configured_mode(): void
    {
        $in = fn (string $mode) => ['earnings' => [$this->earn('basic', '123457.00', true, true)], 'schemes' => [$this->flat(['rounding' => $mode])]];   // 7.5 % of 123457 = 9259.275
        $this->assertSame('9259', $this->line(E::calculate($in('nearest')), 'scheme_employee', 'S')['amount']);
        $this->assertSame('9259', $this->line(E::calculate($in('down')), 'scheme_employee', 'S')['amount']);
        $this->assertSame('9260', $this->line(E::calculate($in('up')), 'scheme_employee', 'S')['amount']);
    }

    public function test_the_base_follows_the_flags_of_each_earning(): void
    {
        $earn = [$this->earn('basic', '300000.00', true, true), $this->earn('transport', '50000.00', false, false), $this->earn('bonus', '100000.00', true, false)];
        $by = fn (string $kind) => $this->line(E::calculate(['earnings' => $earn, 'schemes' => [$this->flat(['base_kind' => $kind, 'employee_rate' => '10'])]]), 'scheme_employee', 'S');
        $this->assertSame('30000', $by('pensionable_earnings')['amount']);       // basic only
        $this->assertSame('40000', $by('taxable_earnings')['amount']);           // basic + bonus
        $this->assertSame('45000', $by('gross_earnings')['amount']);             // everything
    }

    public function test_a_cap_limits_the_base_and_no_cap_means_no_limit(): void
    {
        $in = fn (?string $cap) => ['earnings' => [$this->earn('basic', '900000.00', true, true)], 'schemes' => [$this->flat(['employee_rate' => '10', 'base_cap' => $cap])]];
        $this->assertSame('50000', $this->line(E::calculate($in('500000')), 'scheme_employee', 'S')['amount']);
        $this->assertSame('90000', $this->line(E::calculate($in(null)), 'scheme_employee', 'S')['amount']);
        $this->assertSame('500000.00', $this->line(E::calculate($in('500000')), 'scheme_employee', 'S')['base']);
    }

    private function bands(): array { return [['lower' => '0', 'upper' => '100000', 'rate' => '0'], ['lower' => '100000', 'upper' => '200000', 'rate' => '10'], ['lower' => '200000', 'upper' => null, 'rate' => '25']]; }
    private function tax(array $o = []): array { return $o + ['code' => 'T', 'type' => 'paye', 'order' => 1, 'mode' => 'brackets', 'base_kind' => 'taxable_earnings', 'brackets' => $this->bands(), 'rounding' => 'nearest', 'decimals' => 0, 'version_id' => 'v']; }

    public function test_progressive_bands_tax_only_the_part_inside_each_band(): void
    {
        $at = fn (string $amount) => E::calculate(['earnings' => [$this->earn('basic', $amount, true, false)], 'schemes' => [$this->tax()]]);
        $this->assertSame('0', $this->line($at('100000.00'), 'scheme_employee', 'T')['amount']);          // exactly at the first limit
        $this->assertSame('1000', $this->line($at('110000.00'), 'scheme_employee', 'T')['amount']);
        $this->assertSame('10000', $this->line($at('200000.00'), 'scheme_employee', 'T')['amount']);
        $this->assertSame('80000', $this->line($at('480000.00'), 'scheme_employee', 'T')['amount']);      // 10000 + 25 % x 280000
        $this->assertSame('0', $this->line($at('0.00'), 'scheme_employee', 'T')['amount']);
        $this->assertCount(3, $this->line($at('480000.00'), 'scheme_employee', 'T')['bands']);             // the payslip can show every band
    }

    public function test_bands_given_out_of_order_give_the_same_result(): void
    {
        $shuffled = array_reverse($this->bands());
        $r = E::calculate(['earnings' => [$this->earn('basic', '480000.00', true, false)], 'schemes' => [$this->tax(['brackets' => $shuffled])]]);
        $this->assertSame('80000', $this->line($r, 'scheme_employee', 'T')['amount']);
    }

    public function test_rounding_once_or_per_band_can_differ_and_is_a_setting(): void
    {
        $bands = [['lower' => '0', 'upper' => '10', 'rate' => '15'], ['lower' => '10', 'upper' => null, 'rate' => '15']];   // 1.5 on band 1, 0.75 on 5 of band 2
        $in = fn (bool $each) => ['earnings' => [$this->earn('basic', '15.00', true, false)], 'schemes' => [$this->tax(['brackets' => $bands, 'round_each_band' => $each])]];
        $this->assertSame('2', $this->line(E::calculate($in(false)), 'scheme_employee', 'T')['amount']);   // 1.5 + 0.75 = 2.25 -> 2
        $this->assertSame('3', $this->line(E::calculate($in(true)), 'scheme_employee', 'T')['amount']);    // 2 + 1 = 3
    }

    public function test_a_contribution_that_reduces_the_taxable_base_runs_before_the_tax_that_depends_on_it(): void
    {
        $pension = $this->flat(['code' => 'P', 'order' => 1, 'employee_rate' => '5', 'reduces_taxable_base' => true]);
        $tax = $this->tax(['order' => 2, 'base_kind' => 'taxable_after_deductions']);
        $earn = [$this->earn('basic', '400000.00', true, true), $this->earn('allowance', '100000.00', true, false)];
        $r = E::calculate(['earnings' => $earn, 'schemes' => [$tax, $pension]]);                 // deliberately listed in the wrong order
        $this->assertSame('20000', $this->line($r, 'scheme_employee', 'P')['amount']);
        $this->assertSame('480000.00', $this->line($r, 'scheme_employee', 'T')['base']);
        $this->assertSame('80000', $this->line($r, 'scheme_employee', 'T')['amount']);
        $notReducing = E::calculate(['earnings' => $earn, 'schemes' => [$tax, $this->flat(['code' => 'P', 'order' => 1, 'employee_rate' => '5', 'reduces_taxable_base' => false])]]);
        $this->assertSame('500000.00', $this->line($notReducing, 'scheme_employee', 'T')['base']);   // the flag is what decides, nothing is assumed
    }

    public function test_totals_net_and_employer_cost(): void
    {
        $r = E::calculate(['earnings' => [$this->earn('basic', '500000.00', true, true)], 'deductions' => [['code' => 'loan', 'amount' => '25000.50']],
            'schemes' => [$this->flat(['employee_rate' => '6', 'employer_rate' => '8'])]]);
        $this->assertSame('500000.00', $r['totals']['gross']);
        $this->assertSame('30000.00', $r['totals']['employee_contributions']);
        $this->assertSame('25000.50', $r['totals']['deductions']);
        $this->assertSame('444999.50', $r['totals']['net']);
        $this->assertSame('40000.00', $r['totals']['employer_contributions']);
        $this->assertSame('540000.00', $r['totals']['employer_cost']);
        $this->assertSame([], $r['warnings']);
    }

    public function test_every_line_carries_what_it_was_computed_from(): void
    {
        $r = E::calculate(['earnings' => [$this->earn('basic', '100000.00', true, true)], 'schemes' => [$this->flat(['version_id' => 'ver-9', 'employee_rate' => '4'])]]);
        $l = $this->line($r, 'scheme_employee', 'S');
        $this->assertSame('ver-9', $l['version_id']);
        $this->assertSame('4', $l['rate']);
        $this->assertSame('100000.00', $l['base']);
        $this->assertSame('pensionable_earnings', $l['base_kind']);
        $this->assertSame('nearest', $l['rounding']);
    }

    public function test_negative_net_is_flagged_not_hidden(): void
    {
        $r = E::calculate(['earnings' => [$this->earn('basic', '10000.00', true, true)], 'deductions' => [['code' => 'loan', 'amount' => '20000']], 'schemes' => []]);
        $this->assertSame('-10000.00', $r['totals']['net']);
        $this->assertSame(['negative_net'], $r['warnings']);
    }

    public function test_no_scheme_means_no_contribution_nothing_is_assumed(): void
    {
        $r = E::calculate(['earnings' => [$this->earn('basic', '300000.00', true, true)], 'schemes' => []]);
        $this->assertSame('0.00', $r['totals']['employee_contributions']);
        $this->assertSame('300000.00', $r['totals']['net']);
        $this->assertCount(1, $r['lines']);
    }

    public function test_the_calculation_is_repeatable(): void
    {
        $in = ['earnings' => [$this->earn('basic', '777777.77', true, true)], 'schemes' => [$this->tax(), $this->flat(['order' => 0])]];
        $this->assertSame(E::calculate($in), E::calculate($in));
    }
    public function test_bracket_validation_refuses_gaps_overlaps_and_open_ends(): void
    {
        $b = fn (array $rows) => array_map(fn ($r) => ['lower' => $r[0], 'upper' => $r[1], 'rate' => $r[2]], $rows);
        $this->assertNull(BV::error($b([['0', '100000', '0'], ['100000', '200000', '10'], ['200000', null, '25']])));
        $this->assertNull(BV::error($b([['0', null, '30']])));                                                  // a single open band
        $this->assertNull(BV::error($b([['100000', null, '20'], ['0', '100000', '0']])));                      // order does not matter
        $this->assertSame('empty', BV::error([]));
        $this->assertSame('first_not_zero', BV::error($b([['50000', null, '10']])));
        $this->assertSame('gap_or_overlap', BV::error($b([['0', '100000', '0'], ['120000', null, '10']])));     // 100000-120000 would be untaxed
        $this->assertSame('gap_or_overlap', BV::error($b([['0', '100000', '0'], ['90000', null, '10']])));      // 90000-100000 would be taxed twice
        $this->assertSame('last_not_open', BV::error($b([['0', '100000', '0'], ['100000', '200000', '10']])));
        $this->assertSame('open_band_not_last', BV::error($b([['0', null, '0'], ['100000', null, '10']])));
        $this->assertSame('bad_bounds', BV::error($b([['0', '0', '5'], ['0', null, '5']])));
        $this->assertSame('bad_value', BV::error($b([['0', null, '101']])));
        $this->assertSame('bad_value', BV::error($b([['0', null, '-1']])));
    }
}
