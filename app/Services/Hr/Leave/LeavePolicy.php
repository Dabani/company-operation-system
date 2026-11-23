<?php

namespace App\Services\Hr\Leave;

use App\Services\MoneyService;

/**
 * The company leave policy is not stored here: it is a rule set in the "leave" domain of the shared rules engine (Guide 3.7), so it is versioned,
 * effective-dated and approved by someone other than its author. This class only names the outcome keys the leave module understands and
 * turns the resolved outcomes into typed values. A key the rule does not set is null ("the policy says nothing"), never an invented default.
 * Pure: no database.
 */
final class LeavePolicy
{
    /** key => [type, unit]. Types: decimal (2 places), int, bool. The unit is a translation hint for the screens. */
    public const KEYS = [
        'entitlement_days' => ['decimal', 'days_per_year'],              // days per leave year (balance leave types)
        'service_increment_days' => ['decimal', 'days'],                 // extra days for each completed service period ...
        'service_increment_years' => ['int', 'years'],                   // ... of this many years
        'entitlement_cap_days' => ['decimal', 'days_per_year'],          // the entitlement never grows beyond this (never cuts below the base)
        'full_time_weekly_hours' => ['decimal', 'hours'],                // below this contractual week the entitlement is pro-rated
        'min_service_months_to_take' => ['int', 'months'],               // leave may be taken only after this much service
        'notice_days' => ['int', 'days'],                                // minimum notice before the first day
        'min_days_per_request' => ['decimal', 'days'],
        'max_days_per_request' => ['decimal', 'days'],
        'max_days_per_year' => ['decimal', 'days'],                      // most days of this type that can be taken in one leave year
        'min_annual_block_days' => ['decimal', 'days'],                  // a block of this many days is expected each year (reported, not enforced)
        'carry_over_max_days' => ['decimal', 'days'],                    // above this the balance is forfeited at year end; unset = all carries over
        'carry_over_expiry_months' => ['int', 'months'],                 // carried-over days expire this many months into the new year; unset = never
        'max_simultaneous_absences' => ['int', 'people'],                // warns when more people of the team would be away together
        'allow_negative_balance' => ['bool', 'flag'],
        'requires_document_after_days' => ['decimal', 'days'],           // a supporting document is expected above this many days
    ];

    /** @return array<string,mixed> every known key => typed value or null */
    public static function fromOutcomes(array $outcomes): array
    {
        $out = [];
        foreach (self::KEYS as $key => [$type]) {
            $v = $outcomes[$key] ?? null;
            if ($v === null || $v === '') {
                $out[$key] = null;
                continue;
            }
            $out[$key] = match ($type) {
                'decimal' => is_numeric($v) ? MoneyService::add((string) $v, '0', 2) : null,
                'int' => is_numeric($v) ? (int) $v : null,
                default => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            };
        }
        return $out;
    }

    public static function decimalKeys(): array
    {
        return array_keys(array_filter(self::KEYS, fn ($d) => $d[0] === 'decimal'));
    }
}
