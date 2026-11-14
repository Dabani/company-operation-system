<?php

namespace App\Services\Hr\Attendance;

use App\Models\ScheduleAssignment;
use App\Models\ScheduleDay;
use App\Models\Shift;
use App\Models\ShiftOverride;
use DateTimeImmutable;

/** Which shift is an employee expected to work on each date? A dated override wins; otherwise the latest schedule assignment on or before the date. */
class ScheduleResolver
{
    /** @return array<string,?Shift> date (Y-m-d) => shift, or null for a day off / no schedule */
    public function forRange(string $employeeId, string $from, string $to): array
    {
        $overrides = ShiftOverride::with('shift')->where('employee_id', $employeeId)->whereDate('work_date', '>=', $from)->whereDate('work_date', '<=', $to)->get()
            ->keyBy(fn ($o) => $o->work_date->toDateString());
        $assignments = ScheduleAssignment::where('employee_id', $employeeId)->whereDate('effective_from', '<=', $to)->orderBy('effective_from')->orderBy('created_at')->get();
        $days = ScheduleDay::with('shift')->whereIn('schedule_id', $assignments->pluck('schedule_id')->unique())->get()
            ->groupBy('schedule_id')->map(fn ($g) => $g->keyBy('weekday'));

        $out = [];
        for ($d = new DateTimeImmutable($from), $end = new DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
            $date = $d->format('Y-m-d');
            if ($overrides->has($date)) {
                $out[$date] = $overrides[$date]->shift;
                continue;
            }
            $current = $assignments->last(fn ($a) => $a->effective_from->toDateString() <= $date);
            $out[$date] = $current ? ($days[$current->schedule_id][(int) $d->format('N')] ?? null)?->shift : null;
        }
        return $out;
    }
}
