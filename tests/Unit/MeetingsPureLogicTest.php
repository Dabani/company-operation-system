<?php

namespace Tests\Unit;

use App\Services\Meetings\IcsBuilder;
use App\Services\Meetings\MeetingReminders;
use App\Services\Meetings\MeetingWorkflow as W;
use App\Services\Meetings\RecurrenceExpander as R;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** COPS Phase 7a: pure meeting logic (no database). */
class MeetingsPureLogicTest extends TestCase
{
    public function test_lifecycle_allows_only_the_documented_moves(): void
    {
        $this->assertTrue(W::allowed('draft', 'scheduled'));
        $this->assertTrue(W::allowed('scheduled', 'in_progress'));
        $this->assertTrue(W::allowed('scheduled', 'held'));
        $this->assertTrue(W::allowed('held', 'closed'));
        $this->assertFalse(W::allowed('draft', 'held'));
        $this->assertFalse(W::allowed('in_progress', 'cancelled'));
        $this->assertFalse(W::allowed('closed', 'draft'));
        $this->assertFalse(W::allowed('cancelled', 'scheduled'));
        $this->assertFalse(W::allowed('unknown', 'draft'));
    }

    public function test_only_draft_and_scheduled_are_editable_and_cancelled_frees_the_calendar(): void
    {
        $this->assertTrue(W::editable('draft'));
        $this->assertTrue(W::editable('scheduled'));
        $this->assertFalse(W::editable('in_progress'));
        $this->assertTrue(W::occupiesCalendar('in_progress'));
        $this->assertFalse(W::occupiesCalendar('cancelled'));
        $this->assertFalse(W::occupiesCalendar('held'));
    }

    public function test_back_to_back_is_not_an_overlap(): void
    {
        $this->assertFalse(W::overlaps(100, 200, 200, 300));
        $this->assertFalse(W::overlaps(200, 300, 100, 200));
        $this->assertTrue(W::overlaps(100, 250, 200, 300));
        $this->assertTrue(W::overlaps(100, 400, 200, 300));
    }

    public function test_weekly_recurrence_keeps_wall_clock_across_daylight_saving(): void
    {
        $first = CarbonImmutable::parse('2026-03-25 08:00:00', 'UTC');                 // 09:00 in Paris (winter time)
        $out = R::expand($first, 'weekly', 1, null, 2, 'Europe/Paris');
        $this->assertSame('2026-03-25 08:00:00', $out[0]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-04-01 07:00:00', $out[1]->format('Y-m-d H:i:s'));     // still 09:00 in Paris, now summer time
    }

    public function test_monthly_recurrence_uses_the_last_day_of_short_months(): void
    {
        $out = R::expand(CarbonImmutable::parse('2026-01-31 10:00:00', 'UTC'), 'monthly', 1, null, 3, 'UTC');
        $this->assertSame(['2026-01-31', '2026-02-28', '2026-03-31'], array_map(fn ($d) => $d->format('Y-m-d'), $out));
    }

    public function test_recurrence_stops_at_the_end_date_and_is_capped(): void
    {
        $first = CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC');
        $this->assertCount(3, R::expand($first, 'daily', 1, '2026-11-04', null, 'UTC'));
        $this->assertCount(R::MAX_OCCURRENCES, R::expand($first, 'daily', 1, null, 500, 'UTC'));
    }

    public function test_recurrence_rejects_bad_input(): void
    {
        $first = CarbonImmutable::parse('2026-11-02 08:00:00', 'UTC');
        foreach ([['yearly', 1, null, 3], ['daily', 0, null, 3], ['daily', 1, null, null]] as [$f, $i, $e, $c]) {
            try {
                R::expand($first, $f, $i, $e, $c, 'UTC');
                $this->fail("$f/$i should be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_ics_is_crlf_escaped_and_folded(): void
    {
        $ics = IcsBuilder::build([
            'uid' => 'abc@cops', 'sequence' => 2, 'method' => 'REQUEST', 'status' => 'CONFIRMED', 'summary' => 'Budget; review, Q4',
            'description' => str_repeat('long text ', 30), 'location' => 'Room A',
            'start' => CarbonImmutable::parse('2026-11-20 08:00:00', 'UTC'), 'end' => CarbonImmutable::parse('2026-11-20 09:00:00', 'UTC'),
            'stamp' => CarbonImmutable::parse('2026-11-15 10:00:00', 'UTC'),
            'organizer' => ['name' => 'Jane "J" Doe', 'email' => 'jane@example.org'],
            'attendees' => [['name' => 'Bob', 'email' => 'bob@example.org', 'partstat' => 'NEEDS-ACTION']], 'reminder_minutes' => 60,
        ]);
        $this->assertStringContainsString("DTSTART:20261120T080000Z\r\n", $ics);
        $this->assertStringContainsString('SUMMARY:Budget\; review\, Q4', $ics);
        $this->assertStringContainsString('SEQUENCE:2', $ics);
        $this->assertStringContainsString('TRIGGER:-PT60M', $ics);
        $this->assertStringContainsString('ORGANIZER;CN="Jane J Doe":mailto:jane@example.org', $ics);
        $this->assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        foreach (explode("\r\n", rtrim($ics)) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    public function test_reminder_offsets_are_parsed_and_due_when_the_time_left_reaches_them(): void
    {
        $this->assertSame([1440, 60], MeetingReminders::parseOffsets('60, 1440,60,x,2,99999'));
        $this->assertSame([], MeetingReminders::parseOffsets(''));
        $this->assertSame([], MeetingReminders::dueOffsets([1440, 60], 2000));
        $this->assertSame([1440], MeetingReminders::dueOffsets([1440, 60], 1000));
        $this->assertSame([1440, 60], MeetingReminders::dueOffsets([1440, 60], 30));
    }
}
