<?php

namespace App\Services\Billing;

use App\Services\MoneyService;

/**
 * Pure invoice arithmetic (unit-tested). Rounding happens once, here, at invoice generation - never in the timer or timesheet layers.
 *   billed minutes = round(raw seconds / 60) to the configured increment and mode
 *   line amount    = round(billed minutes / 60 x hourly rate, 2)
 *   tax            = round(subtotal x tax percent / 100, 2)   (once, on the document)
 */
class InvoiceMath
{
    /** @return array{raw_seconds:int, rounded_minutes:string, quantity:string, amount:string} */
    public static function timeLine(int $seconds, string $hourlyRate, int $incrementMinutes, string $mode): array
    {
        $minutes = bcdiv((string) $seconds, '60', 8);
        $rounded = $incrementMinutes > 1 ? MoneyService::roundToIncrement($minutes, (string) $incrementMinutes, $mode) : MoneyService::round($minutes, 0, $mode);
        $billed = MoneyService::round($rounded, 2, $mode);                                      // minutes, two decimals
        return [
            'raw_seconds' => $seconds,
            'rounded_minutes' => $billed,
            'quantity' => bcdiv($billed, '60', 4),                                              // hours, for display
            'amount' => MoneyService::round(bcdiv(bcmul($billed, $hourlyRate, 8), '60', 8), 2),
        ];
    }

    public static function lineAmount(string $quantity, string $unitPrice): string
    {
        return MoneyService::round(bcmul($quantity, $unitPrice, 8), 2);
    }

    /** @return array{subtotal:string, tax_total:string, total:string} */
    public static function totals(array $lineAmounts, ?string $taxPercent): array
    {
        $subtotal = '0.00';
        foreach ($lineAmounts as $a) $subtotal = MoneyService::add($subtotal, $a);
        $tax = $taxPercent === null ? '0.00' : MoneyService::round(MoneyService::percent($subtotal, $taxPercent), 2);
        return ['subtotal' => $subtotal, 'tax_total' => $tax, 'total' => MoneyService::add($subtotal, $tax)];
    }

    /** Balance owed: total - payments - credit notes (negative = the client is owed money). */
    public static function balance(string $total, string $paid, string $credited): string
    {
        return MoneyService::sub(MoneyService::sub($total, $paid), $credited);
    }

    /** Status implied by the money, ignoring drafts/approval states. $today and $due are 'Y-m-d'. */
    public static function settlement(string $total, string $paid, string $credited, ?string $due, string $today): string
    {
        $balance = self::balance($total, $paid, $credited);
        if (MoneyService::compare($credited, $total) >= 0 && MoneyService::compare($paid, '0') <= 0) return 'cancelled';   // fully credited, nothing paid
        if (MoneyService::compare($balance, '0') <= 0) return 'paid';
        if ($due !== null && $due < $today) return 'overdue';
        return MoneyService::compare($paid, '0') > 0 ? 'partially_paid' : 'issued';
    }

    /** Tax on a partial credit note, proportional to the invoice's own percent. */
    public static function creditTax(string $amount, ?string $taxPercent): string
    {
        return $taxPercent === null ? '0.00' : MoneyService::round(MoneyService::percent($amount, $taxPercent), 2);
    }
}
