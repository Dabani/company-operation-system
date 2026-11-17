<?php

namespace App\Services\Events;

/**
 * Pure rules of the events module (no database), so they can be unit-tested anywhere.
 *   draft -> open (registration) <-> closed -> in_progress -> completed;  draft/open/closed -> cancelled.
 * Capacity counts "registered" people only; a full event puts new people on the waitlist (when enabled) and promotes them, oldest first, as seats free up.
 */
final class EventRules
{
    public const TRANSITIONS = [
        'draft' => ['open', 'cancelled'],
        'open' => ['closed', 'in_progress', 'cancelled'],
        'closed' => ['open', 'in_progress', 'cancelled'],
        'in_progress' => ['completed'],
        'completed' => [],
        'cancelled' => [],
    ];

    public static function allowed(string $from, string $to): bool { return in_array($to, self::TRANSITIONS[$from] ?? [], true); }

    /** Title, time, place, capacity and registration window can change until the event starts. */
    public static function editable(string $status): bool { return in_array($status, ['draft', 'open', 'closed'], true); }

    /** Registration is possible while the event is open and "now" is inside the optional window. Arguments are timestamps. */
    public static function registrationOpen(string $status, int $now, ?int $opensAt, ?int $closesAt): bool
    {
        return $status === 'open' && ($opensAt === null || $now >= $opensAt) && ($closesAt === null || $now < $closesAt);
    }

    /** registered | waitlisted | full */
    public static function seat(?int $capacity, int $taken, bool $waitlistEnabled): string
    {
        if ($capacity === null || $taken < $capacity) {
            return 'registered';
        }
        return $waitlistEnabled ? 'waitlisted' : 'full';
    }

    /** How many waitlisted people can be promoted now. */
    public static function freeSeats(?int $capacity, int $taken): int { return $capacity === null ? PHP_INT_MAX : max(0, $capacity - $taken); }

    /** Check-in happens while the event is running; the organizer can still record a late check-in after it completed (corrections are audited). */
    public static function canCheckIn(string $status, bool $isManager): bool { return $status === 'in_progress' || ($status === 'completed' && $isManager); }

    public static function ticketValid(string $token): bool { return strlen($token) >= 40 && ctype_alnum($token); }

    /** Share of registered people who attended, as a whole percentage. */
    public static function attendanceRate(int $registered, int $attended): int { return $registered > 0 ? (int) round($attended * 100 / $registered) : 0; }

    /** Registered people without a check-in. */
    public static function noShows(array $registeredIds, array $checkedInIds): array { return array_values(array_diff($registeredIds, $checkedInIds)); }
}
