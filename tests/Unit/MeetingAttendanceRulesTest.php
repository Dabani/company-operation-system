<?php

namespace Tests\Unit;

use App\Services\Meetings\AttendanceRules as R;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** COPS Phase 7b: pure attendance rules. */
class MeetingAttendanceRulesTest extends TestCase
{
    public function test_attendance_opens_when_the_meeting_starts_and_changes_after_the_end_are_corrections(): void
    {
        foreach (['draft', 'scheduled', 'cancelled'] as $s) {
            $this->assertFalse(R::isOpen($s), $s);
        }
        foreach (['in_progress', 'held', 'closed'] as $s) {
            $this->assertTrue(R::isOpen($s), $s);
        }
        $this->assertFalse(R::isCorrection('in_progress'));
        $this->assertTrue(R::isCorrection('held'));
        $this->assertTrue(R::isCorrection('closed'));
    }

    public function test_arriving_within_the_grace_period_is_present_and_after_it_is_late(): void
    {
        $start = CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC');
        $this->assertSame('present', R::classify($start->subMinutes(5), $start, 10));
        $this->assertSame('present', R::classify($start->addMinutes(10), $start, 10));
        $this->assertSame('late', R::classify($start->addMinutes(11), $start, 10));
        $this->assertSame('late', R::classify($start->addMinute(), $start, 0));
    }

    public function test_only_present_late_and_online_count_as_attended(): void
    {
        foreach (['present', 'late', 'online'] as $s) {
            $this->assertTrue(R::attended($s));
        }
        foreach (['expected', 'absent', 'excused', 'nonsense'] as $s) {
            $this->assertFalse(R::attended($s));
        }
    }

    public function test_summary_counts_and_leaves_excused_people_out_of_the_rate(): void
    {
        $sum = R::summary(['present', 'present', 'late', 'online', 'absent', 'excused', 'expected', 'bogus']);
        $this->assertSame(7, $sum['total']);
        $this->assertSame(4, $sum['attended']);
        $this->assertSame(66.7, $sum['rate']);                  // 4 of the 6 who were not excused
        $this->assertNull(R::summary([])['rate']);
        $this->assertNull(R::summary(['excused'])['rate']);
    }
}
