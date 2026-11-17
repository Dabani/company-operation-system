<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationContext;

/**
 * One answer to "what may this user do with this meeting?" (Guide 6.8). Server-side only; hiding a button is not a control.
 * A meeting outside the user's visibility is a 404, never a 403 (no existence leak).
 */
class MeetingAccess
{
    public function __construct(private OrganizationContext $ctx) {}

    public function can(string $permission): bool { return $this->ctx->can($permission); }

    /** @param string[] $with */
    public function find(User $u, string $id, array $with = []): Meeting
    {
        $m = Meeting::query()->visibleTo($u)->with($with)->whereKey($id)->first();
        abort_unless($m, 404);
        return $m;
    }

    public function invitation(User $u, Meeting $m): ?MeetingInvitation
    {
        return MeetingInvitation::where('meeting_id', $m->id)->where('user_id', $u->id)->first();
    }

    public function isParticipant(User $u, Meeting $m): bool
    {
        return $m->isHost($u) || $this->invitation($u, $m) !== null;
    }

    /** Organizer, secretary and holders of "manage all" run the meeting's paperwork. */
    public function canManage(User $u, Meeting $m): bool
    {
        return $m->organizer_id === $u->id || $m->secretary_id === $u->id || $this->can('meeting.manage_all');
    }

    public function canChair(User $u, Meeting $m): bool
    {
        return $m->chair_id === $u->id || $this->can('meeting.manage_all');
    }

    public function requireManage(User $u, Meeting $m): void { abort_unless($this->canManage($u, $m), 403, 'forbidden'); }

    /**
     * Seeing a confidential meeting only because of the "view confidential" permission (not as a host or invitee) is an audited event.
     * Call from detail endpoints, not from lists.
     */
    public function auditOverride(User $u, Meeting $m): void
    {
        if ($m->isConfidential() && !$this->isParticipant($u, $m)) {
            AuditLogger::log('meeting.confidential_viewed', $m, [], ['code' => $m->code]);
        }
    }

    /** Item-level confidentiality: hosts, the presenter and holders of "view confidential" see a confidential agenda item. */
    public function canSeeItem(User $u, Meeting $m, object $item): bool
    {
        return !$item->is_confidential || $m->isHost($u) || $item->presenter_id === $u->id || $this->can('meeting.view_confidential');
    }
}
