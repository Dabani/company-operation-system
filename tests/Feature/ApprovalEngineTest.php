<?php

namespace Tests\Feature;

use App\Models\ApprovalAction;
use App\Models\ApprovalDelegation;
use App\Models\ApprovalRequest;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. */
class ApprovalEngineTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    protected function setUp(): void { parent::setUp(); $this->bootCops(); }

    private function submit($requester) { return app(ApprovalEngine::class)->submit('contract', '01JTESTSUBJECT0000000000AA', $requester, 'Test contract'); }

    public function test_no_rule_applies_so_the_safe_default_flow_is_used(): void
    {
        $r = $this->submit($this->makeUser('staff'));
        $this->assertTrue($r->used_default_flow);
        $this->assertSame('default_two_level', $r->flow->code);
    }


    public function test_two_step_chain_and_segregation(): void
    {
        $staff = $this->makeUser('staff');
        $admin1 = $this->makeUser('admin');
        $admin2 = $this->makeUser('admin');
        $engine = app(ApprovalEngine::class);
        $r = $this->submit($staff);                                   // no department head -> fallback to admins for step 1

        try { $engine->act($r->id, $staff, 'approve'); $this->fail('self approval allowed'); } catch (ValidationException $e) { $this->assertArrayHasKey('approval', $e->errors()); }

        $engine->act($r->id, $admin1, 'approve');
        try { $engine->act($r->id, $admin1, 'approve'); $this->fail('same person approved two steps'); } catch (ValidationException $e) { $this->assertArrayHasKey('approval', $e->errors()); }

        $done = $engine->act($r->id, $admin2, 'approve');
        $this->assertSame('approved', $done->status->code);
        $this->assertSame(2, ApprovalAction::where('request_id', $r->id)->count());
    }

    public function test_reject_requires_comment_and_decisions_are_immutable(): void
    {
        $staff = $this->makeUser('staff');
        $admin = $this->makeUser('admin');
        $engine = app(ApprovalEngine::class);
        $r = $this->submit($staff);
        try { $engine->act($r->id, $admin, 'reject'); $this->fail('reject without comment'); } catch (ValidationException $e) { $this->assertArrayHasKey('comment', $e->errors()); }
        $engine->act($r->id, $admin, 'reject', 'Not justified');
        $this->assertSame('rejected', $r->refresh()->status->code);
        $this->expectException(\LogicException::class);
        ApprovalAction::where('request_id', $r->id)->firstOrFail()->update(['comment' => 'tampered']);
    }

    public function test_delegate_can_act_and_context_is_recorded(): void
    {
        $staff = $this->makeUser('staff');
        $admin = $this->makeUser('admin');
        $this->makeUser('admin');                                     // another top-authority user so the step stays valid
        $deputy = $this->makeUser('team_leader');
        ApprovalDelegation::create(['delegator_id' => $admin->id, 'delegate_id' => $deputy->id, 'valid_from' => now()->subDay()->toDateString(), 'valid_to' => now()->addDay()->toDateString(), 'is_active' => true]);
        $r = $this->submit($staff);
        app(ApprovalEngine::class)->act($r->id, $deputy, 'approve');
        $this->assertSame($admin->id, ApprovalAction::where('request_id', $r->id)->firstOrFail()->on_behalf_of_id);
    }

    public function test_cannot_submit_twice_while_pending(): void
    {
        $staff = $this->makeUser('staff');
        $this->makeUser('admin');
        $this->submit($staff);
        $this->expectException(ValidationException::class);
        $this->submit($staff);
    }
}
