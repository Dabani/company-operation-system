<?php

namespace App\Services\Meetings;

/** Pure rules for meeting minutes (Guide 6.6 and 6.8): lifecycle, who may write, and where a publication may go. No database. */
final class MinutesRules
{
    /** draft -> pending_approval (sent to the chair) -> approved | back to draft; approved -> published; published -> superseded by a corrected version. */
    public const TRANSITIONS = [
        'draft' => ['pending_approval'],
        'pending_approval' => ['approved', 'draft'],
        'approved' => ['published'],
        'published' => ['superseded'],
        'superseded' => [],
    ];

    public const VISIBILITIES = ['participants', 'department', 'organization', 'named'];

    public static function allowed(string $from, string $to): bool { return in_array($to, self::TRANSITIONS[$from] ?? [], true); }

    /** Only a draft can be edited, commented for change or have its reviewers changed. An approved wording is frozen. */
    public static function editable(string $status): bool { return $status === 'draft'; }

    /** Comments are welcome while the text is a draft or waiting for the chair. */
    public static function commentable(string $status): bool { return in_array($status, ['draft', 'pending_approval'], true); }

    /** Minutes can be started once the meeting is under way. */
    public static function canStart(string $meetingStatus): bool { return in_array($meetingStatus, ['in_progress', 'held', 'closed'], true); }

    /** A confidential meeting's minutes never go organization-wide or to whole departments: only participants or named people. */
    public static function allowedVisibilities(bool $confidentialMeeting): array
    {
        return $confidentialMeeting ? ['participants', 'named'] : self::VISIBILITIES;
    }

    /** @return string|null the recipient kind the visibility needs: "department" needs departments, "named" needs people */
    public static function recipientKind(string $visibility): ?string
    {
        return ['department' => 'department', 'named' => 'user'][$visibility] ?? null;
    }

    /** Text without markup, used to refuse an empty submission. */
    public static function plainLength(?string $html): int
    {
        return mb_strlen(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? ''));
    }
}
