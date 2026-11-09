<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\RateCardLine;
use App\Models\Task;
use App\Models\TaxRate;
use App\Models\TimeEntry;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Needs Bundles 08-11 and the CopsTestHelpers trait. Run: php artisan test */
class BillingTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $manager;
    private User $admin;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        $this->manager = $this->makeUser('manager');
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        SettingsService::set($this->org->id, 'billing.rounding_minutes', 15);
        $client = Client::create(['code' => 'ACME', 'name' => 'Acme Ltd', 'status_id' => Lookup::id('client_status', 'active'), 'address' => 'Kigali', 'tax_number' => 'TIN-1']);
        $card = RateCard::create(['name' => 'Std', 'currency_code' => 'RWF']);
        RateCardLine::create(['rate_card_id' => $card->id, 'scope_id' => Lookup::id('rate_scope', 'default'), 'hourly_rate' => '25000.00', 'effective_from' => '2026-01-01']);
        $this->project = Project::create(['code' => 'PRJ', 'name' => 'Portal', 'owner_id' => $this->manager->id, 'status_id' => Lookup::id('project_status', 'active'),
            'client_id' => $client->id, 'rate_card_id' => $card->id, 'billing_model_id' => Lookup::id('billing_model', 'time_and_materials'), 'currency_code' => 'RWF']);
        foreach ([7620, 3600] as $i => $secs) {                                                   // 2h07 + 1h = 187 min -> 180 min at 15-min rounding
            TimeEntry::create(['user_id' => $this->manager->id, 'project_id' => $this->project->id, 'work_date' => '2026-11-0' . ($i + 2), 'started_at' => '2026-11-0' . ($i + 2) . ' 08:00:00',
                'ended_at' => '2026-11-0' . ($i + 2) . ' 10:00:00', 'duration_seconds' => $secs, 'is_billable' => true, 'status_id' => Lookup::id('time_entry_status', 'approved')]);
        }
    }

    private function tax(bool $verified): TaxRate
    {
        return TaxRate::create(['name' => 'VAT (test value)', 'rate_percent' => '18.0000', 'valid_from' => '2026-01-01', 'is_default' => true, 'source_citation' => 'test fixture',
            'verification_status_id' => Lookup::id('rule_verification_status', $verified ? 'verified' : 'unverified')]);
    }

    private function draftId(): string
    {
        Sanctum::actingAs($this->manager);
        return $this->postJson("/api/v1/projects/{$this->project->id}/invoices/generate")->assertCreated()->json('data.id');
    }

    private function issue(string $id): Invoice
    {
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/v1/invoices/$id/issue")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $req = ApprovalRequest::where('subject_id', $id)->firstOrFail();
        app(ApprovalEngine::class)->act($req->id, $this->admin, 'approve');
        return Invoice::findOrFail($id);
    }

    public function test_draft_uses_rate_card_rounds_once_and_totals_are_reproducible(): void
    {
        $this->tax(true);
        $id = $this->draftId();
        $inv = Invoice::with('lines.sources')->findOrFail($id);
        $this->assertSame('75000.00', (string) $inv->subtotal);                        // 180 min / 60 x 25,000
        $this->assertSame('13500.00', (string) $inv->tax_total);
        $this->assertSame('88500.00', (string) $inv->total);
        $this->assertCount(1, $inv->lines);
        $this->assertSame(11220, $inv->lines[0]->raw_seconds);
        $this->assertCount(2, $inv->lines[0]->sources);                                // source entries are stored
        $this->postJson("/api/v1/projects/{$this->project->id}/invoices/generate")->assertStatus(422);        // one open draft per project
    }

    public function test_entries_without_a_rate_block_the_draft(): void
    {
        RateCardLine::query()->update(['effective_from' => '2027-01-01']);             // no rate applies to the November entries
        Sanctum::actingAs($this->manager);
        $this->postJson("/api/v1/projects/{$this->project->id}/invoices/generate")->assertStatus(422);
    }

    public function test_unverified_tax_blocks_issue_and_approval_issues_with_a_gap_free_number(): void
    {
        $tax = $this->tax(false);
        $id = $this->draftId();
        $this->postJson("/api/v1/invoices/$id/issue")->assertStatus(422);              // tax not signed off
        $tax->update(['verification_status_id' => Lookup::id('rule_verification_status', 'verified')]);

        $inv = $this->issue($id);
        $this->assertSame('issued', $inv->status->code);
        $this->assertSame('INV-' . date('Y') . '-0001', $inv->number);
        $this->assertSame(2, TimeEntry::where('project_id', $this->project->id)->whereHas('status', fn ($s) => $s->where('code', 'invoiced'))->count());
        $this->assertSame('88500.00', (string) $inv->balance);
    }

    public function test_issued_invoice_is_immutable_and_never_deleted(): void
    {
        $this->tax(true);
        $inv = $this->issue($this->draftId());
        try { $inv->update(['notes' => 'tampered']); $this->fail('issued invoice was edited'); } catch (\LogicException $e) { $this->assertTrue(true); }
        $this->expectException(\LogicException::class);
        $inv->delete();
    }

    public function test_partial_payment_overpayment_and_reversal(): void
    {
        $this->tax(true);
        $id = $this->issue($this->draftId())->id;
        $method = Lookup::id('payment_method', 'bank_transfer');
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/invoices/$id/payments", ['amount' => 50000, 'paid_on' => date('Y-m-d'), 'method_id' => $method, 'reference' => 'TX1'])->assertOk()->assertJsonPath('data.status', 'partially_paid');
        $this->postJson("/api/v1/invoices/$id/payments", ['amount' => 40000, 'paid_on' => date('Y-m-d'), 'method_id' => $method])->assertStatus(422);       // exceeds 38,500 balance
        $this->postJson("/api/v1/invoices/$id/payments", ['amount' => 38500, 'paid_on' => date('Y-m-d'), 'method_id' => $method])->assertOk()->assertJsonPath('data.status', 'paid');
        $first = InvoicePayment::where('invoice_id', $id)->where('amount', '>', 0)->orderBy('created_at')->first();
        $this->postJson("/api/v1/invoices/$id/payments/{$first->id}/reverse", ['reason' => 'Bounced transfer'])->assertOk()->assertJsonPath('data.status', 'partially_paid');
        $this->expectException(\LogicException::class);
        $first->update(['amount' => 1]);                                                                                  // payments are immutable
    }

    public function test_credit_note_needs_independent_approval_and_reduces_the_balance(): void
    {
        $this->tax(true);
        $id = $this->issue($this->draftId())->id;
        Sanctum::actingAs($this->admin);
        $second = $this->makeUser('admin');                                                                                           // an independent approver must exist (nobody approves their own request)
        $this->postJson("/api/v1/invoices/$id/credit-notes", ['amount' => 80000, 'reason' => 'Too much'])->assertStatus(422);       // exceeds the 75,000 subtotal
        $this->postJson("/api/v1/invoices/$id/credit-notes", ['amount' => 25000, 'reason' => 'Disputed hours'])->assertOk();
        $this->assertSame('88500.00', (string) Invoice::find($id)->balance);                                                          // not issued until approved
        $req = ApprovalRequest::where('subject_type_id', Lookup::id('approval_subject_type', 'credit_note'))->firstOrFail();
        app(ApprovalEngine::class)->act($req->id, $second, 'approve');
        $this->assertSame('59000.00', (string) Invoice::find($id)->balance);                                                          // 88,500 - (25,000 + 4,500 tax)
    }

    public function test_deliver_and_close_gates(): void
    {
        $this->tax(true);
        Sanctum::actingAs($this->manager);
        $task = Task::create(['project_id' => $this->project->id, 'number' => 1, 'title' => 'Open', 'status_id' => Lookup::id('task_status', 'todo'),
            'priority_id' => Lookup::id('task_priority', 'normal'), 'reporter_id' => $this->manager->id]);
        $this->postJson("/api/v1/projects/{$this->project->id}/deliver")->assertStatus(422);                                         // unresolved task
        $task->update(['status_id' => Lookup::id('task_status', 'done')]);
        $this->postJson("/api/v1/projects/{$this->project->id}/deliver")->assertOk()->assertJsonPath('data.status', 'delivered')->assertJson(fn ($j) => $j->has('data.invoice_id')->etc());
        $this->postJson("/api/v1/projects/{$this->project->id}/close")->assertStatus(422);                                           // draft invoice not issued
        $this->postJson("/api/v1/projects/{$this->project->id}/close", ['exception_reason' => 'Client agreed to invoice next quarter'])->assertForbidden();   // manager lacks the exception permission
        Sanctum::actingAs($this->admin);
        $this->postJson("/api/v1/projects/{$this->project->id}/close", ['exception_reason' => 'Client agreed to invoice next quarter'])->assertOk();
    }

    public function test_pdf_is_generated_and_staff_without_billing_access_are_refused(): void
    {
        $this->tax(true);
        $id = $this->draftId();
        $res = $this->get("/api/v1/invoices/$id/pdf");
        $res->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
        Sanctum::actingAs($this->makeUser('staff'));
        $this->getJson("/api/v1/invoices/$id")->assertForbidden();
    }
}
