<?php

namespace Tests\Unit;

use App\Services\Hr\Payroll\PayRunFlow as F;
use App\Services\Hr\Payroll\Proration as P;
use PHPUnit\Framework\TestCase;

/** Pure run-lifecycle and proration logic. Dates and rules are test inputs: the real proration rule is the accountant's decision. */
class PayrollRunLogicTest extends TestCase
{
    // ---- proration -----------------------------------------------------------------------------------------------------------
    public function test_no_proration_always_gives_the_whole_amount_but_still_says_whether_the_person_was_employed(): void
    {
        $r = P::factor('2026-11-01', '2026-11-30', '2026-11-16', null, P::NONE);
        $this->assertSame('1.000000', $r['factor']);
        $this->assertTrue($r['employed']);
        $this->assertTrue(P::isPartial('2026-11-01', '2026-11-30', '2026-11-16', null));
        $this->assertFalse(P::isPartial('2026-11-01', '2026-11-30', '2025-01-01', null));
        $this->assertTrue(P::isPartial('2026-11-01', '2026-11-30', '2025-01-01', '2026-11-20'));
    }

    public function test_calendar_days(): void
    {
        $r = P::factor('2026-11-01', '2026-11-30', '2026-11-16', null, P::CALENDAR);           // 16..30 = 15 of 30 days
        $this->assertSame(['factor' => '0.500000', 'worked' => 15, 'total' => 30, 'employed' => true], $r);
        $this->assertSame('1.000000', P::factor('2026-11-01', '2026-11-30', '2025-01-01', null, P::CALENDAR)['factor']);
        $this->assertSame('0.333333', P::factor('2026-11-01', '2026-11-30', '2025-01-01', '2026-11-10', P::CALENDAR)['factor']);   // 10 of 30
        $this->assertSame(['factor' => '0.033333', 'worked' => 1, 'total' => 30, 'employed' => true], P::factor('2026-11-01', '2026-11-30', '2026-11-30', null, P::CALENDAR));
    }

    public function test_working_days_skip_weekends_and_holidays(): void
    {
        // November 2026: 21 working days (Mon-Fri). Start Mon 16th: 16-20 (5), 23-27 (5), 30 (1) = 11.
        $r = P::factor('2026-11-01', '2026-11-30', '2026-11-16', null, P::WORKING);
        $this->assertSame(11, $r['worked']);
        $this->assertSame(21, $r['total']);
        $this->assertSame('0.523809', $r['factor']);
        $h = P::factor('2026-11-01', '2026-11-30', '2026-11-16', null, P::WORKING, ['2026-11-18' => true]);   // a holiday leaves both counts
        $this->assertSame([10, 20], [$h['worked'], $h['total']]);
        $this->assertSame('0.500000', $h['factor']);
    }

    public function test_someone_not_employed_in_the_period_gets_nothing_and_is_not_employed(): void
    {
        $this->assertSame(['factor' => '0.000000', 'worked' => 0, 'total' => 30, 'employed' => false], P::factor('2026-11-01', '2026-11-30', '2026-12-01', null, P::CALENDAR));
        $this->assertFalse(P::factor('2026-11-01', '2026-11-30', '2025-01-01', '2026-10-31', P::NONE)['employed']);   // left before the period
        $this->assertTrue(P::factor('2026-11-01', '2026-11-30', '2025-01-01', '2026-11-01', P::NONE)['employed']);    // left on the first day: still employed that day
    }

    // ---- lifecycle ---------------------------------------------------------------------------------------------------------------
    public function test_the_lifecycle_only_moves_forward_through_the_defined_steps(): void
    {
        $this->assertTrue(F::can('draft', 'calculate'));
        $this->assertTrue(F::can('calculated', 'calculate'));                         // recalculating before review is allowed
        $this->assertFalse(F::can('reviewed', 'calculate'));
        $this->assertFalse(F::can('draft', 'review'));                                // nothing is reviewed before it is calculated
        $this->assertTrue(F::can('calculated', 'review'));
        $this->assertFalse(F::can('calculated', 'approve_finance'));
        $this->assertTrue(F::can('reviewed', 'approve_finance'));
        $this->assertFalse(F::can('approved', 'approve_finance'));
        $this->assertFalse(F::can('reviewed', 'lock'));
        $this->assertTrue(F::can('approved', 'lock'));
        $this->assertTrue(F::can('locked', 'pay'));
        $this->assertFalse(F::can('approved', 'pay'));
        $this->assertTrue(F::can('paid', 'declare'));
        $this->assertFalse(F::can('locked', 'declare'));
    }

    public function test_a_locked_run_can_never_go_back_or_be_cancelled(): void
    {
        foreach (['locked', 'paid', 'declared'] as $s) {
            $this->assertTrue(F::frozen($s));
            $this->assertFalse(F::editable($s));
            $this->assertFalse(F::can($s, 'return'), "$s return");
            $this->assertFalse(F::can($s, 'cancel'), "$s cancel");
            $this->assertFalse(F::can($s, 'calculate'), "$s calculate");
        }
        $this->assertFalse(F::frozen('approved'));
        $this->assertFalse(F::editable('reviewed'));                                   // inputs freeze once review starts
        $this->assertTrue(F::editable('draft'));
        $this->assertTrue(F::can('reviewed', 'return'));
        $this->assertTrue(F::can('approved', 'return'));
        $this->assertFalse(F::can('declared', 'pay'));
    }

    public function test_approval_steps_finance_first_then_the_managing_director_when_required(): void
    {
        $this->assertSame(['status' => 'reviewed', 'error' => null], F::afterApproval('approve_finance', true, false, false));    // MD still to come
        $this->assertSame(['status' => 'approved', 'error' => null], F::afterApproval('approve_finance', false, false, false));   // no MD needed
        $this->assertSame(['status' => 'approved', 'error' => null], F::afterApproval('approve_md', true, true, false));
        $this->assertSame('finance_first', F::afterApproval('approve_md', true, false, false)['error']);
        $this->assertSame('md_not_required', F::afterApproval('approve_md', false, true, false)['error']);
        $this->assertSame('already_done', F::afterApproval('approve_finance', true, true, false)['error']);
    }

    public function test_segregation_of_duties(): void
    {
        $people = ['prepared' => 'p', 'calculated' => 'c', 'reviewed' => 'r', 'finance' => 'f'];
        $this->assertSame('segregation', F::conflict('review', 'p', $people));
        $this->assertSame('segregation', F::conflict('review', 'c', $people));                      // whoever pressed "calculate" counts as a preparer
        $this->assertNull(F::conflict('review', 'r', $people));
        $this->assertSame('segregation', F::conflict('approve_finance', 'r', $people));              // the reviewer does not approve
        $this->assertSame('segregation', F::conflict('approve_finance', 'p', $people));
        $this->assertNull(F::conflict('approve_finance', 'f', $people));
        $this->assertSame('segregation', F::conflict('approve_md', 'f', $people));                   // the MD is not the finance approver
        $this->assertSame('segregation', F::conflict('approve_md', 'r', $people));
        $this->assertSame('segregation', F::conflict('approve_md', 'p', $people));
        $this->assertNull(F::conflict('approve_md', 'md', $people));
        $this->assertSame('segregation', F::conflict('lock', 'p', $people));                         // the preparer does not release the money
        $this->assertSame('segregation', F::conflict('pay', 'c', $people));
        $this->assertNull(F::conflict('pay', 'f', $people));
        $this->assertNull(F::conflict('declare', 'p', $people));
        $this->assertNull(F::conflict('review', 'x', ['prepared' => 'p']));                          // missing parties are simply not conflicts
    }
}
