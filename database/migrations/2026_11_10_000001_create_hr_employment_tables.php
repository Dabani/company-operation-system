<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 4a: grades, positions, progression criteria, effective-dated employment records, performance reviews, reminder log. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_grades', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name', 150);
            $t->unsignedInteger('level')->default(1);
            $t->decimal('salary_min', 15, 2)->nullable();          // confidential: only returned with hr.salary.view
            $t->decimal('salary_max', 15, 2)->nullable();
            $t->string('currency_code', 3)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('hr_positions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('title', 150);
            $t->ulid('department_id')->nullable();
            $t->ulid('grade_id')->nullable();
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $t->foreign('grade_id')->references('id')->on('hr_grades')->nullOnDelete();
        });

        Schema::create('hr_progression_criteria', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('from_grade_id')->constrained('hr_grades')->cascadeOnDelete();
            $t->foreignUlid('to_grade_id')->constrained('hr_grades')->cascadeOnDelete();
            $t->unsignedInteger('min_months_in_grade')->default(0);
            $t->unsignedInteger('min_rating_level')->nullable();   // compared with the sort order of the performance_rating lookup
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['from_grade_id', 'to_grade_id']);
        });

        // Append-only history: a change adds a row, it never rewrites a past one. The state in force on a date is the latest effective row on or before it.
        Schema::create('hr_employment_records', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->foreignUlid('change_type_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->date('effective_from');
            $t->foreignUlid('category_id')->constrained('lookup_values');
            $t->ulid('position_id')->nullable();
            $t->ulid('grade_id')->nullable();
            $t->ulid('department_id')->nullable();
            $t->ulid('manager_employee_id')->nullable();
            $t->foreignUlid('contract_type_id')->nullable()->constrained('lookup_values');
            $t->date('contract_end_on')->nullable();
            $t->date('probation_end_on')->nullable();
            $t->text('salary_amount')->nullable();                 // encrypted cast (ciphertext)
            $t->string('salary_currency', 3)->nullable();
            $t->text('reason')->nullable();
            $t->ulid('review_id')->nullable();                     // the performance review this decision came from
            $t->ulid('approval_request_id')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('applied_at')->nullable();               // when the employee snapshot was brought up to this row
            $t->timestamps();
            $t->index(['employee_id', 'effective_from']);
            $t->index(['organization_id', 'status_id']);
            $t->foreign('position_id')->references('id')->on('hr_positions')->nullOnDelete();
            $t->foreign('grade_id')->references('id')->on('hr_grades')->nullOnDelete();
            $t->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
            $t->foreign('manager_employee_id')->references('id')->on('hr_employees')->nullOnDelete();
        });

        Schema::create('hr_review_cycles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->date('period_start');
            $t->date('period_end');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->timestamps();
        });

        Schema::create('hr_performance_reviews', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('cycle_id')->constrained('hr_review_cycles')->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->ulid('reviewer_employee_id')->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->foreignUlid('rating_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('recommendation_id')->nullable()->constrained('lookup_values');
            $t->text('summary')->nullable();
            $t->text('goals')->nullable();
            $t->text('employee_comment')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamps();
            $t->unique(['cycle_id', 'employee_id']);
            $t->foreign('reviewer_employee_id')->references('id')->on('hr_employees')->nullOnDelete();
        });

        Schema::create('hr_reminder_log', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->string('kind', 30);                                // probation | contract
            $t->date('due_on');
            $t->unsignedInteger('step_days');
            $t->timestamp('sent_at');
            $t->unique(['employee_id', 'kind', 'due_on', 'step_days']);
        });

        Schema::table('hr_employees', function (Blueprint $t) {    // current snapshot of the latest effective record (no FK: SQLite cannot add them)
            $t->ulid('position_id')->nullable();
            $t->ulid('grade_id')->nullable();
            $t->date('contract_end_on')->nullable();
            $t->date('probation_end_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employees', fn (Blueprint $t) => $t->dropColumn(['position_id', 'grade_id', 'contract_end_on', 'probation_end_on']));
        foreach (['hr_reminder_log', 'hr_performance_reviews', 'hr_review_cycles', 'hr_employment_records', 'hr_progression_criteria', 'hr_positions', 'hr_grades'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
