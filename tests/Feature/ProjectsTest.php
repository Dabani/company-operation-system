<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\Task;
use App\Services\Approvals\ApprovalEngine;
use App\Support\Lookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs Bundle 08 helpers. */
class ProjectsTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    protected function setUp(): void { parent::setUp(); $this->bootCops(); }

    private function project($owner, string $code = 'PRJ'): Project
    {
        return Project::create(['code' => $code, 'name' => $code, 'owner_id' => $owner->id, 'status_id' => Lookup::id('project_status', 'active')]);
    }

    private function member(Project $p, $user, string $role): void
    {
        ProjectMember::create(['project_id' => $p->id, 'user_id' => $user->id, 'project_role_id' => Lookup::id('project_role', $role)]);
    }

    public function test_projects_are_invisible_to_non_members_with_404(): void
    {
        $owner = $this->makeUser('team_leader');
        $outsider = $this->makeUser('staff');
        $p = $this->project($owner);
        Sanctum::actingAs($outsider);
        $this->getJson("/api/v1/projects/{$p->id}")->assertNotFound();
        $this->getJson('/api/v1/projects')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs($owner);
        $this->getJson("/api/v1/projects/{$p->id}")->assertOk()->assertJsonPath('data.my_level', 'manager');
    }

    public function test_task_numbering_and_assignee_must_be_a_project_person(): void
    {
        $owner = $this->makeUser('team_leader');
        $dev = $this->makeUser('staff');
        $stranger = $this->makeUser('staff');
        $p = $this->project($owner);
        $this->member($p, $dev, 'member');
        Sanctum::actingAs($owner);
        $this->postJson("/api/v1/projects/{$p->id}/tasks", ['title' => 'One', 'assignee_id' => $dev->id])->assertCreated()->assertJsonPath('data.key', 'PRJ-1');
        $this->postJson("/api/v1/projects/{$p->id}/tasks", ['title' => 'Two'])->assertCreated()->assertJsonPath('data.key', 'PRJ-2');
        $this->postJson("/api/v1/projects/{$p->id}/tasks", ['title' => 'Bad', 'assignee_id' => $stranger->id])->assertStatus(422);
    }

    public function test_review_moves_task_to_done_only_after_independent_approval(): void
    {
        $owner = $this->makeUser('team_leader');
        $dev = $this->makeUser('staff');
        $reviewer = $this->makeUser('staff');
        $this->makeUser('admin');                                                // top-authority fallback exists
        $p = $this->project($owner);
        $this->member($p, $dev, 'member');
        $this->member($p, $reviewer, 'reviewer');
        Sanctum::actingAs($dev);
        $id = $this->postJson("/api/v1/projects/{$p->id}/tasks", ['title' => 'Ship', 'assignee_id' => $dev->id])->json('data.id');
        $this->postJson("/api/v1/tasks/$id/move", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/v1/tasks/$id/move", ['status' => 'done'])->assertStatus(422);                 // no direct close
        $this->postJson("/api/v1/tasks/$id/move", ['status' => 'in_review'])->assertOk()->assertJsonPath('data.status', 'in_review');

        $req = \App\Models\ApprovalRequest::where('subject_id', $id)->firstOrFail();
        $this->assertSame('task_review', $req->flow->code);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(ApprovalEngine::class)->act($req->id, $dev, 'approve');                                         // requester cannot approve
    }

    public function test_approved_review_completes_the_task_and_rejection_reopens_it(): void
    {
        $owner = $this->makeUser('team_leader');
        $dev = $this->makeUser('staff');
        $reviewer = $this->makeUser('staff');
        $p = $this->project($owner);
        $this->member($p, $dev, 'member');
        $this->member($p, $reviewer, 'reviewer');
        Sanctum::actingAs($dev);
        $mk = fn ($title) => $this->postJson("/api/v1/projects/{$p->id}/tasks", ['title' => $title, 'assignee_id' => $dev->id])->json('data.id');
        $a = $mk('A'); $b = $mk('B');
        foreach ([$a, $b] as $id) {
            $this->postJson("/api/v1/tasks/$id/move", ['status' => 'in_progress'])->assertOk();
            $this->postJson("/api/v1/tasks/$id/move", ['status' => 'in_review'])->assertOk();
        }
        $engine = app(ApprovalEngine::class);
        $engine->act(\App\Models\ApprovalRequest::where('subject_id', $a)->value('id'), $reviewer, 'approve');
        $engine->act(\App\Models\ApprovalRequest::where('subject_id', $b)->value('id'), $reviewer, 'reject', 'Missing tests');
        $this->assertSame('done', Task::find($a)->status->code);
        $this->assertNotNull(Task::find($a)->completed_at);
        $this->assertSame('in_progress', Task::find($b)->status->code);
    }

    public function test_dependencies_block_completion_and_reject_cycles(): void
    {
        $owner = $this->makeUser('team_leader');
        $p = $this->project($owner);
        Sanctum::actingAs($owner);
        $a = $this->postJson("/api/v1/projects/{$p->id}/tasks", ['title' => 'A'])->json('data.id');
        $b = $this->postJson("/api/v1/projects/{$p->id}/tasks", ['title' => 'B'])->json('data.id');
        $this->postJson("/api/v1/tasks/$b/dependencies", ['depends_on_task_id' => $a])->assertStatus(201);
        $this->postJson("/api/v1/tasks/$a/dependencies", ['depends_on_task_id' => $b])->assertStatus(422);   // loop
        $this->postJson("/api/v1/tasks/$b/move", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/v1/tasks/$b/move", ['status' => 'done'])->assertStatus(422);                    // A not finished
    }

    public function test_viewers_cannot_edit_or_comment_and_status_history_is_immutable(): void
    {
        $owner = $this->makeUser('team_leader');
        $viewer = $this->makeUser('staff');
        $p = $this->project($owner);
        $this->member($p, $viewer, 'viewer');
        Sanctum::actingAs($owner);
        $id = $this->postJson("/api/v1/projects/{$p->id}/tasks", ['title' => 'A'])->json('data.id');
        Sanctum::actingAs($viewer);
        $this->getJson("/api/v1/tasks/$id")->assertOk()->assertJsonPath('data.can_edit', false);
        $this->patchJson("/api/v1/tasks/$id", ['title' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/tasks/$id/comments", ['body' => 'hi'])->assertForbidden();
        $this->expectException(\LogicException::class);
        \App\Models\TaskStatusHistory::where('task_id', $id)->firstOrFail()->update(['note' => 'tampered']);
    }
}
