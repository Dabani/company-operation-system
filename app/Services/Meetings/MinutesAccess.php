<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingMinuteVersion;
use App\Models\User;
use App\Models\UserDepartmentAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who may read which minutes (Guide 6.6 and 6.8). Server-side only; a version the user may not read is a 404.
 * Draft and pending text: secretary, organizer, chair and the reviewers picked by the secretary. Approved (not yet published): the same people.
 * Published and superseded text: by the visibility chosen at publication.
 */
class MinutesAccess
{
    public function __construct(private MeetingAccess $meetings) {}

    public function isWriter(User $u, Meeting $m): bool { return $m->organizer_id === $u->id || $m->secretary_id === $u->id || $this->meetings->can('meeting.manage_all'); }

    public function isChair(User $u, Meeting $m): bool { return $m->chair_id === $u->id; }

    public function isReviewer(User $u, MeetingMinuteVersion $v): bool { return $v->reviewers()->where('user_id', $u->id)->exists(); }

    private function isHostOrWriter(User $u, Meeting $m): bool { return $this->isWriter($u, $m) || $this->isChair($u, $m); }

    public function canRead(User $u, Meeting $m, MeetingMinuteVersion $v): bool
    {
        $status = $v->status->code;
        if ($this->isHostOrWriter($u, $m)) {
            return true;
        }
        if (in_array($status, ['draft', 'pending_approval'], true)) {
            return $this->isReviewer($u, $v);
        }
        if ($status === 'approved') {
            return false;
        }
        return $this->canReadPublished($u, $m, $v);
    }

    public function canReadPublished(User $u, Meeting $m, MeetingMinuteVersion $v): bool
    {
        $vis = $v->visibility?->code;
        $participant = $this->meetings->isParticipant($u, $m);
        return match ($vis) {
            'participants' => $participant,
            'department' => $participant || $v->recipients()->whereIn('department_id', UserDepartmentAssignment::where('user_id', $u->id)->pluck('department_id'))->exists(),
            'organization' => true,
            'named' => $v->recipients()->where('user_id', $u->id)->exists(),
            default => false,
        };
    }

    /** Published and superseded versions the user may read (the minutes library). */
    public function scopeLibrary(Builder $q, User $u): Builder
    {
        $q->whereHas('status', fn ($s) => $s->whereIn('code', ['published', 'superseded']));
        if ($this->meetings->can('meeting.manage_all')) {
            return $q;
        }
        $depts = UserDepartmentAssignment::where('user_id', $u->id)->pluck('department_id');
        $inMeeting = fn ($m) => $m->where('organizer_id', $u->id)->orWhere('secretary_id', $u->id)->orWhere('chair_id', $u->id)->orWhere('facilitator_id', $u->id)
            ->orWhereHas('invitations', fn ($i) => $i->where('user_id', $u->id));
        return $q->where(function ($w) use ($u, $depts, $inMeeting) {
            $w->whereHas('minutes.meeting', fn ($m) => $m->where('organizer_id', $u->id)->orWhere('secretary_id', $u->id)->orWhere('chair_id', $u->id))
                ->orWhereHas('visibility', fn ($v) => $v->where('code', 'organization'))
                ->orWhere(fn ($x) => $x->whereHas('visibility', fn ($v) => $v->whereIn('code', ['participants', 'department']))->whereHas('minutes.meeting', $inMeeting))
                ->orWhere(fn ($x) => $x->whereHas('visibility', fn ($v) => $v->where('code', 'department'))->whereHas('recipients', fn ($r) => $r->whereIn('department_id', $depts)))
                ->orWhere(fn ($x) => $x->whereHas('visibility', fn ($v) => $v->where('code', 'named'))->whereHas('recipients', fn ($r) => $r->where('user_id', $u->id)));
        });
    }

    /** The people to tell when a version is published. Organization-wide: every active member. */
    public function recipients(Meeting $m, MeetingMinuteVersion $v): Collection
    {
        $vis = $v->visibility->code;
        $hosts = collect([$m->organizer_id, $m->chair_id, $m->secretary_id, $m->facilitator_id])->filter();
        $participants = fn () => $m->invitations()->whereNotNull('user_id')->pluck('user_id');
        $ids = match ($vis) {
            'participants' => $hosts->merge($participants()),
            'department' => $hosts->merge($participants())->merge(UserDepartmentAssignment::whereIn('department_id', $v->recipients()->whereNotNull('department_id')->pluck('department_id'))->pluck('user_id')),
            'named' => $hosts->merge($v->recipients()->whereNotNull('user_id')->pluck('user_id')),
            default => null,
        };
        $q = User::query()->whereHas('status', fn ($s) => $s->where('code', 'active'));
        if ($ids === null) {
            $q->whereHas('memberships', fn ($mm) => $mm->where('organization_id', $m->organization_id)->whereHas('status', fn ($s) => $s->where('code', 'active')));
        } else {
            $q->whereKey($ids->unique()->values());
        }
        return $q->with('language')->get();
    }
}
