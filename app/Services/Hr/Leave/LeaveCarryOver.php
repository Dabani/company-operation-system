<?php

namespace App\Services\Hr\Leave;

/** Year-end carry-over limit and carry-over expiry (Guide 5.4.1). Pure. Nothing is ever forfeited unless the policy sets a limit or an expiry. */
final class LeaveCarryOver
{
    /** Days forfeited at the end of a leave year: whatever is above the carry-over limit. No limit (null) = nothing is forfeited. */
    public static function forfeitAtYearEnd(string $balance, ?string $limit): string
    {
        if ($limit === null || $limit === '' || bccomp($balance, $limit, 2) <= 0) {
            return '0.00';
        }
        return bcsub($balance, $limit, 2);
    }

    /**
     * Days forfeited when carried-over days expire: the carried-in days not yet used (days taken consume the carried-over days first),
     * and never more than the balance that is left.
     */
    public static function forfeitAtExpiry(string $carriedIn, string $usedSinceYearStart, string $balance): string
    {
        $unused = bcsub($carriedIn, $usedSinceYearStart, 2);
        if (bccomp($unused, '0', 2) <= 0 || bccomp($balance, '0', 2) <= 0) {
            return '0.00';
        }
        return bccomp($unused, $balance, 2) < 0 ? $unused : $balance;
    }
}
