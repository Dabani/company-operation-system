<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

/**
 * COPS bundle 37. Every role may open the meetings it is part of and decide the approvals assigned to it.
 *
 * "meeting.view" only means "see the meetings I organise, chair, keep the minutes of, or am invited to" (what a person sees is still decided
 * meeting by meeting), and "approval.act" only lets a person decide approvals that were assigned to them. Both were granted to a fixed list of
 * role codes, so a custom role such as "IT Manager" could be named chairperson and be notified, yet get a 403 on the meeting and could not
 * approve the minutes. This gives both to every existing role. New roles get them from RoleController (same bundle). Safe to run again.
 */
class MeetingBaselineAccessSeeder extends Seeder
{
    public const CODES = ['meeting.view', 'approval.view', 'approval.act'];

    public function run(): void
    {
        $ids = Permission::whereIn('code', self::CODES)->pluck('id')->all();
        if (!$ids) {
            return;
        }
        foreach (Organization::all() as $org) {
            Role::withoutGlobalScopes()->where('organization_id', $org->id)->get()
                ->each(fn (Role $r) => $r->permissions()->syncWithoutDetaching($ids));
        }
    }
}
