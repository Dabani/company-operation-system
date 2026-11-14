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
