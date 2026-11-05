<?php

namespace Tests\Feature;

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Database\Seeders\DatabaseSeeder;

/** Shared setup for the COPS Phase 0 feature tests. Needs the MySQL test database (see .env.testing.example). */
trait CopsTestHelpers
{
    protected Organization $org;

    protected function bootCops(): void
    {
        config(['ops.seed_admin_password' => 'Test-Password-123!', 'ops.seed_admin_email' => 'admin@example.com']);
        $this->seed(DatabaseSeeder::class);
        $this->org = Organization::firstOrFail();
        app(OrganizationContext::class)->resolveForOrganization($this->org);   // model queries in the test body are tenant-scoped
    }

    protected function makeUser(string $roleCode, ?string $name = null): User
    {
        $name ??= ucfirst($roleCode) . ' ' . substr(md5((string) microtime(true) . $roleCode), 0, 6);
        $u = User::create(['name' => $name, 'email' => strtolower(str_replace(' ', '.', $name)) . '@example.com', 'password' => 'Str0ng-Passw0rd!x',
            'status_id' => Lookup::id('user_status', 'active')]);
        $m = Membership::create(['organization_id' => $this->org->id, 'user_id' => $u->id, 'status_id' => Lookup::id('membership_status', 'active'), 'is_default' => true]);
        $m->roles()->attach(Role::withoutGlobalScopes()->where('organization_id', $this->org->id)->where('code', $roleCode)->firstOrFail()->id);
        return $u;
    }
}
