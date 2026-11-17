<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 7d: resolutions and action items of a meeting (Guide 6.7). Statuses are lookups; every change is kept in meeting_followup_history. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_resolutions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('agenda_item_id')->nullable()->constrained('meeting_agenda_items')->nullOnDelete();
            $t->string('code', 60);
            $t->unsignedInteger('seq');                                   // R1, R2 ... inside the meeting
            $t->string('title');
            $t->longText('body')->nullable();                             // purified HTML
            $t->foreignUlid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('responsible_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->date('due_on')->nullable();                               // calendar date in the organization time zone
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->timestamp('completed_at')->nullable();
            $t->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->foreignUlid('obligation_id')->nullable()->constrained('compliance_obligations')->nullOnDelete();
            $t->date('due_alerted_on')->nullable();
            $t->date('overdue_alerted_on')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->unique(['meeting_id', 'seq']);
            $t->index(['organization_id', 'status_id', 'due_on']);
        });

        Schema::create('meeting_actions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('resolution_id')->nullable()->constrained('meeting_resolutions')->nullOnDelete();
            $t->foreignUlid('agenda_item_id')->nullable()->constrained('meeting_agenda_items')->nullOnDelete();
            $t->string('code', 60);
            $t->unsignedInteger('seq');
            $t->string('title');
            $t->longText('description')->nullable();
            $t->foreignUlid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_on')->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->timestamp('completed_at')->nullable();
            $t->string('completion_note', 2000)->nullable();              // the evidence in words; files are linked as "meeting_action"
            $t->timestamp('closed_at')->nullable();
            $t->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_alerted_on')->nullable();
            $t->date('overdue_alerted_on')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->unique(['meeting_id', 'seq']);
            $t->index(['organization_id', 'status_id', 'due_on']);
            $t->index(['owner_id', 'status_id']);
        });

        Schema::create('meeting_followup_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('subject_type', 20);                               // resolution | action
            $t->ulid('subject_id');
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 2000)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_followup_history');
        Schema::dropIfExists('meeting_actions');
        Schema::dropIfExists('meeting_resolutions');
    }
};
