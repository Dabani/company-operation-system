<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 7c: meeting minutes (Guide 6.6). One minutes record per meeting; every wording is a numbered version.
 * An approved and published version is never edited: a correction is a new version and the earlier ones stay readable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_minutes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique('meeting_id');
        });

        Schema::create('meeting_minute_versions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('minutes_id')->constrained('meeting_minutes')->cascadeOnDelete();
            $t->unsignedSmallInteger('version_no');
            $t->foreignUlid('status_id')->constrained('lookup_values');         // draft | pending_approval | approved | published | superseded
            $t->longText('body')->nullable();                                   // purified rich text
            $t->unsignedInteger('revision')->default(1);                        // optimistic lock: a save must name the revision it started from
            $t->text('correction_reason')->nullable();                          // required from version 2
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('submitted_at')->nullable();
            $t->foreignUlid('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('approval_request_id')->nullable()->constrained('approval_requests')->nullOnDelete();
            $t->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignUlid('visibility_id')->nullable()->constrained('lookup_values'); // participants | department | organization | named (set on publication)
            $t->timestamp('published_at')->nullable();
            $t->foreignUlid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('file_id')->nullable()->constrained('file_assets')->nullOnDelete();   // the controlled PDF copy made on publication
            $t->timestamps();
            $t->unique(['minutes_id', 'version_no']);
            $t->index(['organization_id', 'status_id']);
        });

        Schema::create('meeting_minute_reviewers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('version_id')->constrained('meeting_minute_versions')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('added_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->nullable();
            $t->unique(['version_id', 'user_id']);
        });

        Schema::create('meeting_minute_recipients', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('version_id')->constrained('meeting_minute_versions')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('department_id')->nullable()->constrained('departments')->cascadeOnDelete();
            $t->index(['version_id', 'user_id']);
            $t->index(['version_id', 'department_id']);
        });

        Schema::create('meeting_minute_comments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('version_id')->constrained('meeting_minute_versions')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');
            $t->text('body');
            $t->string('anchor_text', 500)->nullable();                         // the passage the comment is about (inline feedback)
            $t->timestamp('resolved_at')->nullable();
            $t->foreignUlid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
            $t->index(['version_id', 'created_at']);
        });

        Schema::create('meeting_minute_distributions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('version_id')->constrained('meeting_minute_versions')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->timestamp('notified_at');
            $t->timestamp('downloaded_at')->nullable();
            $t->unique(['version_id', 'user_id']);
        });

        Schema::create('meeting_minute_status_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('version_id')->constrained('meeting_minute_versions')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->text('note')->nullable();
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['meeting_minute_status_history', 'meeting_minute_distributions', 'meeting_minute_comments', 'meeting_minute_recipients', 'meeting_minute_reviewers', 'meeting_minute_versions', 'meeting_minutes'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
