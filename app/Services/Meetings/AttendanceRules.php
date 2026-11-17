<?php

namespace App\Services\Meetings;

use Carbon\CarbonInterface;

/** Pure attendance rules (Guide 6.5): no database, unit tested. */
final class AttendanceRules
{
    /** What the sheet can hold. "expected" is the starting point and is never a final answer after the meeting. */
    public const STATUSES = ['expected', 'present', 'late', 'online', 'absent', 'excused'];
    public const ATTENDED = ['present', 'late', 'online'];

    /** Attendance is recorded from the moment the meeting starts. */
    public static function isOpen(string $meetingStatus): bool { return in_array($meetingStatus, ['in_progress', 'held', 'closed'], true); }

    /** After the meeting was held, every change is a correction: it needs a reason and is flagged in the history. */
    public static function isCorrection(string $meetingStatus): bool { return in_array($meetingStatus, ['held', 'closed'], true); }

    public static function attended(string $status): bool { return in_array($status, self::ATTENDED, true); }

    /** Arriving up to $graceMinutes after the start still counts as present. */
    public static function classify(CarbonInterface $arrival, CarbonInterface $start, int $graceMinutes): string
    {
        return $arrival->greaterThan($start->copy()->addMinutes(max(0, $graceMinutes))) ? 'late' : 'present';
    }

    /** @param string[] $statuses
     *  @return array{total:int,present:int,late:int,online:int,absent:int,excused:int,expected:int,attended:int,rate:?float} */
    public static function summary(array $statuses): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($statuses as $s) {
            if (isset($out[$s])) {
                $out[$s]++;
            }
        }
        $attended = $out['present'] + $out['late'] + $out['online'];
        $counted = array_sum($out) - $out['excused'];          // excused people are not held against the rate
        return $out + ['total' => array_sum($out), 'attended' => $attended, 'rate' => $counted > 0 ? round($attended * 100 / $counted, 1) : null];
    }
}
