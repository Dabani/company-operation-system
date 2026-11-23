<?php

namespace App\Services\Hr;

use App\Models\HrSetting;
use Illuminate\Validation\ValidationException;

/** Small per-organization HR settings with documented defaults and allowed ranges (nothing is hard-coded in the logic that uses them). */
final class HrSettings
{
    /** key => [default, min, max] */
    public const DEFS = [
        'applicant_retention_months' => [12, 1, 120],   // review applicant personal data this many months after the decision
        'offer_validity_days' => [14, 1, 90],           // default validity of an issued offer
        'overtime_min_minutes' => [15, 0, 240],         // attendance: extra time below this is not counted as overtime
        'pairing_window_hours' => [20, 4, 24],          // attendance: longest time between a clock-in and its clock-out
        'punch_min_interval_seconds' => [30, 0, 600],   // attendance: punches closer than this are one tap (double tap)
        'geolocation_mode' => [0, 0, 2],                // attendance: 0 = off, 1 = record where people clock in, 2 = clocking is only allowed inside a work site
        'geolocation_max_accuracy_meters' => [100, 10, 1000],   // attendance: a location less precise than this is refused when geolocation is required
        'leave_year_start_month' => [1, 1, 12],         // leave (phase 5a): month the leave year starts
        'trip_advance_retire_days' => [14, 1, 90],         // trips (phase 5b): days after the trip (or payment) to justify an advance
        'payroll_md_approval_required' => [1, 0, 1],      // payroll (6b): the Managing Director must approve a run (1) or not (0)
        'payroll_proration_mode' => [0, 0, 2],             // payroll (6b): part-period pay: 0 none, 1 calendar days, 2 working days (the accountant decides)
        'payroll_earnings_decimals' => [2, 0, 2],          // payroll (6b): decimals of a prorated earning
    ];

    public static function get(string $orgId, string $key): int
    {
        $v = HrSetting::withoutGlobalScopes()->where('organization_id', $orgId)->where('key', $key)->value('value');
        return $v !== null ? (int) $v : self::DEFS[$key][0];
    }

    public static function all(string $orgId): array
    {
        return collect(self::DEFS)->map(fn ($d, $k) => ['key' => $k, 'value' => self::get($orgId, $k), 'default' => $d[0], 'min' => $d[1], 'max' => $d[2]])->values()->all();
    }

    public static function set(string $orgId, string $key, int $value): void
    {
        if (!isset(self::DEFS[$key]) || $value < self::DEFS[$key][1] || $value > self::DEFS[$key][2]) {
            throw ValidationException::withMessages([$key => [__('recruitment.setting_invalid')]]);
        }
        HrSetting::withoutGlobalScopes()->updateOrCreate(['organization_id' => $orgId, 'key' => $key], ['value' => (string) $value]);
    }
}
