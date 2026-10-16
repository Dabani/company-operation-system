<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Gap-free yearly counter per organization (locked row => unique references under concurrency).
        Schema::create('case_sequences', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('year');
            $t->unsignedInteger('last_number')->default(0);
            $t->unique(['organization_id', 'year']);
        });

        Schema::create('cases', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40)->unique();                 // CASE-2026-000123
            $t->foreignUlid('type_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->foreignUlid('priority_id')->constrained('lookup_values');
            $t->string('title');
            $t->longText('description')->nullable();               // purified HTML
            $t->foreignUlid('requester_id')->constrained('users');
            $t->foreignUlid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->timestamp('due_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status_id']);
            $t->index(['organization_id', 'requester_id']);
            $t->index(['organization_id', 'assignee_id']);
        });

        Schema::create('case_comments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('case_id')->constrained('cases')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');
            $t->longText('body');                                  // purified HTML
            $t->boolean('is_internal')->default(false);            // staff-only note
            $t->timestamps();
            $t->index(['case_id', 'created_at']);
        });

        // Activity tracker: append-only. old_ref/new_ref hold a lookup-value id or user id depending on event type.
        Schema::create('case_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('case_id')->constrained('cases')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('event_type_id')->constrained('lookup_values');
            $t->string('old_ref', 40)->nullable();
            $t->string('new_ref', 40)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['case_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_events');
        Schema::dropIfExists('case_comments');
        Schema::dropIfExists('cases');
        Schema::dropIfExists('case_sequences');
    }
};
