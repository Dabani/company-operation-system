<?php

namespace Tests\Feature;

use App\Models\Applicant;
use App\Models\ApprovalRequest;
use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmploymentRecord;
use App\Models\OnboardingItem;
use App\Models\Requisition;
use App\Models\User;
use App\Models\Vacancy;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs Bundle 15 (routes/recruitment.php, HrRecruitmentSeeder). */
class RecruitmentTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        Carbon::setTestNow('2026-11-09 09:00:00');
        $this->hr = $this->makeUser('hr_manager');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User { return User::where('email', 'admin@example.com')->firstOrFail(); }

    private function approve(string $approvalRequestId): void { app(ApprovalEngine::class)->act($approvalRequestId, $this->admin(), 'approve'); }

    private function dept(string $name = 'Finance'): string
    {
        Sanctum::actingAs($this->admin());
        return $this->postJson('/api/v1/departments', ['name' => $name])->assertCreated()->json('data.id');
    }

    /** A manager user who is also an employee, so they can sit on interview panels. */
    private function panelist(string $name): array
    {
        $u = $this->makeUser('manager');
        $e = Employee::create(['first_name' => $name, 'last_name' => 'Panel', 'category_id' => Lookup::id('employee_category', 'contracted'), 'status_id' => Lookup::id('employee_status', 'active'), 'user_id' => $u->id]);
        return [$u, $e];
    }

    /** Approved requisition + open vacancy. Returns [requisition id, vacancy id, department id]. */
    private function vacancy(int $headcount = 1): array
    {
        $dept = $this->dept();
        Sanctum::actingAs($this->hr);
        $req = $this->postJson('/api/v1/requisitions', ['department_id' => $dept, 'title' => 'Accountant', 'headcount' => $headcount, 'justification' => 'Growing workload'])
            ->assertCreated()->assertJsonPath('data.status', 'pending_approval')->assertJsonPath('data.code', 'REQ-0001')->json('data');
        $this->approve($req['approval_request_id']);
        Sanctum::actingAs($this->hr);
        $vac = $this->postJson("/api/v1/requisitions/{$req['id']}/vacancy", [])->assertCreated()->json('data.id');
        return [$req['id'], $vac, $dept];
    }

    private function applicant(string $vac, string $email = 'jean@example.com'): string
    {
        Sanctum::actingAs($this->hr);
        return $this->postJson("/api/v1/vacancies/$vac/applicants", ['first_name' => 'Jean', 'last_name' => 'Habimana', 'email' => $email, 'phone' => '0788000000', 'consent' => true])->assertCreated()->json('data.id');
    }

    /** Interview with one scorecard submitted by the panelist. Returns the interview id. */
    private function interviewed(string $app, array $panelist): string
    {
        [$u, $e] = $panelist;
        Sanctum::actingAs($this->hr);
        $iid = $this->postJson("/api/v1/applicants/$app/interviews", ['type' => 'interview', 'scheduled_at' => '2026-11-12 10:00:00', 'location' => 'Room 2', 'panel_employee_ids' => [$e->id]])->assertCreated()->json('data.id');
        Sanctum::actingAs($u);
        $this->postJson("/api/v1/interviews/$iid/scorecard", ['score' => 4, 'recommendation' => 'yes', 'strengths' => 'Solid'])->assertCreated();
        return $iid;
    }

    private function selected(string $app): void
    {
        Sanctum::actingAs($this->hr);
        $aid = $this->postJson("/api/v1/applicants/$app/selection")->assertCreated()->json('data.approval_request_id');
        $this->approve($aid);
    }

    private function offerBody(string $dept, array $extra = []): array
    {
        return $extra + ['category_id' => Lookup::id('employee_category', 'contracted'), 'contract_type_id' => Lookup::id('contract_type', 'permanent'), 'department_id' => $dept,
            'start_date' => '2026-12-01', 'probation_months' => 3, 'salary_amount' => '800000', 'salary_currency' => 'RWF', 'salary_period' => 'monthly', 'terms' => 'Standard terms'];
    }

    public function test_full_journey_from_requisition_to_employee_with_onboarding(): void
    {
        $dept = $this->dept();
        $panel = $this->panelist('Pan');
        Sanctum::actingAs($this->hr);
        $req = $this->postJson('/api/v1/requisitions', ['department_id' => $dept, 'title' => 'Accountant', 'headcount' => 1, 'justification' => 'Growing workload'])->assertCreated()->json('data');
        $this->postJson("/api/v1/requisitions/{$req['id']}/vacancy", [])->assertStatus(422);                 // not approved yet
        $this->approve($req['approval_request_id']);
        Sanctum::actingAs($this->hr);
        $vac = $this->postJson("/api/v1/requisitions/{$req['id']}/vacancy", [])->assertCreated()->assertJsonPath('data.code', 'VAC-0001')->json('data.id');
        $this->postJson("/api/v1/requisitions/{$req['id']}/vacancy", [])->assertStatus(422);                 // one vacancy per requisition

        $app = $this->applicant($vac);
        $this->postJson("/api/v1/applicants/$app/stage", ['stage' => 'screened'])->assertOk()->assertJsonPath('data.stage', 'screened');
        $this->postJson("/api/v1/applicants/$app/selection")->assertStatus(422);                                // no interview/scorecard yet
        $this->interviewed($app, $panel);
        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/applicants/$app")->assertOk()->assertJsonPath('data.stage', 'interview')->assertJsonPath('data.code', 'APL-0001');

        $this->selected($app);
        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/applicants/$app")->assertJsonPath('data.stage', 'offer');

        $offer = $this->postJson("/api/v1/applicants/$app/offers", $this->offerBody($dept))->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.code', 'OFR-0001')->json('data.id');
        $this->postJson("/api/v1/applicants/$app/convert")->assertStatus(422);                                 // no accepted offer yet
        $this->postJson("/api/v1/offers/$offer/issue")->assertOk()->assertJsonPath('data.status', 'issued')->assertJsonPath('data.valid_until', '2026-11-23');
        $this->postJson("/api/v1/offers/$offer/respond", ['decision' => 'accepted', 'responded_on' => '2026-11-09'])->assertOk()->assertJsonPath('data.status', 'accepted');

        $conv = $this->postJson("/api/v1/applicants/$app/convert")->assertCreated()->json('data');
        $e = Employee::findOrFail($conv['employee_id']);
        $this->assertSame('Jean', $e->first_name);
        $this->assertSame('2026-12-01', $e->hired_on->toDateString());
        $this->assertSame('2027-03-01', $e->probation_end_on->toDateString());
        $this->assertSame(Lookup::id('employee_category', 'contracted'), $e->category_id);
        $hire = EmploymentRecord::where('employee_id', $e->id)->firstOrFail();
        $this->assertSame('800000.00', $hire->salary_amount);                                                 // the offer's salary is on the hire row
        $this->assertSame(Lookup::id('contract_type', 'permanent'), $hire->contract_type_id);
        $this->assertSame(6, OnboardingItem::where('employee_id', $e->id)->count());                          // the default checklist
        $this->assertSame('filled', Requisition::find($req['id'])->status->code);
        $this->assertSame('closed', Vacancy::find($vac)->status->code);
        $this->assertSame('hired', Applicant::find($app)->stage->code);
        $this->assertNull(Applicant::find($app)->retention_until);
        $this->postJson("/api/v1/applicants/$app/convert")->assertStatus(422);                                 // not twice
    }

    public function test_applicants_need_consent_and_cannot_apply_twice(): void
    {
        [, $vac] = $this->vacancy();
        Sanctum::actingAs($this->hr);
        $body = ['first_name' => 'A', 'last_name' => 'B', 'email' => 'a@example.com'];
        $this->postJson("/api/v1/vacancies/$vac/applicants", $body + ['consent' => false])->assertStatus(422);
        $this->postJson("/api/v1/vacancies/$vac/applicants", $body + ['consent' => true])->assertCreated();
        $this->postJson("/api/v1/vacancies/$vac/applicants", ['email' => 'A@Example.com'] + $body + ['consent' => true])->assertStatus(422);
    }

    public function test_selection_respects_the_headcount(): void
    {
        [, $vac, $dept] = $this->vacancy(1);
        $panel = $this->panelist('Pan');
        $a = $this->applicant($vac, 'a@example.com');
        $b = $this->applicant($vac, 'b@example.com');
        $this->interviewed($a, $panel);
        $this->interviewed($b, $panel);
        $this->selected($a);
        Sanctum::actingAs($this->hr);
        $this->postJson("/api/v1/applicants/$b/selection")->assertStatus(422);                                 // the only place already has a selected applicant
    }

    public function test_offer_outside_the_pay_band_needs_a_reason(): void
    {
        [, $vac, $dept] = $this->vacancy();
        $panel = $this->panelist('Pan');
        $app = $this->applicant($vac);
        $this->interviewed($app, $panel);
        $this->selected($app);
        Sanctum::actingAs($this->hr);
        $grade = $this->postJson('/api/v1/hr/grades', ['name' => 'Officer', 'level' => 2, 'salary_min' => 300000, 'salary_max' => 500000, 'currency_code' => 'RWF'])->assertCreated()->json('data.id');
        $body = $this->offerBody($dept, ['grade_id' => $grade]);
        $this->postJson("/api/v1/applicants/$app/offers", $body)->assertStatus(422);
        $this->postJson("/api/v1/applicants/$app/offers", $body + ['band_exception_reason' => 'Scarce skill'])->assertCreated()->assertJsonPath('data.band_exception', true);
    }

    public function test_scorecards_stay_private_until_the_panelist_has_scored(): void
    {
        [, $vac] = $this->vacancy();
        [$u1, $e1] = $this->panelist('One');
        [$u2, $e2] = $this->panelist('Two');
        [$u3] = $this->panelist('Three');
        $app = $this->applicant($vac);
        Sanctum::actingAs($this->hr);
        $iid = $this->postJson("/api/v1/applicants/$app/interviews", ['type' => 'interview', 'scheduled_at' => '2026-11-12 10:00:00', 'panel_employee_ids' => [$e1->id, $e2->id]])->assertCreated()->json('data.id');

        Sanctum::actingAs($u1);
        $this->postJson("/api/v1/interviews/$iid/scorecard", ['score' => 5, 'recommendation' => 'strong_yes'])->assertCreated();
        $this->postJson("/api/v1/interviews/$iid/scorecard", ['score' => 2, 'recommendation' => 'no'])->assertStatus(422);                // once only
        Sanctum::actingAs($u2);
        $this->assertCount(0, $this->getJson("/api/v1/interviews/$iid")->assertOk()->json('data.scorecards'));                       // not submitted yet: cannot see the others
        $this->postJson("/api/v1/interviews/$iid/scorecard", ['score' => 3, 'recommendation' => 'yes'])->assertCreated();
        $this->assertCount(2, $this->getJson("/api/v1/interviews/$iid")->json('data.scorecards'));
        $this->assertSame('completed', $this->getJson("/api/v1/interviews/$iid")->json('data.status'));                              // the whole panel has scored
        Sanctum::actingAs($u3);
        $this->getJson("/api/v1/interviews/$iid")->assertNotFound();                                                                  // not on the panel
        $this->postJson("/api/v1/interviews/$iid/scorecard", ['score' => 4, 'recommendation' => 'yes'])->assertNotFound();
    }

    public function test_declined_offer_rejects_the_applicant_and_retention_follows_the_setting(): void
    {
        [, $vac, $dept] = $this->vacancy();
        $panel = $this->panelist('Pan');
        $app = $this->applicant($vac);
        $this->interviewed($app, $panel);
        $this->selected($app);
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/hr/settings', ['applicant_retention_months' => 6])->assertOk();
        $this->putJson('/api/v1/hr/settings', ['applicant_retention_months' => 0])->assertStatus(422);
        $offer = $this->postJson("/api/v1/applicants/$app/offers", $this->offerBody($dept))->json('data.id');
        $this->postJson("/api/v1/offers/$offer/issue")->assertOk();
        $this->postJson("/api/v1/offers/$offer/respond", ['decision' => 'declined', 'responded_on' => '2026-11-09'])->assertOk();
        $a = Applicant::find($app);
        $this->assertSame('rejected', $a->stage->code);
        $this->assertSame('2027-05-09', $a->retention_until->toDateString());
        $this->postJson("/api/v1/applicants/$app/convert")->assertStatus(422);
    }

    public function test_retention_review_flags_once_and_anonymising_erases_identity(): void
    {
        [, $vac] = $this->vacancy();
        $app = $this->applicant($vac);
        Sanctum::actingAs($this->hr);
        $this->getJson("/api/v1/applicants/$app")->assertOk();
        $this->assertTrue(AuditLog::where('action', 'applicant.viewed')->exists());                         // reading a person's application is audited
        $this->postJson("/api/v1/applicants/$app/anonymize")->assertStatus(422);                            // still open and not yet due
        $this->postJson("/api/v1/applicants/$app/reject", ['reason' => 'Not enough experience'])->assertOk();

        Carbon::setTestNow('2027-12-01 09:00:00');                                                           // past the default 12 months
        $this->artisan('cops:hr-retention-review')->assertSuccessful();
        $this->artisan('cops:hr-retention-review')->assertSuccessful();
        $this->assertSame(1, AppNotification::where('user_id', $this->hr->id)->where('link', '/hr/recruitment/retention')->count());      // flagged once
        $this->assertSame(1, Applicant::whereNotNull('retention_flagged_at')->count());
        Sanctum::actingAs($this->hr);
        $this->getJson('/api/v1/applicants?retention_due=1')->assertOk()->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/applicants/$app/anonymize")->assertOk();
        $a = Applicant::find($app);
        $this->assertSame('Anonymised', $a->first_name);
        $this->assertNull($a->phone);
        $this->assertNotSame('jean@example.com', $a->email);
        $this->assertGreaterThan(0, $a->history()->count());                                                  // the funnel history stays, without identity
        $this->getJson('/api/v1/applicants?retention_due=1')->assertJsonCount(0, 'data');
    }

    public function test_offer_letter_pdf_in_the_requested_language(): void
    {
        [, $vac, $dept] = $this->vacancy();
        $panel = $this->panelist('Pan');
        $app = $this->applicant($vac);
        $this->interviewed($app, $panel);
        $this->selected($app);
        Sanctum::actingAs($this->hr);
        $offer = $this->postJson("/api/v1/applicants/$app/offers", $this->offerBody($dept))->json('data.id');
        $r = $this->get("/api/v1/offers/$offer/pdf?lang=fr");
        $r->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertTrue(AuditLog::where('action', 'offer.pdf_downloaded')->exists());
    }

    public function test_staff_cannot_reach_recruitment_data(): void
    {
        $this->vacancy();
        Sanctum::actingAs($this->makeUser('staff'));
        $this->getJson('/api/v1/applicants')->assertForbidden();
        $this->getJson('/api/v1/vacancies')->assertForbidden();
        $this->getJson('/api/v1/offers')->assertForbidden();
        $this->postJson('/api/v1/requisitions', ['title' => 'x'])->assertForbidden();
    }

    public function test_the_new_employee_sees_and_completes_their_own_onboarding_checklist(): void
    {
        [, $vac, $dept] = $this->vacancy();
        $panel = $this->panelist('Pan');
        $app = $this->applicant($vac);
        $this->interviewed($app, $panel);
        $this->selected($app);
        Sanctum::actingAs($this->hr);
        $offer = $this->postJson("/api/v1/applicants/$app/offers", $this->offerBody($dept))->json('data.id');
        $this->postJson("/api/v1/offers/$offer/issue")->assertOk();
        $this->postJson("/api/v1/offers/$offer/respond", ['decision' => 'accepted', 'responded_on' => '2026-11-09'])->assertOk();
        $empId = $this->postJson("/api/v1/applicants/$app/convert")->assertCreated()->json('data.employee_id');

        $staff = $this->makeUser('staff');
        Employee::find($empId)->update(['user_id' => $staff->id]);
        Sanctum::actingAs($staff);
        $items = $this->getJson("/api/v1/employees/$empId/onboarding")->assertOk()->json('data');
        $this->assertCount(6, $items);
        $this->assertSame('2026-12-01', $items[0]['due_on']);
        $this->postJson("/api/v1/onboarding-items/{$items[0]['id']}/complete")->assertOk()->assertJsonPath('data.done', true);
        Sanctum::actingAs($this->makeUser('staff'));
        $this->getJson("/api/v1/employees/$empId/onboarding")->assertNotFound();                                 // somebody else's checklist
        $this->postJson("/api/v1/onboarding-items/{$items[1]['id']}/complete")->assertNotFound();
    }
}
