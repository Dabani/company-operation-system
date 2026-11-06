<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 2a - time entries (server-side timer segments), weekly timesheets, rate cards. Money is DECIMAL(15,2); timestamps are UTC. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rate_cards', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->char('currency_code', 3);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('rate_card_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('rate_card_id')->constrained('rate_cards')->cascadeOnDelete();
            $t->foreignUlid('scope_id')->constrained('lookup_values');                    // default | role | employee | task
            $t->foreignUlid('project_role_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('task_id')->nullable()->constrained('tasks')->cascadeOnDelete();
            $t->decimal('hourly_rate', 15, 2);
            $t->date('effective_from');
            $t->date('effective_to')->nullable();
            $t->timestamps();
            $t->index(['rate_card_id', 'effective_from']);
        });

        Schema::table('projects', fn (Blueprint $t) => $t->foreignUlid('rate_card_id')->nullable()->after('currency_code')->constrained('rate_cards')->nullOnDelete());
        Schema::table('tasks', fn (Blueprint $t) => $t->boolean('is_billable')->default(true)->after('estimate_minutes'));

        Schema::create('timesheets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');
            $t->date('week_start');                                                       // Monday
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->unsignedInteger('total_seconds')->default(0);                            // snapshot at submission
            $t->ulid('approval_request_id')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'user_id', 'week_start']);
            $t->foreign('approval_request_id')->references('id')->on('approval_requests')->nullOnDelete();
        });

        Schema::create('time_entries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');
            $t->foreignUlid('project_id')->constrained('projects');
            $t->foreignUlid('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            $t->date('work_date');                                                        // local date in the organization's timezone
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();                                        // null while the timer is running or paused
            $t->integer('duration_seconds')->default(0);                                  // signed: adjustments may be negative
            $t->boolean('is_manual')->default(false);
            $t->text('manual_reason')->nullable();
            $t->boolean('is_billable')->default(true);
            $t->string('description', 500)->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->foreignUlid('timesheet_id')->nullable()->constrained('timesheets')->nullOnDelete();
            $t->ulid('adjusts_entry_id')->nullable();
            $t->boolean('auto_stopped')->default(false);                                  // forgotten timer cut off: needs the owner's correction
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'work_date']);
            $t->index(['project_id', 'status_id']);
            $t->index('timesheet_id');
            $t->foreign('adjusts_entry_id')->references('id')->on('time_entries')->nullOnDelete();
        });

        Schema::create('time_entry_segments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('time_entry_id')->constrained('time_entries')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');                             // denormalised for the overlap check
            $t->timestamp('started_at');
            $t->timestamp('ended_at')->nullable();
            $t->index(['user_id', 'started_at']);
            $t->index('time_entry_id');
        });
    }

    public function down(): void
    {
        foreach (['time_entry_segments', 'time_entries', 'timesheets'] as $t) Schema::dropIfExists($t);
        Schema::table('tasks', fn (Blueprint $t) => $t->dropColumn('is_billable'));
        Schema::table('projects', function (Blueprint $t) { $t->dropForeign(['rate_card_id']); $t->dropColumn('rate_card_id'); });
        foreach (['rate_card_lines', 'rate_cards'] as $t) Schema::dropIfExists($t);
    }
};
