<?php

namespace App\Services\Meetings;

use Carbon\CarbonInterface;

/**
 * Pure rules of online meetings (no database). The video room opens shortly before the start and stays open while the meeting lasts; hosts are moderators;
 * interventions are requested only while the meeting is in progress, each person has at most one open request, and the chair grants them one at a time
 * (points of order first, then replies, then everything else in order of arrival).
 */
final class OnlineRules
{
    public const KINDS = ['speak', 'question', 'point_of_order', 'reply'];
    public const OPEN = ['requested', 'speaking'];
    public const MOVES = [
        'requested' => ['speaking', 'dismissed', 'withdrawn'],
        'speaking' => ['done'],
        'done' => [],
        'dismissed' => [],
        'withdrawn' => [],
    ];

    public static function allowed(string $from, string $to): bool { return in_array($to, self::MOVES[$from] ?? [], true); }

    public static function isOpen(string $status): bool { return in_array($status, self::OPEN, true); }

    /** The room can be entered from $earlyMinutes before the start until the scheduled end (and for as long as the meeting is in progress). */
    public static function joinWindowOpen(string $status, CarbonInterface $now, CarbonInterface $startsAt, CarbonInterface $endsAt, int $earlyMinutes): bool
    {
        if ($status === 'in_progress') {
            return true;
        }
        if ($status !== 'scheduled') {
            return false;
        }
        return $now->greaterThanOrEqualTo($startsAt->copy()->subMinutes(max(0, $earlyMinutes))) && $now->lessThanOrEqualTo($endsAt);
    }

    /** Hosts run the room. */
    public static function moderator(bool $organizer, bool $chair, bool $secretary, bool $facilitator): bool
    {
        return $organizer || $chair || $secretary || $facilitator;
    }

    /** Guests (no account) get in only when the meeting allows it, the meeting is not confidential and they did not decline. */
    public static function guestMayJoin(bool $allowGuests, bool $confidential, ?string $response): bool
    {
        return $allowGuests && !$confidential && $response !== 'declined';
    }

    public static function mayRequest(string $meetingStatus): bool { return $meetingStatus === 'in_progress'; }

    public static function topicOk(?string $topic): bool { return $topic === null || mb_strlen(trim($topic)) <= 200; }

    /** The chair gives the floor to a request: only when nobody is speaking at the moment. */
    public static function canGrant(bool $someoneSpeaking): bool { return !$someoneSpeaking; }

    /**
     * Queue order. @param array<int, array{id:string, kind:string, at:int}> $requests
     * @return string[] ids, first to speak first
     */
    public static function order(array $requests): array
    {
        $rank = ['point_of_order' => 0, 'reply' => 1];
        usort($requests, fn ($a, $b) => [$rank[$a['kind']] ?? 2, $a['at'], $a['id']] <=> [$rank[$b['kind']] ?? 2, $b['at'], $b['id']]);
        return array_column($requests, 'id');
    }

    /** A room name that cannot be guessed: lower-case letters and digits, 20 to 48 characters, prefixed. */
    public static function validRoom(string $name): bool { return (bool) preg_match('/^cops-[a-z0-9]{20,48}$/', $name); }
}
