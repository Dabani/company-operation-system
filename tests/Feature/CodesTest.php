<?php

namespace Tests\Feature;

use App\Models\ApprovalFlow;
use App\Models\Department;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\Project;
use App\Models\RuleSet;
use App\Models\User;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Identifiers are system-generated, unique, never reused and immutable. Needs Bundles 08-13 and CopsTestHelpers. */
class CodesTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($this->admin);
    }

    public function test_project_codes_are_generated_sequentially_and_a_typed_code_is_ignored(): void
    {
        $a = $this->postJson('/api/v1/projects', ['name' => 'Alpha', 'code' => 'HAND-TYPED'])->assertCreated()->json('data.code');
        $b = $this->postJson('/api/v1/projects', ['name' => 'Beta'])->assertCreated()->json('data.code');
        $this->assertSame('PRJ-0001', $a);
        $this->assertSame('PRJ-0002', $b);
    }

    public function test_code_cannot_be_changed_afterwards(): void
    {
        $id = $this->postJson('/api/v1/projects', ['name' => 'Alpha'])->json('data.id');
        $this->patchJson("/api/v1/projects/$id", ['name' => 'Alpha 2', 'code' => 'CHANGED'])->assertOk();
        $this->assertSame('PRJ-0001', Project::find($id)->code);
    }

    public function test_generator_skips_codes_that_already_exist(): void
    {
        Project::create(['code' => 'PRJ-0001', 'name' => 'Legacy', 'owner_id' => $this->admin->id, 'status_id' => Lookup::id('project_status', 'active')]);
        Project::create(['code' => 'PRJ-0002', 'name' => 'Legacy 2', 'owner_id' => $this->admin->id, 'status_id' => Lookup::id('project_status', 'active')]);
        $this->assertSame('PRJ-0003', $this->postJson('/api/v1/projects', ['name' => 'New'])->json('data.code'));
    }

    public function test_other_entities_get_their_own_prefixes(): void
    {
        $this->assertSame('CLI-0001', $this->postJson('/api/v1/clients', ['name' => 'Acme', 'code' => 'X'])->assertCreated()->json('data.code'));
        $this->assertSame('DEP-0001', $this->postJson('/api/v1/departments', ['name' => 'Finance', 'code' => 'FIN'])->assertCreated()->json('data.code'));
        $emp = $this->postJson('/api/v1/employees', ['first_name' => 'Ada', 'last_name' => 'L', 'employee_number' => 'E-99', 'category_id' => Lookup::id('employee_category', 'contracted')])->assertCreated();
        $this->assertSame('EMP-0001', $emp->json('data.employee_number'));
    }

    public function test_rule_sets_and_flows_are_generated_and_system_sets_keep_their_key(): void
    {
        $this->assertTrue(RuleSet::where('code', 'invoice-approval')->exists());                                  // seeded system key
        $id = $this->postJson('/api/v1/rule-sets', ['domain_id' => Lookup::id('rule_domain', 'billing'), 'name' => 'Purchases', 'code' => 'mine'])->assertCreated()->json('id');
        $this->assertSame('RUL-0001', RuleSet::find($id)->code);

        $flow = $this->postJson('/api/v1/approval-flows', ['name' => 'Two steps', 'effective_from' => '2026-01-01', 'code' => 'x',
            'steps' => [['label' => 'Head', 'step_type_id' => Lookup::id('approval_step_type', 'department_head')]]])->assertCreated();
        $this->assertSame('FLW-0001', $flow->json('data.code'));
        $v2 = $this->postJson('/api/v1/approval-flows', ['name' => 'Two steps', 'effective_from' => '2026-06-01', 'new_version_of' => $flow->json('data.id'),
            'steps' => [['label' => 'Head', 'step_type_id' => Lookup::id('approval_step_type', 'department_head')]]])->assertCreated();
        $this->assertSame('FLW-0001', $v2->json('data.code'));
        $this->assertSame(2, $v2->json('data.version'));
    }

    public function test_lookup_keys_are_generated_except_currencies(): void
    {
        $type = LookupType::where('code', 'project_role')->value('id');
        $mk = fn ($label) => $this->postJson('/api/v1/lookup-values', ['lookup_type_id' => $type, 'code' => 'typed', 'labels' => ['en' => $label]]);
        $this->assertSame('observer', $mk('Observer')->assertCreated()->json('data.code'));
        $this->assertSame('observer_2', $mk('Observer')->assertCreated()->json('data.code'));

        $cur = LookupType::where('code', 'currency')->value('id');
        $this->postJson('/api/v1/lookup-values', ['lookup_type_id' => $cur, 'labels' => ['en' => 'Japanese yen']])->assertStatus(422);                       // ISO code required
        $this->postJson('/api/v1/lookup-values', ['lookup_type_id' => $cur, 'code' => 'jpy', 'labels' => ['en' => 'Japanese yen']])->assertStatus(422);       // must be upper-case ISO
        $this->postJson('/api/v1/lookup-values', ['lookup_type_id' => $cur, 'code' => 'JPY', 'labels' => ['en' => 'Japanese yen']])->assertCreated()->assertJsonPath('data.code', 'JPY');
    }

    public function test_legal_parameter_key_comes_from_the_name_and_new_values_keep_it(): void
    {
        $base = ['domain_id' => Lookup::id('rule_domain', 'leave'), 'bound_type_id' => Lookup::id('legal_bound', 'floor'), 'name' => 'Annual leave minimum', 'value_decimal' => 18,
            'valid_from' => '2026-01-01', 'source_citation' => 'test fixture', 'code' => 'ignored'];
        $first = $this->postJson('/api/v1/legal-parameters', $base)->assertCreated()->json('id');
        $this->assertSame('annual_leave_minimum', \App\Models\LegalParameter::find($first)->code);
        $second = $this->postJson('/api/v1/legal-parameters', ['valid_from' => '2027-01-01', 'value_decimal' => 20, 'new_value_of' => $first] + $base)->assertCreated()->json('id');
        $this->assertSame('annual_leave_minimum', \App\Models\LegalParameter::find($second)->code);                                                   // same parameter, new value
        $third = $this->postJson('/api/v1/legal-parameters', ['name' => 'Annual leave minimum', 'valid_from' => '2026-01-01'] + $base)->assertCreated()->json('id');
        $this->assertSame('annual_leave_minimum_2', \App\Models\LegalParameter::find($third)->code);                                                   // a different parameter with the same name
    }
}
