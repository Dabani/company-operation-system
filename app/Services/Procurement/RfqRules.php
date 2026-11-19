<?php

namespace App\Services\Procurement;

/**
 * Sourcing rules (pure, no database). An RFQ goes draft -> open -> closed -> awarded; it can be cancelled before award and a closed one can be reopened.
 * Fewer invited suppliers than the minimum needs a written sole-source reason. Only complete, submitted, still-valid quotes can win. Awarding anything but
 * the lowest eligible quote needs a written reason, and the award cannot exceed the approved requisition beyond the order tolerance.
 */
class RfqRules
{
    public const MOVES = [
        'draft' => ['open', 'cancelled'],
        'open' => ['closed', 'awarded', 'cancelled'],
        'closed' => ['open', 'awarded', 'cancelled'],
        'awarded' => [],
        'cancelled' => [],
    ];

    public static function allowed(string $from, string $to): bool { return in_array($to, self::MOVES[$from] ?? [], true); }

    public static function editable(string $status): bool { return $status === 'draft'; }

    public static function canQuote(string $status): bool { return $status === 'open'; }

    /** Whether the invitation list is acceptable: enough suppliers, or a reason of at least 10 characters. */
    public static function inviteesOk(int $count, int $minimum, ?string $reason): bool
    {
        return $count >= 1 && ($count >= $minimum || mb_strlen(trim((string) $reason)) >= 10);
    }

    /** A quote is complete when every requisition line has a price. */
    public static function complete(int $lineCount, int $pricedLines): bool { return $lineCount > 0 && $pricedLines === $lineCount; }

    /** A quote is valid on a date when it has no validity limit or the limit is not before that date (both Y-m-d). */
    public static function stillValid(?string $validUntil, string $today): bool { return $validUntil === null || $validUntil >= $today; }

    /** @param array<string,int> $totals eligible quote id => total in cents. Returns the ids sharing the lowest total (in input order). */
    public static function lowest(array $totals): array
    {
        if (!$totals) {
            return [];
        }
        $min = min($totals);
        return array_keys(array_filter($totals, fn ($t) => $t === $min));
    }

    public static function awardNeedsReason(string $winnerId, array $lowestIds): bool { return !in_array($winnerId, $lowestIds, true); }

    public static function reasonOk(?string $reason): bool { return mb_strlen(trim((string) $reason)) >= 10; }
}
