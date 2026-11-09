<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Currency lookup, rich-text sanitising and the SQLite-safe date storage fix. Needs Bundles 08-12 and CopsTestHelpers. */
class PolishTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    protected function setUp(): void { parent::setUp(); $this->bootCops(); }

    public function test_currency_must_be_a_value_of_the_currency_lookup(): void
    {
        $owner = $this->makeUser('team_leader');
        Sanctum::actingAs($owner);
        $this->postJson('/api/v1/projects', ['code' => 'C1', 'name' => 'Bad', 'currency_code' => 'XXX'])->assertStatus(422);
        $this->postJson('/api/v1/projects', ['code' => 'C2', 'name' => 'Good', 'currency_code' => 'RWF'])->assertCreated();
        $this->getJson('/api/v1/lookups?type=currency')->assertOk()->assertJsonFragment(['code' => 'USD']);
    }

    public function test_rich_text_is_purified_on_the_server(): void
    {
        $owner = $this->makeUser('team_leader');
        $p = Project::create(['code' => 'R1', 'name' => 'Rich', 'owner_id' => $owner->id, 'status_id' => Lookup::id('project_status', 'active')]);
        Sanctum::actingAs($owner);
        $this->patchJson("/api/v1/projects/{$p->id}", ['description' => '<p>Scope <strong>v2</strong></p><script>alert(1)</script><img src=x onerror=alert(1)>'])->assertOk();
        $html = (string) Project::find($p->id)->description;
        $this->assertStringContainsString('<strong>v2</strong>', $html);
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
    }

    public function test_week_dates_are_stored_as_plain_dates_so_lookups_work_on_sqlite(): void
    {
        $u = $this->makeUser('staff');
        $a = Timesheet::firstOrCreate(['user_id' => $u->id, 'week_start' => '2026-11-02'], ['status_id' => Lookup::id('timesheet_status', 'draft')]);
        $b = Timesheet::firstOrCreate(['user_id' => $u->id, 'week_start' => '2026-11-02'], ['status_id' => Lookup::id('timesheet_status', 'draft')]);
        $this->assertSame($a->id, $b->id);                                                                        // the second call finds the first
        $this->assertSame('2026-11-02', \DB::table('timesheets')->where('id', $a->id)->value('week_start'));
    }
}
