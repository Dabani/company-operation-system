<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 4b: recruitment (requisition -> vacancy -> applicants -> interviews/scorecards -> selection -> offer -> employee) and onboarding. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_requisitions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->foreignUlid('department_id')->constrained('departments');
            $t->ulid('position_id')->nullable();
            $t->string('title', 150);
            $t->unsignedInteger('headcount')->default(1);
            $t->text('justification');
            $t->date('needed_by')->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->foreignUlid('requested_by')->constrained('users');
            $t->ulid('approval_request_id')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->foreign('position_id')->references('id')->on('hr_positions')->nullOnDelete();
        });

        Schema::create('hr_vacancies', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->foreignUlid('requisition_id')->constrained('hr_requisitions');
            $t->string('title', 150);
            $t->text('description')->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->date('opened_on');
            $t->date('closes_on')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->unique('requisition_id');                           // one posting per approved requisition
        });

        Schema::create('hr_applicants', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->foreignUlid('vacancy_id')->constrained('hr_vacancies');
            $t->string('first_name', 100);
            $t->string('last_name', 100);
            $t->string('email', 190);
            $t->text('phone')->nullable();                          // encrypted cast (ciphertext)
            $t->string('source', 60)->nullable();
            $t->text('notes')->nullable();
            $t->foreignUlid('stage_id')->constrained('lookup_values');
            $t->text('rejection_reason')->nullable();
            $t->date('applied_on');
            $t->timestamp('consent_recorded_at');
            $t->date('retention_until')->nullable();                // personal data is reviewed (never auto-deleted) from this date
            $t->timestamp('retention_flagged_at')->nullable();      // HR was told this record is due for review
            $t->timestamp('anonymized_at')->nullable();
            $t->ulid('converted_employee_id')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->unique(['vacancy_id', 'email']);
            $t->index(['organization_id', 'stage_id']);
            $t->foreign('converted_employee_id')->references('id')->on('hr_employees')->nullOnDelete();
        });

        Schema::create('hr_applicant_stage_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('applicant_id')->constrained('hr_applicants')->cascadeOnDelete();
            $t->foreignUlid('from_stage_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_stage_id')->constrained('lookup_values');
            $t->text('note')->nullable();
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
        });

        Schema::create('hr_interviews', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('applicant_id')->constrained('hr_applicants')->cascadeOnDelete();
            $t->foreignUlid('type_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->timestamp('scheduled_at');
            $t->string('location', 255)->nullable();
            $t->text('notes')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('hr_interview_panelists', function (Blueprint $t) {
            $t->foreignUlid('interview_id')->constrained('hr_interviews')->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->primary(['interview_id', 'employee_id']);
        });

        Schema::create('hr_scorecards', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('interview_id')->constrained('hr_interviews')->cascadeOnDelete();
            $t->foreignUlid('panelist_employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->unsignedTinyInteger('score');                       // 1-5
            $t->foreignUlid('recommendation_id')->constrained('lookup_values');
            $t->text('strengths')->nullable();
            $t->text('concerns')->nullable();
            $t->timestamp('submitted_at');
            $t->timestamps();
            $t->unique(['interview_id', 'panelist_employee_id']);
        });

        Schema::create('hr_reference_checks', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('applicant_id')->constrained('hr_applicants')->cascadeOnDelete();
            $t->string('referee_name', 150);
            $t->string('referee_contact', 190)->nullable();
            $t->string('relationship', 100)->nullable();
            $t->foreignUlid('result_id')->constrained('lookup_values');
            $t->text('notes')->nullable();
            $t->date('checked_on')->nullable();
            $t->foreignUlid('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('hr_offers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->foreignUlid('applicant_id')->constrained('hr_applicants');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->ulid('position_id')->nullable();
            $t->ulid('grade_id')->nullable();
            $t->ulid('department_id')->nullable();
            $t->foreignUlid('category_id')->constrained('lookup_values');
            $t->foreignUlid('contract_type_id')->nullable()->constrained('lookup_values');
            $t->date('start_date');
            $t->unsignedSmallInteger('probation_months')->default(0);
            $t->date('contract_end_on')->nullable();
            $t->text('salary_amount');                              // encrypted cast (ciphertext)
            $t->string('salary_currency', 3);
            $t->string('salary_period', 10)->default('monthly');     // monthly | annual
            $t->text('terms')->nullable();
            $t->text('band_exception_reason')->nullable();
            $t->date('valid_until')->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->date('responded_on')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->foreign('position_id')->references('id')->on('hr_positions')->nullOnDelete();
            $t->foreign('grade_id')->references('id')->on('hr_grades')->nullOnDelete();
            $t->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
        });

        Schema::create('hr_onboarding_template_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('title', 200);
            $t->integer('offset_days')->default(0);                 // due date = start date + offset
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('hr_onboarding_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->string('title', 200);
            $t->date('due_on')->nullable();
            $t->ulid('assignee_user_id')->nullable();
            $t->timestamp('done_at')->nullable();
            $t->ulid('done_by')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'done_at']);
            $t->foreign('assignee_user_id')->references('id')->on('users')->nullOnDelete();
            $t->foreign('done_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('hr_settings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('key', 60);
            $t->string('value', 255);
            $t->timestamps();
            $t->unique(['organization_id', 'key']);
        });
    }

    public function down(): void
    {
        foreach (['hr_settings', 'hr_onboarding_items', 'hr_onboarding_template_items', 'hr_offers', 'hr_reference_checks', 'hr_scorecards', 'hr_interview_panelists', 'hr_interviews',
            'hr_applicant_stage_history', 'hr_applicants', 'hr_vacancies', 'hr_requisitions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
