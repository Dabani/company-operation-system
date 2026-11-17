<?php

namespace App\Services\Meetings;

/**
 * Pure rules for resolutions and action items (Guide 6.7): who may move what, what counts as overdue, when to remind.
 * Dates are calendar dates (Y-m-d) in the organization time zone; the caller passes "today".
 */
final class FollowUpRules
{
    public const RESOLUTION = [
        'open' => ['in_progress', 'completed', 'cancelled'],
        'in_progress' => ['open', 'completed', 'cancelled'],
        'completed' => ['open'],
        'cancelled' => [],
    ];
    public const ACTION = [
        'open' => ['in_progress', 'done', 'cancelled'],
        'in_progress' => ['open', 'done', 'cancelled'],
        'done' => ['closed', 'in_progress'],
        'closed' => [],
        'cancelled' => [],
    ];

    private static function table(string $kind): array { return $kind === 'resolution' ? self::RESOLUTION : self::ACTION; }

    public static function allowed(string $kind, string $from, string $to): bool { return in_array($to, self::table($kind)[$from] ?? [], true); }

    /** Meetings that have started can record resolutions and actions. */
    public static function canRecord(string $meetingStatus): bool { return in_array($meetingStatus, ['in_progress', 'held', 'closed'], true); }

    public static function isActive(string $status): bool { return in_array($status, ['open', 'in_progress'], true); }

    /** The text of an item can change while it is active. */
    public static function editable(string $status): bool { return self::isActive($status); }

    /** Moves that need a written reason: cancelling and re-opening finished work. */
    public static function needsReason(string $kind, string $from, string $to): bool
    {
        return $to === 'cancelled' || ($kind === 'resolution' && $from === 'completed' && $to === 'open') || ($kind === 'action' && $from === 'done' && $to === 'in_progress');
    }

    /** Completion evidence: a note of 3+ characters or at least one file. */
    public static function evidenceOk(?string $note, int $files): bool { return $files > 0 || mb_strlen(trim((string) $note)) >= 3; }

    /**
     * May this person make this move? The responsible person / owner reports progress and completion; the people who run the meeting
     * (organizer, secretary, chair, manage-all) can do everything; closing an action is a check by someone other than its owner.
     */
    public static function canMove(string $kind, string $from, string $to, bool $isOwner, bool $isRunner): bool
    {
        if (!self::allowed($kind, $from, $to)) {
            return false;
        }
        if ($kind === 'action' && $to === 'closed') {
            return $isRunner && !$isOwner;
        }
        if ($isRunner) {
            return true;
        }
        return $isOwner && in_array($to, ['in_progress', 'done', 'completed', 'open'], true) && !($from === 'completed' || $from === 'done');
    }

    public static function isOverdue(string $status, ?string $dueOn, string $today): bool { return $dueOn !== null && self::isActive($status) && $dueOn < $today; }

    public static function dueSoon(string $status, ?string $dueOn, string $today, int $days): bool
    {
        if ($dueOn === null || !self::isActive($status) || $dueOn < $today) {
            return false;
        }
        return $dueOn <= date('Y-m-d', strtotime($today . " +$days days"));
    }

    /** Overdue reminders repeat every $repeatDays days (at least 1), never twice on the same day. */
    public static function overdueDue(?string $lastAlertedOn, string $today, int $repeatDays): bool
    {
        return $lastAlertedOn === null || $lastAlertedOn <= date('Y-m-d', strtotime($today . ' -' . max(1, $repeatDays) . ' days'));
    }

    public static function daysOverdue(?string $dueOn, string $today): int
    {
        return $dueOn === null || $dueOn >= $today ? 0 : (int) round((strtotime($today) - strtotime($dueOn)) / 86400);
    }
}
