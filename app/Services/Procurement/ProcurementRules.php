<?php

namespace App\Services\Procurement;

/**
 * Pure rules for suppliers and purchase requisitions (Guide section 7). Money is handled in cents. Quantities have up to 3 decimals, so a line total is computed
 * from thousandths of a unit: cents = round(thousandths * unit_price_cents / 1000).
 *
 * Supplier: draft -> under_review -> approved <-> suspended; approved|suspended -> blocked -> under_review (re-review); anything but archived -> archived.
 * Only an approved supplier can be preferred on a requisition.
 * Requisition: draft -> pending_approval -> approved | rejected | draft (returned); rejected -> draft; draft|pending_approval|approved -> cancelled.
 */
final class ProcurementRules
{
    public const SUPPLIER_MOVES = [
        'draft' => ['under_review', 'archived'],
        'under_review' => ['approved', 'draft', 'archived'],
        'approved' => ['suspended', 'blocked', 'archived'],
        'suspended' => ['approved', 'blocked', 'archived'],
        'blocked' => ['under_review', 'archived'],
        'archived' => [],
    ];
    /** Moves a buyer can make by hand; approval and return to draft come only from the approval outcome. */
    public const SUPPLIER_MANUAL_TARGETS = ['suspended', 'blocked', 'archived'];

    public const REQUISITION_MOVES = [
        'draft' => ['pending_approval', 'cancelled'],
        'pending_approval' => ['approved', 'rejected', 'draft', 'cancelled'],
        'approved' => ['cancelled'],
        'rejected' => ['draft', 'cancelled'],
        'cancelled' => [],
    ];

    public static function supplierAllowed(string $from, string $to): bool { return in_array($to, self::SUPPLIER_MOVES[$from] ?? [], true); }

    public static function supplierManual(string $from, string $to): bool
    {
        if (!self::supplierAllowed($from, $to)) {
            return false;
        }
        return in_array($to, self::SUPPLIER_MANUAL_TARGETS, true) || ($from === 'suspended' && $to === 'approved');
    }

    public static function supplierReasonRequired(string $to): bool { return in_array($to, ['suspended', 'blocked'], true); }

    public static function supplierEditable(string $status): bool { return in_array($status, ['draft', 'approved', 'suspended', 'under_review'], true); }

    public static function requisitionAllowed(string $from, string $to): bool { return in_array($to, self::REQUISITION_MOVES[$from] ?? [], true); }

    public static function requisitionEditable(string $status): bool { return $status === 'draft'; }

    public static function toCents(string|int|float $amount): int { return (int) round(((float) $amount) * 100); }

    public static function thousandths(string|int|float $quantity): int { return (int) round(((float) $quantity) * 1000); }

    public static function lineCents(string|int|float $quantity, string|int|float $unitPrice): int
    {
        return (int) round(self::thousandths($quantity) * self::toCents($unitPrice) / 1000);
    }

    /** @param array<int, array{quantity: string|int|float, unit_price: string|int|float}> $lines */
    public static function totalCents(array $lines): int
    {
        return array_sum(array_map(fn ($l) => self::lineCents($l['quantity'], $l['unit_price']), $lines));
    }

    public static function exceedsBudget(?int $budgetCents, int $committedCents, int $newCents): bool
    {
        return $budgetCents !== null && $committedCents + $newCents > $budgetCents;
    }

    /** Several purchases by the same requester for the same cost centre and type, close in time, that together cross the threshold look like splitting. 0 switches it off. */
    public static function splitSuspected(int $thresholdCents, int $newCents, int $recentCents): bool
    {
        return $thresholdCents > 0 && $newCents < $thresholdCents && $recentCents > 0 && $recentCents + $newCents >= $thresholdCents;
    }

    /** What a requisition needs before it can be submitted. Returns the list of missing field keys (empty = complete). */
    public static function missing(array $r, int $lineCount, int $totalCents, string $today): array
    {
        $m = [];
        foreach (['title', 'purpose', 'delivery_location', 'department_id', 'procurement_type_id', 'currency_code'] as $k) {
            if (trim(strip_tags((string) ($r[$k] ?? ''))) === '') {
                $m[] = $k;
            }
        }
        if (empty($r['needed_by']) || $r['needed_by'] < $today) {
            $m[] = 'needed_by';
        }
        if ($lineCount < 1) {
            $m[] = 'lines';
        } elseif ($totalCents <= 0) {
            $m[] = 'total';
        }
        return $m;
    }

    /** A supplier bank change is verified by someone other than the person who proposed it. */
    public static function canVerifyBank(string $proposerId, string $verifierId): bool { return $proposerId !== $verifierId; }

    public static function maskAccount(string $number): string
    {
        $n = preg_replace('/\s+/', '', $number);
        return strlen($n) <= 4 ? str_repeat('•', strlen($n)) : str_repeat('•', strlen($n) - 4) . substr($n, -4);
    }
}
