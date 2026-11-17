<?php

namespace Tests\Unit;

use App\Services\Events\EventRules as R;
use PHPUnit\Framework\TestCase;

/** COPS Phase 7e: pure event rules. */
class EventRulesTest extends TestCase
{
    public function test_lifecycle(): void
    {
        $this->assertTrue(R::allowed('draft', 'open'));
        $this->assertTrue(R::allowed('open', 'closed'));
        $this->assertTrue(R::allowed('closed', 'open'));
        $this->assertTrue(R::allowed('open', 'in_progress'));
        $this->assertTrue(R::allowed('in_progress', 'completed'));
        $this->assertFalse(R::allowed('draft', 'in_progress'));
        $this->assertFalse(R::allowed('in_progress', 'cancelled'));
        $this->assertFalse(R::allowed('completed', 'open'));
        $this->assertFalse(R::allowed('cancelled', 'open'));
    }

    public function test_editing_stops_when_the_event_starts(): void
    {
        $this->assertTrue(R::editable('open'));
        $this->assertFalse(R::editable('in_progress'));
        $this->assertFalse(R::editable('completed'));
    }

    public function test_registration_window(): void
    {
        $now = 1000;
        $this->assertTrue(R::registrationOpen('open', $now, null, null));
        $this->assertTrue(R::registrationOpen('open', $now, 900, 1100));
        $this->assertFalse(R::registrationOpen('open', $now, 1001, null), 'not open yet');
        $this->assertFalse(R::registrationOpen('open', $now, null, 1000), 'closing time itself is closed');
        $this->assertFalse(R::registrationOpen('closed', $now, null, null));
        $this->assertFalse(R::registrationOpen('draft', $now, null, null));
    }

    public function test_capacity_and_waitlist(): void
    {
        $this->assertSame('registered', R::seat(null, 5000, true));
        $this->assertSame('registered', R::seat(10, 9, false));
        $this->assertSame('waitlisted', R::seat(10, 10, true));
        $this->assertSame('full', R::seat(10, 10, false));
        $this->assertSame(0, R::freeSeats(10, 12));
        $this->assertSame(3, R::freeSeats(10, 7));
        $this->assertSame(PHP_INT_MAX, R::freeSeats(null, 7));
    }

    public function test_check_in_only_while_running_or_corrected_by_the_organizer(): void
    {
        $this->assertTrue(R::canCheckIn('in_progress', false));
        $this->assertFalse(R::canCheckIn('open', true));
        $this->assertFalse(R::canCheckIn('completed', false));
        $this->assertTrue(R::canCheckIn('completed', true));
    }

    public function test_tickets_and_figures(): void
    {
        $this->assertTrue(R::ticketValid(str_repeat('a1', 24)));
        $this->assertFalse(R::ticketValid('short'));
        $this->assertFalse(R::ticketValid(str_repeat('a', 47) . '/'));
        $this->assertSame(75, R::attendanceRate(8, 6));
        $this->assertSame(0, R::attendanceRate(0, 0));
        $this->assertSame(['b', 'c'], R::noShows(['a', 'b', 'c'], ['a']));
    }
}
