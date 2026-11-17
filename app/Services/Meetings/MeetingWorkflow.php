<?php

namespace App\Services\Meetings;

/**
 * Meeting lifecycle (Guide 6.1), kept as data so it can be unit-tested without a database.
 *   draft -> scheduled (invitations sent) -> in_progress -> held -> closed;  draft/scheduled -> cancelled.
 * A meeting may be recorded as held without being started (the secretary forgot to press start).
 * Closing needs published minutes: that rule needs the database and lives in MeetingService::close().
 */
final class MeetingWorkflow
{
    public const TRANSITIONS = [
        'draft' => ['scheduled', 'cancelled'],
        'scheduled' => ['in_progress', 'held', 'cancelled'],
        'in_progress' => ['held'],
        'held' => ['closed'],
        'cancelled' => [],
        'closed' => [],
    ];

    public static function allowed(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** Title, people, time and place may be changed only before the meeting starts. */
    public static function editable(string $status): bool
    {
        return in_array($status, ['draft', 'scheduled'], true);
    }

    /** Statuses in which the meeting still occupies a room and people's calendars. */
    public static function occupiesCalendar(string $status): bool
    {
        return in_array($status, ['draft', 'scheduled', 'in_progress'], true);
    }

    /** Two periods overlap when each starts before the other ends (back-to-back is not a conflict). Arguments are comparable timestamps. */
    public static function overlaps(int $aStart, int $aEnd, int $bStart, int $bEnd): bool
    {
        return $aStart < $bEnd && $bStart < $aEnd;
    }
}
