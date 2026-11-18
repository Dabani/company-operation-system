<?php

namespace App\Services\Events;

/**
 * Pure rules for event expenses. Money is handled in minor units (cents) to avoid float drift: callers convert with toCents().
 *   draft -> pending_approval -> approved | rejected | draft (returned);  rejected -> draft (to correct and resubmit).
 * A budget counts approved plus pending expenses as "committed". An expense that would exceed the budget is still allowed, but it is flagged
 * "over budget" so the approver sees it (Guide 7: over-budget requests are blocked or explicitly escalated; here they are escalated, never silent).
 */
final class ExpenseRules
{
    public const TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'rejected', 'draft'],
        'approved' => [],
        'rejected' => ['draft'],
    ];

    public static function allowed(string $from, string $to): bool { return in_array($to, self::TRANSITIONS[$from] ?? [], true); }

    public static function editable(string $status): bool { return $status === 'draft'; }

    public static function toCents(string|int|float $amount): int { return (int) round(((float) $amount) * 100); }

    /** Money left in the budget after the committed expenses; null when there is no budget. */
    public static function remainingCents(?int $budgetCents, int $committedCents): ?int { return $budgetCents === null ? null : $budgetCents - $committedCents; }

    public static function exceedsBudget(?int $budgetCents, int $committedCents, int $newCents): bool
    {
        return $budgetCents !== null && $committedCents + $newCents > $budgetCents;
    }

    /** A receipt is needed from this amount upwards; a threshold of 0 switches the rule off. */
    public static function receiptRequired(int $thresholdCents, int $amountCents): bool { return $thresholdCents > 0 && $amountCents >= $thresholdCents; }

    public static function amountValid(int $cents): bool { return $cents > 0; }
}
