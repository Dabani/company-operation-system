<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 1 - Projects core: projects, members, milestones, tasks, dependencies, comments, immutable status history. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->text('description')->nullable();
            $t->foreignUlid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $t->foreignUlid('owner_id')->constrained('users');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->date('start_date')->nullable();
            $t->date('end_date')->nullable();
            $t->decimal('budget_amount', 15, 2)->nullable();
            $t->char('currency_code', 3)->nullable();
            $t->unsignedInteger('task_counter')->default(0);          // per-project task numbering (PRJ-12)
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status_id']);
        });

        Schema::create('project_departments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('department_id')->constrained()->cascadeOnDelete();
            $t->unique(['project_id', 'department_id']);
        });

        Schema::create('project_members', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('project_role_id')->constrained('lookup_values');
            $t->timestamps();
            $t->unique(['project_id', 'user_id']);
        });

        Schema::create('milestones', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->date('due_date')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->index('project_id');
        });

        Schema::create('tasks', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('milestone_id')->nullable()->constrained('milestones')->nullOnDelete();
            $t->ulid('parent_task_id')->nullable();
            $t->unsignedInteger('number');
            $t->string('title');
            $t->text('description')->nullable();                    // plain text only; rendered escaped
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->foreignUlid('priority_id')->constrained('lookup_values');
            $t->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('reporter_id')->constrained('users');
            $t->ulid('department_id')->nullable();
            $t->date('due_date')->nullable();
            $t->unsignedInteger('estimate_minutes')->nullable();
            $t->unsignedInteger('position')->default(0);            // order inside a kanban column
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['project_id', 'number']);
            $t->index(['project_id', 'status_id', 'position']);
            $t->index(['assignee_id', 'status_id']);
            $t->foreign('parent_task_id')->references('id')->on('tasks')->nullOnDelete();
            $t->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        Schema::create('task_dependencies', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();                 // the blocked task
            $t->foreignUlid('depends_on_task_id')->constrained('tasks')->cascadeOnDelete();       // must finish first
            $t->unique(['task_id', 'depends_on_task_id']);
        });

        Schema::create('task_comments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');
            $t->text('body');
            $t->timestamps();
            $t->index('task_id');
        });

        Schema::create('task_status_history', function (Blueprint $t) {      // immutable
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('task_id')->constrained('tasks')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index('task_id');
        });
    }

    public function down(): void
    {
        foreach (['task_status_history', 'task_comments', 'task_dependencies', 'tasks', 'milestones', 'project_members', 'project_departments', 'projects'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
