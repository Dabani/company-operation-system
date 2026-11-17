<?php

namespace App\Services\Meetings;

use App\Models\Employee;
use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Models\User;
use App\Models\UserDepartmentAssignment;
use App\Support\Lookup;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Who is invited and how they answer (Guide 6.4): users, whole departments, external guests. Hosts are always on the list. */
class InvitationService
{
    /** Active users of the active organization who belong to a department (employees with a login, and users assigned to it). */
    public function usersOfDepartments(array $departmentIds): Collection
    {
        if (!$departmentIds) {
            return collect();
        }
        $viaEmployee = Employee::whereIn('department_id', $departmentIds)->whereNotNull('user_id')->get(['user_id', 'department_id'])->map(fn ($e) => [$e->user_id, $e->department_id]);
        $viaAssignment = UserDepartmentAssignment::whereIn('department_id', $departmentIds)->get(['user_id', 'department_id'])->map(fn ($a) => [$a->user_id, $a->department_id]);
        $pairs = $viaEmployee->merge($viaAssignment)->unique(fn ($p) => $p[0]);
        $active = User::whereIn('id', $pairs->pluck(0))->whereHas('status', fn ($s) => $s->where('code', 'active'))->pluck('id');
        return $pairs->filter(fn ($p) => $active->contains($p[0]))->mapWithKeys(fn ($p) => [$p[0] => $p[1]]);   // userId => departmentId
    }

    /**
     * Add invitations (idempotent: nobody is invited twice).
     * @param array{user_ids?:string[], department_ids?:string[], guests?:array<int,array{name:string,email:string}>} $who
     * @return MeetingInvitation[] the invitations created by this call
     */
    public function add(Meeting $m, array $who): array
    {
        $pending = Lookup::id('rsvp_response', 'pending');
        $existingUsers = MeetingInvitation::where('meeting_id', $m->id)->whereNotNull('user_id')->pluck('user_id')->all();
        $existingEmails = MeetingInvitation::where('meeting_id', $m->id)->whereNotNull('guest_email')->pluck('guest_email')->map(fn ($e) => mb_strtolower($e))->all();
        $created = [];

        $targets = collect($who['user_ids'] ?? [])->mapWithKeys(fn ($id) => [$id => null]);                // userId => via department
        foreach ($this->usersOfDepartments($who['department_ids'] ?? []) as $userId => $deptId) {
            if (!$targets->has($userId)) {
                $targets[$userId] = $deptId;
            }
        }
        foreach ($targets as $userId => $deptId) {
            if (in_array($userId, $existingUsers, true)) {
                continue;
            }
            $created[] = MeetingInvitation::create(['meeting_id' => $m->id, 'user_id' => $userId, 'via_department_id' => $deptId, 'response_id' => $pending, 'token' => Str::random(48)]);
            $existingUsers[] = $userId;
        }
        foreach ($who['guests'] ?? [] as $g) {
            $email = mb_strtolower(trim($g['email']));
            if (in_array($email, $existingEmails, true)) {
                continue;
            }
            $created[] = MeetingInvitation::create(['meeting_id' => $m->id, 'guest_name' => trim($g['name']), 'guest_email' => $email, 'response_id' => $pending, 'token' => Str::random(48)]);
            $existingEmails[] = $email;
        }
        return $created;
    }

    /** Make sure the people who run the meeting are on its list. The organizer has, by organizing it, accepted. */
    public function ensureHosts(Meeting $m): array
    {
        $created = $this->add($m, ['user_ids' => array_values(array_unique(array_filter([$m->organizer_id, $m->chair_id, $m->secretary_id, $m->facilitator_id])))]);
        MeetingInvitation::where('meeting_id', $m->id)->where('user_id', $m->organizer_id)
            ->where('response_id', Lookup::id('rsvp_response', 'pending'))
            ->update(['response_id' => Lookup::id('rsvp_response', 'accepted'), 'responded_at' => now()]);
        return $created;
    }

    /** A new time invalidates earlier answers: everybody (except the organizer) is asked again. */
    public function resetResponses(Meeting $m): void
    {
        MeetingInvitation::where('meeting_id', $m->id)->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', '!=', $m->organizer_id))
            ->update(['response_id' => Lookup::id('rsvp_response', 'pending'), 'response_note' => null, 'proposed_starts_at' => null, 'responded_at' => null]);
        \Illuminate\Support\Facades\DB::table('meeting_reminder_log')->whereIn('invitation_id', MeetingInvitation::where('meeting_id', $m->id)->pluck('id'))->delete();
    }

    /** Invitees who already have another meeting at the same time (warning only: people may attend two things in a day, not usually at once). */
    public function busyElsewhere(Meeting $m): array
    {
        $userIds = MeetingInvitation::where('meeting_id', $m->id)->whereNotNull('user_id')->pluck('user_id');
        if ($userIds->isEmpty()) {
            return [];
        }
        $clashes = MeetingInvitation::whereIn('user_id', $userIds)->where('meeting_id', '!=', $m->id)
            ->whereHas('response', fn ($r) => $r->where('code', '!=', 'declined'))
            ->whereHas('meeting', fn ($x) => $x->whereHas('status', fn ($s) => $s->whereIn('code', ['draft', 'scheduled', 'in_progress']))
                ->where('starts_at', '<', $m->ends_at->utc()->format('Y-m-d H:i:s'))->where('ends_at', '>', $m->starts_at->utc()->format('Y-m-d H:i:s')))
            ->with('user:id,name')->get();
        return $clashes->unique('user_id')->map(fn ($i) => ['user_id' => $i->user_id, 'name' => $i->user?->name])->values()->all();
    }
}
