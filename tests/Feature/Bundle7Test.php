<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ReportService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Bundle 7 acceptance tests. Not executed in the build environment (no Laravel runtime there) - run `php artisan test`.
 * Use a dedicated MySQL test database (see .env.testing.example); the seeders and migrations target MySQL 8.
 */
class Bundle7Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ops.seed_admin_password' => 'Test-Password-123!', 'ops.seed_admin_email' => 'admin@example.com']);
        $this->seed(DatabaseSeeder::class);
    }

    private function admin(): User
    {
        $u = User::where('email', 'admin@example.com')->firstOrFail();
        Sanctum::actingAs($u);
        return $u;
    }

    public function test_public_health_is_minimal_and_has_security_headers(): void
    {
        $r = $this->getJson('/api/v1/health');
        $r->assertOk()->assertJsonStructure(['status', 'time'])->assertJsonMissing(['checks']);
        $r->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Frame-Options', 'DENY');
        $this->assertStringContainsString('no-store', $r->headers->get('Cache-Control'));
    }

    public function test_reports_and_health_details_require_authentication(): void
    {
        $this->getJson('/api/v1/reports')->assertUnauthorized();
        $this->getJson('/api/v1/reports/executive')->assertUnauthorized();
        $this->getJson('/api/v1/reports/executive/export')->assertUnauthorized();
        $this->getJson('/api/v1/health/details')->assertUnauthorized();
    }

    public function test_admin_sees_every_report_in_the_catalogue(): void
    {
        $this->admin();
        $codes = collect($this->getJson('/api/v1/reports')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertEqualsCanonicalizing(array_keys(ReportService::definitions()), $codes);
    }

    public function test_every_report_runs_and_has_the_documented_shape(): void
    {
        $this->admin();
        foreach (array_keys(ReportService::definitions()) as $code) {
            $this->getJson("/api/v1/reports/{$code}?from=2026-01-01&to=2026-06-30")
                ->assertOk()
                ->assertJsonStructure(['data' => [
                    'meta' => ['code', 'title', 'from', 'to', 'timezone', 'method' => ['formula', 'inclusion', 'date_basis', 'sources']],
                    'kpis', 'charts', 'table' => ['columns', 'rows'],
                ]]);
        }
    }

    public function test_unknown_report_is_404_and_bad_dates_are_rejected(): void
    {
        $this->admin();
        $this->getJson('/api/v1/reports/nope')->assertNotFound();
        $this->getJson('/api/v1/reports/executive?from=not-a-date')->assertStatus(422);
    }

    public function test_export_needs_its_own_permission_and_is_audited(): void
    {
        $this->admin();
        $this->get('/api/v1/reports/executive/export?format=csv')->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'report.exported']);

        // Remove only the export permission: viewing still works, exporting is refused.
        $role = Role::withoutGlobalScopes()->where('code', 'admin')->firstOrFail();
        $role->permissions()->detach(Permission::where('code', 'report.export')->value('id'));
        $this->getJson('/api/v1/reports/executive')->assertOk();
        $this->getJson('/api/v1/reports/executive/export?format=csv')->assertForbidden();
    }

    public function test_a_report_also_needs_its_module_permission(): void
    {
        $this->admin();
        $role = Role::withoutGlobalScopes()->where('code', 'admin')->firstOrFail();
        $role->permissions()->detach(Permission::where('code', 'audit_log.view')->value('id'));
        $this->getJson('/api/v1/reports/delivery')->assertForbidden();
        $this->getJson('/api/v1/reports/executive')->assertOk();
    }

    public function test_health_details_report_components_without_secrets(): void
    {
        $this->admin();
        $r = $this->getJson('/api/v1/health/details');
        $this->assertContains($r->status(), [200, 503]);   // 503 only if the database were down
        $r->assertJsonStructure(['status', 'checks' => ['database', 'cache', 'storage', 'queue', 'scheduler', 'backup', 'notifications']]);
        $this->assertStringNotContainsString(config('app.key'), $r->getContent());
    }
}
