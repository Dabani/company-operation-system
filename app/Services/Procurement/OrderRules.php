<?php

namespace App\Services\Procurement;

/**
 * Pure rules for purchase orders and goods receipts (Guide 7.4 steps 8 and 9). Quantities are compared in thousandths of a unit, money in cents.
 *   draft -> pending_approval -> approved -> issued -> partially_received -> received -> closed
 *   pending_approval -> draft (returned or rejected, with the reviewer's note);  draft|approved|issued (nothing received yet) -> cancelled;  partially_received -> closed (short delivery accepted, with a reason).
 * A purchase order may exceed its requisition by at most a tolerance in percent (default 0 means no overrun at all): more than that is a variation and needs a new requisition.
 * The person who raised the order cannot record its receipt: receipt is independent of the buyer.
 */
final class OrderRules
{
    public const MOVES = [
        'draft' => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'draft', 'cancelled'],
        'approved' => ['issued', 'cancelled'],
        'issued' => ['partially_received', 'received', 'cancelled'],
        'partially_received' => ['received', 'closed'],
        'received' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    public static function allowed(string $from, string $to): bool { return in_array($to, self::MOVES[$from] ?? [], true); }

    public static function editable(string $status): bool { return $status === 'draft'; }

    public static function canReceive(string $status): bool { return in_array($status, ['issued', 'partially_received'], true); }

    public static function thousandths(string|int|float $q): int { return (int) round(((float) $q) * 1000); }

    /** What is still to be delivered on a line, in thousandths (never negative). */
    public static function remaining(string|int|float $ordered, string|int|float $accepted): int
    {
        return max(0, self::thousandths($ordered) - self::thousandths($accepted));
    }

    /** One receipt line is valid when 0 <= accepted <= received and received does not exceed what is still to come. */
    public static function receiptLineValid(string|int|float $received, string|int|float $accepted, int $remainingThousandths): bool
    {
        $r = self::thousandths($received);
        $a = self::thousandths($accepted);
        return $r > 0 && $a >= 0 && $a <= $r && $r <= $remainingThousandths;
    }

    /** Status after a receipt, from the ordered and accepted quantities of every line. */
    public static function statusAfterReceipt(array $lines): string
    {
        $all = true;
        $any = false;
        foreach ($lines as $l) {
            $acc = self::thousandths($l['accepted']);
            $any = $any || $acc > 0;
            $all = $all && $acc >= self::thousandths($l['quantity']);
        }
        return $all ? 'received' : ($any ? 'partially_received' : 'issued');
    }

    public static function withinTolerance(int $orderCents, int $requisitionCents, int $tolerancePercent): bool
    {
        return $orderCents <= (int) floor($requisitionCents * (100 + max(0, $tolerancePercent)) / 100);
    }

    public static function receiverAllowed(?string $orderCreatorId, string $userId): bool { return $orderCreatorId === null || $orderCreatorId !== $userId; }

    public static function hasReceipts(array $lines): bool
    {
        foreach ($lines as $l) {
            if (self::thousandths($l['accepted']) > 0) {
                return true;
            }
        }
        return false;
    }
}
