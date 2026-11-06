<?php

namespace Tests\Unit;

use App\Services\Time\RateMatcher;
use App\Services\Time\TimerMath;
use PHPUnit\Framework\TestCase;

class TimePureLogicTest extends TestCase
{
    public function test_elapsed_time_counts_open_segment_up_to_now(): void
    {
        $segs = [['start' => '2026-11-03 08:00:00', 'end' => '2026-11-03 09:00:00'], ['start' => '2026-11-03 09:30:00', 'end' => null]];
        $this->assertSame(5400, TimerMath::seconds($segs, '2026-11-03 10:00:00'));
    }

    public function test_overlap_rules(): void
    {
        $a = ['start' => '2026-11-03 08:00:00', 'end' => '2026-11-03 09:00:00'];
        $now = '2026-11-03 10:00:00';
        $this->assertTrue(TimerMath::overlaps(['start' => '2026-11-03 08:30:00', 'end' => '2026-11-03 09:15:00'], $a, $now));
        $this->assertFalse(TimerMath::overlaps(['start' => '2026-11-03 09:00:00', 'end' => '2026-11-03 09:30:00'], $a, $now));   // touching only
        $this->assertTrue(TimerMath::overlaps(['start' => '2026-11-03 09:45:00', 'end' => null], ['start' => '2026-11-03 09:30:00', 'end' => null], $now));
    }

    public function test_forgotten_timer_is_cut_at_the_allowance(): void
    {
        $this->assertNull(TimerMath::autoStopAt([['start' => '2026-11-03 08:00:00', 'end' => null]], 12 * 3600, '2026-11-03 10:00:00'));
        $this->assertSame('2026-11-03 13:00:00', TimerMath::autoStopAt([['start' => '2026-11-03 01:00:00', 'end' => null]], 12 * 3600, '2026-11-03 20:00:00'));
        $this->assertSame('2026-11-03 07:00:00', TimerMath::autoStopAt([['start' => '2026-11-03 01:00:00', 'end' => '2026-11-03 03:00:00'], ['start' => '2026-11-03 04:00:00', 'end' => null]], 5 * 3600, '2026-11-03 20:00:00'));
    }

    public function test_week_starts_on_monday(): void
    {
        $this->assertSame('2026-11-02', TimerMath::weekStart('2026-11-08'));
        $this->assertSame('2026-11-02', TimerMath::weekStart('2026-11-02'));
    }

    public function test_rate_specificity_and_effective_dating(): void
    {
        $L = [
            ['id' => 'd', 'scope' => 'default', 'task_id' => null, 'user_id' => null, 'role' => null, 'rate' => '10', 'from' => '2026-01-01', 'to' => null],
            ['id' => 'r', 'scope' => 'role', 'task_id' => null, 'user_id' => null, 'role' => 'member', 'rate' => '20', 'from' => '2026-01-01', 'to' => null],
            ['id' => 'e', 'scope' => 'employee', 'task_id' => null, 'user_id' => 'U1', 'role' => null, 'rate' => '30', 'from' => '2026-01-01', 'to' => null],
            ['id' => 'e2', 'scope' => 'employee', 'task_id' => null, 'user_id' => 'U1', 'role' => null, 'rate' => '35', 'from' => '2026-07-01', 'to' => null],
            ['id' => 't', 'scope' => 'task', 'task_id' => 'T1', 'user_id' => null, 'role' => null, 'rate' => '40', 'from' => '2026-01-01', 'to' => null],
        ];
        $id = fn ($t, $u, $r, $d) => RateMatcher::pick($L, $t, $u, $r, $d)['id'] ?? null;
        $this->assertSame('t', $id('T1', 'U1', 'member', '2026-11-03'));
        $this->assertSame('e2', $id('T2', 'U1', 'member', '2026-11-03'));
        $this->assertSame('e', $id('T2', 'U1', 'member', '2026-03-01'));
        $this->assertSame('r', $id('T2', 'U2', 'member', '2026-11-03'));
        $this->assertNull($id('T2', 'U2', 'viewer', '2025-12-31'));
    }
}
