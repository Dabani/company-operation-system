<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientIdentity;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LookupValue;
use App\Models\UserDepartmentAssignment;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment - run `php artisan test`. */
class PortalAndScopeTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    protected function setUp(): void { parent::setUp(); $this->bootCops(); }

    private function client(string $code): array
    {
        $c = Client::create(['code' => $code, 'name' => $code, 'status_id' => Lookup::id('client_status', 'active'), 'portal_enabled' => true]);
        $contact = ClientContact::create(['client_id' => $c->id, 'name' => "Contact $code"]);
        $identity = ClientIdentity::create(['client_id' => $c->id, 'email' => strtolower($code) . '@client.test', 'password' => 'Str0ng-Passw0rd!x', 'is_active' => true]);
        return [$c, $contact, $identity];
    }

    /** The SPA is a "stateful" Sanctum frontend; in tests that is signalled by the Referer header. */
    private function spa(): static
    {
        config(['sanctum.stateful' => ['spa.test']]);                 // do not depend on the developer's SANCTUM_STATEFUL_DOMAINS
        return $this->withHeaders(['Referer' => 'http://spa.test']);
    }

    public function test_portal_login_works_and_wrong_password_is_refused(): void
    {
        [, , $id] = $this->client('ACME');
        $this->spa()->postJson('/api/v1/portal/auth/login', ['organization' => $this->org->slug, 'email' => 'ACME@client.test', 'password' => 'wrong-password'])->assertStatus(422);
        $this->spa()->postJson('/api/v1/portal/auth/login', ['organization' => $this->org->slug, 'email' => 'ACME@client.test', 'password' => 'Str0ng-Passw0rd!x'])->assertOk();
        $this->assertNotNull($id->refresh()->last_login_at);
    }

    public function test_client_cannot_read_another_clients_contact_by_guessing_the_id(): void
    {
        [, $contactA, $idA] = $this->client('ACME');
        [, $contactB] = $this->client('GLOBEX');
        $this->actingAs($idA, 'client');
        $this->getJson("/api/v1/portal/contacts/{$contactA->id}")->assertOk();
        $this->getJson("/api/v1/portal/contacts/{$contactB->id}")->assertNotFound();
        $this->getJson('/api/v1/portal/contacts')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/employees')->assertUnauthorized();                                          // a portal session is not a staff session
    }

    public function test_disabled_portal_is_refused(): void
    {
        [$c, , $id] = $this->client('ACME');
        $c->update(['portal_enabled' => false]);
        $this->actingAs($id, 'client');
        $this->getJson('/api/v1/portal/me')->assertForbidden();
    }

    public function test_department_scope_limits_employee_lists_and_hides_outside_records(): void
    {
        $dA = Department::create(['code' => 'A', 'name' => 'Dept A']);
        $dB = Department::create(['code' => 'B', 'name' => 'Dept B']);
        $mk = fn ($n, $d) => Employee::create(['employee_number' => $n, 'first_name' => $n, 'last_name' => 'T', 'department_id' => $d->id,
            'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active')]);
        $a = $mk('E-A', $dA);
        $b = $mk('E-B', $dB);

        $lead = $this->makeUser('team_leader');
        UserDepartmentAssignment::create(['user_id' => $lead->id, 'department_id' => $dA->id, 'is_head' => true,
            'scope_level_id' => LookupValue::whereHas('type', fn ($q) => $q->where('code', 'scope_level'))->where('code', 'department')->value('id')]);

        Sanctum::actingAs($lead);
        $this->getJson('/api/v1/employees')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/employees/{$a->id}")->assertOk();
        $this->getJson("/api/v1/employees/{$b->id}")->assertNotFound();                                     // 404, not 403
    }

    public function test_sensitive_fields_are_hidden_encrypted_and_audited(): void
    {
        $admin = \App\Models\User::where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);
        $id = $this->postJson('/api/v1/employees', ['employee_number' => 'E-1', 'first_name' => 'Ada', 'last_name' => 'L', 'national_id' => '1199880012345678',
            'category_id' => Lookup::id('employee_category', 'contracted')])->assertCreated()->assertJsonMissingPath('data.national_id')->json('data.id');
        $this->assertStringNotContainsString('1199880012345678', (string) \DB::table('hr_employees')->where('id', $id)->value('national_id'));   // ciphertext at rest
        $this->getJson("/api/v1/employees/$id/sensitive")->assertOk()->assertJsonPath('national_id', '1199880012345678');
        $this->assertDatabaseHas('audit_logs', ['action' => 'employee.sensitive_viewed', 'entity_id' => $id]);
    }
}
