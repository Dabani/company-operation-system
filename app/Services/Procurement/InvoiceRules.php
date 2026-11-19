<?php

namespace App\Services\Procurement;

/**
 * Pure rules for supplier invoices (Guide 7.4 stages 10 and 11). Money is in cents, quantities in thousandths.
 *
 * Three-way match, per invoice line: the invoiced quantity may not exceed what was received AND accepted and not yet invoiced on other live invoices;
 * the unit price may not exceed the order price by more than a tolerance in percent; the tax must equal subtotal x rate within one cent per line
 * (checked only when a tax rate is set). Any failure is a discrepancy and puts the invoice on hold. An approver can accept the discrepancy as an exception.
 *
 *   received -> on_hold | matched;  on_hold -> matched (exception approved) | rejected | on_hold (re-checked);  matched -> approved -> paid;  received|on_hold|matched|approved -> cancelled;  on_hold|matched -> rejected.
 *
 * Segregation of duties: whoever recorded the invoice cannot approve it or decide its exception; whoever approved it cannot mark it paid; the buyer who raised the order
 * cannot approve its invoice.
 */
final class InvoiceRules
{
    public const MOVES = [
        'received' => ['on_hold', 'matched', 'cancelled'],
        'on_hold' => ['matched', 'rejected', 'cancelled'],
        'matched' => ['approved', 'rejected', 'cancelled'],
        'approved' => ['paid', 'cancelled'],
        'paid' => [],
        'rejected' => [],
        'cancelled' => [],
    ];

    public static function allowed(string $from, string $to): bool { return in_array($to, self::MOVES[$from] ?? [], true); }

    /** Statuses whose quantities count as already invoiced against the order lines. */
    public static function counts(string $status): bool { return in_array($status, ['received', 'on_hold', 'matched', 'approved', 'paid'], true); }

    /** The supplier's number, reduced to letters and digits in upper case, so "inv-001", "INV 001" and "INV001" are one invoice. */
    public static function numberKey(string $number): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $number) ?? ''); }

    public static function thousandths(string|int|float $q): int { return (int) round(((float) $q) * 1000); }

    /**
     * @param int $acceptedMilli   accepted so far on the order line
     * @param int $invoicedMilli   already invoiced on other live invoices
     * @return array<int, array{code:string, detail:string}> discrepancies for one invoice line
     */
    public static function matchLine(int $acceptedMilli, int $invoicedMilli, int $invoiceQtyMilli, int $orderPriceCents, int $invoicePriceCents, int $tolerancePercent): array
    {
        $issues = [];
        $available = max(0, $acceptedMilli - $invoicedMilli);
        if ($acceptedMilli <= 0) {
            $issues[] = ['code' => 'not_received', 'detail' => 'accepted 0'];
        } elseif ($invoiceQtyMilli > $available) {
            $issues[] = ['code' => 'qty_over', 'detail' => 'invoiced ' . $invoiceQtyMilli / 1000 . ' > available ' . $available / 1000];
        }
        $max = (int) floor($orderPriceCents * (100 + max(0, $tolerancePercent)) / 100);
        if ($invoicePriceCents > $max) {
            $issues[] = ['code' => 'price_over', 'detail' => 'price ' . $invoicePriceCents / 100 . ' > order ' . $orderPriceCents / 100];
        }
        return $issues;
    }

    /** Expected tax in cents for a subtotal and a rate in percent (may have decimals). */
    public static function expectedTaxCents(int $subtotalCents, float $ratePercent): int { return (int) round($subtotalCents * $ratePercent / 100); }

    /** A rate of 0 means the organization does not use the tax check. One cent of slack per line covers rounding. */
    public static function taxOk(int $taxCents, int $subtotalCents, float $ratePercent, int $lineCount): bool
    {
        if ($ratePercent <= 0) {
            return true;
        }
        return abs($taxCents - self::expectedTaxCents($subtotalCents, $ratePercent)) <= max(1, $lineCount);
    }

    public static function approverAllowed(string $recordedBy, ?string $orderCreator, string $approver): bool
    {
        return $approver !== $recordedBy && ($orderCreator === null || $approver !== $orderCreator);
    }

    public static function exceptionDeciderAllowed(string $recordedBy, string $decider): bool { return $decider !== $recordedBy; }

    public static function payerAllowed(?string $approvedBy, string $payer): bool { return $approvedBy === null || $approvedBy !== $payer; }

    /** Matched status when no discrepancy, else on hold. */
    public static function outcome(array $issues): string { return $issues ? 'on_hold' : 'matched'; }
}
