<?php

namespace App\Services\Hr\Payroll;

/**
 * The payroll run lifecycle and its separation of duties (Guide 5.6.1). Pure.
 *   Draft -> Calculated -> Reviewed (HR) -> Approved (Finance, then the Managing Director where required) -> Locked -> Paid -> Declared
 * Locked is immutable: a correction is an adjustment run. "Approved" is reached only when every required approval is in.
 */
final class PayRunFlow
{
    public const STATUSES = ['draft', 'calculated', 'reviewed', 'approved', 'locked', 'paid', 'declared', 'cancelled'];

    /** action => statuses it may start from */
    private const FROM = [
        'calculate' => ['draft', 'calculated'],
        'review' => ['calculated'],
        'approve_finance' => ['reviewed'],
        'approve_md' => ['reviewed'],
        'return' => ['reviewed', 'approved'],
        'lock' => ['approved'],
        'pay' => ['locked'],
        'declare' => ['paid'],
        'cancel' => ['draft', 'calculated', 'reviewed'],
    ];

    public static function can(string $status, string $action): bool
    {
        return in_array($status, self::FROM[$action] ?? [], true);
    }

    /** A run in one of these states can no longer be edited in any way (payslips, inputs, scope). */
    public static function frozen(string $status): bool
    {
        return in_array($status, ['locked', 'paid', 'declared'], true);
    }

    /** Inputs and exclusions may change only before review. */
    public static function editable(string $status): bool
    {
        return in_array($status, ['draft', 'calculated'], true);
    }

    /**
     * The status after an approval step. Finance approves first; the Managing Director approves second when the run requires it.
     * @return array{status:string,error:?string}
     */
    public static function afterApproval(string $step, bool $mdRequired, bool $financeDone, bool $mdDone): array
    {
        if ($step === 'approve_md') {
            if (!$mdRequired) {
                return ['status' => 'reviewed', 'error' => 'md_not_required'];
            }
            if (!$financeDone) {
                return ['status' => 'reviewed', 'error' => 'finance_first'];
            }
            return ['status' => 'approved', 'error' => null];
        }
        if ($financeDone) {
            return ['status' => 'reviewed', 'error' => 'already_done'];
        }
        return ['status' => $mdRequired ? 'reviewed' : 'approved', 'error' => null];
    }

    /**
     * Segregation of duties: the people who prepared or calculated cannot review or approve, and nobody approves twice.
     * $people = ['prepared' => id, 'calculated' => ?id, 'reviewed' => ?id, 'finance' => ?id]; returns an error code or null.
     */
    public static function conflict(string $action, string $actor, array $people): ?string
    {
        $made = array_filter([$people['prepared'] ?? null, $people['calculated'] ?? null]);
        $earlier = match ($action) {
            'review' => $made,
            'approve_finance' => array_merge($made, array_filter([$people['reviewed'] ?? null])),
            'approve_md' => array_merge($made, array_filter([$people['reviewed'] ?? null, $people['finance'] ?? null])),
            'lock', 'pay' => $made,                 // the person who prepared the figures does not release the money
            default => [],
        };
        return in_array($actor, $earlier, true) ? 'segregation' : null;
    }
}
