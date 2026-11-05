<?php

namespace Tests\Feature;

use App\Models\LegalParameter;
use App\Models\RuleSet;
use App\Services\Approvals\ApprovalEngine;
use App\Services\Rules\RuleLifecycle;
use App\Services\Rules\RuleResolver;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Not executed in the build environment - run `php artisan test`. */
class RulesEngineTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    protected function setUp(): void { parent::setUp(); $this->bootCops(); }

    private function set(string $code = 'purchase-approval', bool $legal = false): RuleSet
    {
        return RuleSet::create(['domain_id' => Lookup::id('rule_domain', 'approval'), 'code' => $code, 'name' => $code, 'is_legal_profile' => $legal]);
    }

    private function draft(RuleSet $set, $author, array $extra = [])
    {
        return app(RuleLifecycle::class)->createVersion($set, $extra + [
            'effective_from' => now()->subDay()->toDateString(), 'conditions' => [['attribute' => 'amount', 'operator' => 'gte', 'decimal' => '1000000.00']],
            'outcomes' => [['key' => 'approval_flow', 'approval_flow_id' => \App\Models\ApprovalFlow::where('code', 'default_two_level')->value('id')]],
        ], $author);
    }

    public function test_must_run_impact_check_before_submitting(): void
    {
        $author = $this->makeUser('admin');
        $v = $this->draft($this->set(), $author);
        $this->expectException(ValidationException::class);
        app(RuleLifecycle::class)->submit($v, $author);
    }

    public function test_rule_goes_live_only_after_independent_approval(): void
    {
        $author = $this->makeUser('admin');
        $manager = $this->makeUser('manager');
        $life = app(RuleLifecycle::class);
        $v = $this->draft($this->set(), $author);
        $life->impactCheck($v, [['amount' => '2000000.00', 'subject_type' => 'contract']], $author);
        $req = $life->submit($v->refresh(), $author);
        $this->assertSame('pending_approval', $v->refresh()->status->code);
        $this->assertNull(app(RuleResolver::class)->resolve('approval', ['amount' => '2000000.00']));      // not live yet

        app(ApprovalEngine::class)->act($req->id, $manager, 'approve');
        $this->assertSame('active', $v->refresh()->status->code);
        $this->assertSame($v->id, app(RuleResolver::class)->resolve('approval', ['amount' => '2000000.00'])->versionId);
    }

    public function test_statutory_floor_blocks_submission(): void
    {
        $author = $this->makeUser('admin');
        LegalParameter::create(['domain_id' => Lookup::id('rule_domain', 'leave'), 'bound_type_id' => Lookup::id('legal_bound', 'floor'),
            'code' => 'annual_leave_days_min', 'name' => 'Annual leave floor (test value)', 'value_decimal' => '18', 'valid_from' => '2026-01-01',
            'source_citation' => 'test fixture', 'verification_status_id' => Lookup::id('rule_verification_status', 'unverified')]);
        $set = RuleSet::create(['domain_id' => Lookup::id('rule_domain', 'leave'), 'code' => 'leave-policy', 'name' => 'Leave policy']);
        $life = app(RuleLifecycle::class);
        $v = $life->createVersion($set, ['effective_from' => '2026-01-01', 'outcomes' => [['key' => 'annual_leave_days_min', 'integer' => 15]]], $author);
        $life->impactCheck($v, [['employee_category' => 'contracted']], $author);
        $this->expectException(ValidationException::class);
        $life->submit($v->refresh(), $author);
    }

    public function test_legal_profile_cannot_activate_without_signoff(): void
    {
        $author = $this->makeUser('admin');
        $v = $this->draft($this->set('legal-set', true), $author);
        $this->assertNotEmpty(app(\App\Services\Rules\RuleValidator::class)->activationBlockers($v));
        $this->expectException(ValidationException::class);
        app(RuleLifecycle::class)->signOff($v, $author, 'self sign-off');                                 // author may not sign off
    }
}
