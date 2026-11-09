<?php

namespace Tests\Feature;

use App\Mail\UserInvitationMail;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientIdentity;
use App\Models\Invoice;
use App\Models\Milestone;
use App\Models\PortalFileShare;
use App\Models\Project;
use App\Models\ProjectComment;
use App\Models\Task;
use App\Models\User;
use App\Services\OrganizationContext;
use App\Services\Portal\PortalMfa;
use App\Services\Portal\Totp;
use App\Services\FileService;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Needs Bundles 08-12. Not executed in the build environment - run `php artisan test`. */
class PortalTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private User $admin;
    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        $this->admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->manager = $this->makeUser('manager');
        SettingsService::set($this->org->id, 'portal.mfa_required', false);
        Storage::fake(FileService::disk());
    }

    private function spa(): static
    {
        config(['sanctum.stateful' => ['spa.test']]);
        return $this->withHeaders(['Referer' => 'http://spa.test']);
    }

    /** Each portal request starts with a fresh client context in production; tests reuse one app instance, so reset it. */
    private function asClient(ClientIdentity $i): void { $this->freshRequest(); $this->actingAs($i, 'client'); }
    private function asStaff(User $u): void { $this->freshRequest(); Sanctum::actingAs($u); }

    private function freshRequest(): void
    {
        app()->forgetScopedInstances();                                                          // client + organization context are per request
        app(OrganizationContext::class)->resolveForOrganization($this->org);                    // so direct model calls in the test body stay tenant-scoped
    }

    private function world(string $code = 'ACME', bool $shared = true): array
    {
        $c = Client::create(['code' => $code, 'name' => "$code Ltd", 'status_id' => Lookup::id('client_status', 'active'), 'portal_enabled' => true]);
        $contact = ClientContact::create(['client_id' => $c->id, 'name' => "Contact $code", 'email' => strtolower($code) . '@client.test']);
        $i = ClientIdentity::create(['client_id' => $c->id, 'client_contact_id' => $contact->id, 'email' => strtolower($code) . '@client.test', 'password' => 'Str0ng-Passw0rd!x', 'is_active' => true]);
        $p = Project::create(['code' => "P-$code", 'name' => "Project $code", 'owner_id' => $this->manager->id, 'status_id' => Lookup::id('project_status', 'active'), 'client_id' => $c->id, 'portal_visible' => $shared]);
        return [$c, $contact, $i, $p];
    }

    private function task(Project $p, int $n, string $status, string $title, ?string $milestone = null): Task
    {
        return Task::create(['project_id' => $p->id, 'number' => $n, 'title' => $title, 'status_id' => Lookup::id('task_status', $status), 'priority_id' => Lookup::id('task_priority', 'normal'),
            'reporter_id' => $this->manager->id, 'milestone_id' => $milestone]);
    }

    public function test_invitation_is_single_use_and_the_account_activates_with_a_password(): void
    {
        Mail::fake();
        [$c, $contact] = $this->world();
        ClientIdentity::query()->delete();                                                                     // start with a contact that has no login yet
        $this->asStaff($this->admin);
        $this->postJson("/api/v1/clients/{$c->id}/contacts/{$contact->id}/invite")->assertCreated();
        $url = null;
        Mail::assertSent(UserInvitationMail::class, function ($m) use (&$url) { $url = $m->url; return true; });
        parse_str(parse_url($url, PHP_URL_QUERY), $q);

        $base = ['organization' => $this->org->slug, 'email' => $q['email']];
        $this->getJson('/api/v1/portal/auth/activation?' . http_build_query($base + ['token' => 'wrong']))->assertStatus(422);
        $this->getJson('/api/v1/portal/auth/activation?' . http_build_query($base + ['token' => $q['token']]))->assertOk()->assertJsonPath('valid', true);
        $this->spa()->postJson('/api/v1/portal/auth/activate', $base + ['token' => $q['token'], 'password' => 'short', 'password_confirmation' => 'short'])->assertStatus(422);
        $this->spa()->postJson('/api/v1/portal/auth/activate', $base + ['token' => $q['token'], 'password' => 'Correct-Horse-Battery-9', 'password_confirmation' => 'Correct-Horse-Battery-9'])->assertOk();
        $this->spa()->postJson('/api/v1/portal/auth/activate', $base + ['token' => $q['token'], 'password' => 'Another-Strong-Pass-7', 'password_confirmation' => 'Another-Strong-Pass-7'])->assertStatus(422);   // used
        $this->assertTrue(ClientIdentity::first()->is_active);
    }

    public function test_forgot_password_answers_the_same_for_unknown_and_known_addresses(): void
    {
        Mail::fake();
        $this->world();
        $a = $this->postJson('/api/v1/portal/auth/forgot', ['organization' => $this->org->slug, 'email' => 'nobody@client.test'])->assertOk()->json('message');
        $b = $this->postJson('/api/v1/portal/auth/forgot', ['organization' => $this->org->slug, 'email' => 'acme@client.test'])->assertOk()->json('message');
        $this->assertSame($a, $b);
        Mail::assertSent(UserInvitationMail::class, 1);
    }

    public function test_two_factor_is_enforced_and_codes_cannot_be_replayed(): void
    {
        SettingsService::set($this->org->id, 'portal.mfa_required', true);
        [, , $i] = $this->world();
        $this->asClient($i);
        $this->getJson('/api/v1/portal/projects')->assertForbidden()->assertJsonPath('error.code', 'mfa_setup_required');
        $this->spa()->withSession(['portal_mfa_ok' => $i->id])->getJson('/api/v1/portal/projects')->assertOk();        // a verified session passes

        $mfa = app(PortalMfa::class);
        $setup = $mfa->begin($i->refresh(), 'COPS');
        $this->assertStringContainsString($setup['secret'], $setup['uri']);
        $codes = $mfa->confirm($i->refresh(), Totp::code($setup['secret'], time()));
        $this->assertCount(10, $codes);
        $this->assertTrue($i->refresh()->hasMfa());

        $this->assertFalse($mfa->verify($i, Totp::code($setup['secret'], time())));                              // same time step as the enrolment code: replay refused
        $this->assertTrue($mfa->verify($i, Totp::code($setup['secret'], time() + 30)));                          // next step accepted
        $this->assertTrue($mfa->verify($i, $codes[0]));                                                          // a recovery code works once
        $this->assertFalse($mfa->verify($i, $codes[0]));
        $this->assertFalse($mfa->verify($i, '000000'));

        $this->flushSession();                                                                                    // the earlier verified session must not leak into this request
        $this->asClient($i->refresh());
        $this->getJson('/api/v1/portal/projects')->assertForbidden()->assertJsonPath('error.code', 'mfa_required');
    }

    public function test_client_sees_progress_only_and_only_its_own_shared_projects(): void
    {
        [, , $a, $pa] = $this->world('ACME');
        [, , , $pb] = $this->world('GLOBEX');
        [, , , $hidden] = [null, null, null, Project::create(['code' => 'HID', 'name' => 'Not shared', 'owner_id' => $this->manager->id, 'status_id' => Lookup::id('project_status', 'active'), 'client_id' => $pa->client_id])];
        $ms = Milestone::create(['project_id' => $pa->id, 'name' => 'Design', 'due_date' => '2026-12-01']);
        $this->task($pa, 1, 'done', 'SECRET internal task title', $ms->id);
        $this->task($pa, 2, 'in_progress', 'Another internal title', $ms->id);
        $this->task($pa, 3, 'cancelled', 'Dropped');
        ProjectComment::create(['project_id' => $pa->id, 'user_id' => $this->manager->id, 'body' => 'INTERNAL note', 'is_client_visible' => false]);
        ProjectComment::create(['project_id' => $pa->id, 'user_id' => $this->manager->id, 'body' => 'Update for the client', 'is_client_visible' => true]);

        $this->asClient($a);
        $list = $this->getJson('/api/v1/portal/projects')->assertOk();
        $list->assertJsonCount(1, 'data')->assertJsonPath('data.0.progress', 50);
        $res = $this->getJson("/api/v1/portal/projects/{$pa->id}")->assertOk();
        $res->assertJsonPath('data.progress.percent', 50)->assertJsonPath('data.milestones.0.progress', 50);
        $body = $res->getContent();
        foreach (['SECRET internal task title', 'Another internal title', 'INTERNAL note', 'duration_seconds', 'hourly_rate'] as $leak) $this->assertStringNotContainsString($leak, $body);
        $this->assertStringContainsString('Update for the client', $body);

        $this->getJson("/api/v1/portal/projects/{$pb->id}")->assertNotFound();                                   // another client's project
        $this->getJson("/api/v1/portal/projects/{$hidden->id}")->assertNotFound();                               // own project that is not shared
    }

    public function test_invoices_and_uploads_are_off_until_enabled_and_drafts_never_show(): void
    {
        [$c, , $i, $p] = $this->world();
        $issued = Invoice::create(['project_id' => $p->id, 'client_id' => $c->id, 'billing_model_id' => Lookup::id('billing_model', 'fixed_price'), 'status_id' => Lookup::id('invoice_status', 'issued'),
            'currency_code' => 'RWF', 'number' => 'INV-2026-0001', 'total' => '100.00', 'subtotal' => '100.00', 'balance' => '100.00', 'issue_date' => '2026-11-01', 'due_date' => '2026-12-01']);
        Invoice::create(['project_id' => $p->id, 'client_id' => $c->id, 'billing_model_id' => Lookup::id('billing_model', 'fixed_price'), 'status_id' => Lookup::id('invoice_status', 'draft'), 'currency_code' => 'RWF']);

        $this->asClient($i);
        $this->getJson("/api/v1/portal/projects/{$p->id}/invoices")->assertForbidden();
        $this->postJson("/api/v1/portal/projects/{$p->id}/files", ['file' => UploadedFile::fake()->create('a.txt', 5, 'text/plain')])->assertForbidden();

        $p->update(['portal_invoices' => true, 'portal_uploads' => true]);
        $this->asClient($i);
        $this->getJson("/api/v1/portal/projects/{$p->id}/invoices")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'INV-2026-0001');
        $pdf = $this->get("/api/v1/portal/projects/{$p->id}/invoices/{$issued->id}/pdf");
        $pdf->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_uploads_are_type_and_size_limited_scanned_and_shared_back(): void
    {
        [, , $i, $p] = $this->world();
        $p->update(['portal_uploads' => true]);
        $this->asClient($i);
        $this->postJson("/api/v1/portal/projects/{$p->id}/files", ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])->assertStatus(422);
        $this->postJson("/api/v1/portal/projects/{$p->id}/files", ['file' => UploadedFile::fake()->create('huge.pdf', 20000, 'application/pdf')])->assertStatus(422);
        $id = $this->postJson("/api/v1/portal/projects/{$p->id}/files", ['file' => UploadedFile::fake()->create('notes.txt', 5, 'text/plain')])->assertCreated()->json('id');

        $this->assertDatabaseHas('portal_file_shares', ['project_id' => $p->id, 'file_id' => $id, 'client_identity_id' => $i->id]);
        $this->getJson("/api/v1/portal/projects/{$p->id}/files/$id/download")->assertOk();
        $this->getJson("/api/v1/portal/projects/{$p->id}")->assertJsonPath('data.deliverables.0.mine', true);
    }

    public function test_another_clients_file_cannot_be_downloaded_and_staff_can_share_and_unshare(): void
    {
        [, , $a, $pa] = $this->world('ACME');
        [, , $b, $pb] = $this->world('GLOBEX');
        $pb->update(['portal_uploads' => true]);
        $this->asClient($b);
        $fileB = $this->postJson("/api/v1/portal/projects/{$pb->id}/files", ['file' => UploadedFile::fake()->create('b.txt', 5, 'text/plain')])->assertCreated()->json('id');
        $this->asClient($a);
        $this->getJson("/api/v1/portal/projects/{$pa->id}/files/$fileB/download")->assertNotFound();           // no share on A's project
        $this->getJson("/api/v1/portal/projects/{$pb->id}/files/$fileB/download")->assertNotFound();           // B's project is invisible to A

        $this->asStaff($this->manager);
        $mine = FileService::store(UploadedFile::fake()->create('plan.pdf', 5, 'application/pdf'), $this->manager, [], ['type' => 'project', 'id' => $pa->id]);
        $this->putJson("/api/v1/projects/{$pa->id}/portal-shares/{$mine->id}", ['shared' => true])->assertNoContent();
        $this->asClient($a);
        $this->getJson("/api/v1/portal/projects/{$pa->id}")->assertJsonCount(1, 'data.deliverables');
        $this->asStaff($this->manager);
        $this->putJson("/api/v1/projects/{$pa->id}/portal-shares/{$mine->id}", ['shared' => false])->assertNoContent();
        $this->asClient($a);
        $this->getJson("/api/v1/portal/projects/{$pa->id}")->assertJsonCount(0, 'data.deliverables');
    }

    public function test_client_comment_is_visible_to_client_and_team_comments_are_internal_by_default(): void
    {
        [, , $i, $p] = $this->world();
        $this->asClient($i);
        $this->postJson("/api/v1/portal/projects/{$p->id}/comments", ['body' => '<p>Hello <strong>team</strong></p><script>alert(1)</script>'])->assertCreated();
        $stored = (string) ProjectComment::first()->body;
        $this->assertStringContainsString('<strong>team</strong>', $stored);                                      // formatting kept
        $this->assertStringNotContainsString('<script', $stored);                                                  // script removed by the server

        $this->asStaff($this->manager);
        $this->postJson("/api/v1/projects/{$p->id}/client-comments", ['body' => '<p>Internal only</p>'])->assertCreated();
        $this->postJson("/api/v1/projects/{$p->id}/client-comments", ['body' => '<p>Shared reply</p>', 'is_client_visible' => true])->assertCreated();
        $this->asClient($i);
        $bodies = implode(' ', array_column($this->getJson("/api/v1/portal/projects/{$p->id}")->json('data.comments'), 'body'));
        $this->assertStringContainsString('Hello', $bodies);
        $this->assertStringContainsString('Shared reply', $bodies);
        $this->assertStringNotContainsString('Internal only', $bodies);
    }
}
