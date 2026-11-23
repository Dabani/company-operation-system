<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 5a: leave. Entitlements are never stored as a number that someone typed: balances are the sum of an append-only ledger
 * (accruals, usage, adjustments, forfeitures), policy lives in the shared rules engine (domain "leave") and statutory limits in legal_parameters.
 * No enums, no JSON. Day counts are DECIMAL(7,2) so half days are exact.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_leave_type_settings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('leave_type_id')->constrained('lookup_values');
            $t->foreignUlid('day_basis_id')->constrained('lookup_values');         // working days or calendar days
            $t->boolean('uses_balance')->default(false);                           // deducts from an accruing balance (annual, compensatory...)
            $t->boolean('accrues')->default(false);                                // the balance grows month by month
            $t->boolean('is_paid')->default(true);                                 // payroll (phase 6) reads this: confirm each value with the accountant
            $t->boolean('requires_document')->default(false);                      // e.g. a medical certificate
            $t->boolean('allow_retroactive')->default(false);                      // may be requested after it started (sick leave)
            $t->boolean('ignores_blackout')->default(false);                       // blackout periods do not apply (sick, maternity...)
            $t->boolean('allow_half_days')->default(true);
            $t->boolean('is_requestable')->default(true);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'leave_type_id']);
        });

        // Facts the statutory rules depend on and the employee master does not hold. Narrow, encrypted, audited on read (Guide 5.7).
        Schema::create('hr_leave_profiles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->text('date_of_birth')->nullable();                                 // encrypted cast
            $t->foreignUlid('sex_id')->nullable()->constrained('lookup_values');
            $t->decimal('weekly_hours', 5, 2)->nullable();                         // contractual hours per week; empty = full time
            $t->foreignUlid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique('employee_id');
        });

        // The leave-specific side of a legal_parameters row (value, bound, unit, dates, citation, verification and verifier live there).
        Schema::create('hr_legal_leave_rules', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('legal_parameter_id')->constrained('legal_parameters')->cascadeOnDelete();
            $t->foreignUlid('leave_type_id')->nullable()->constrained('lookup_values');     // empty = every leave type
            $t->string('rule_key', 60);                                            // the policy key it bounds (see LeavePolicy::KEYS)
            $t->foreignUlid('sex_id')->nullable()->constrained('lookup_values');            // eligibility: empty = everyone
            $t->unsignedTinyInteger('min_age')->nullable();
            $t->unsignedTinyInteger('max_age')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique('legal_parameter_id');
            $t->index(['organization_id', 'rule_key']);
        });

        Schema::create('hr_leave_periods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('kind_id')->constrained('lookup_values');              // recommended | blackout
            $t->string('name', 150);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->foreignUlid('leave_type_id')->nullable()->constrained('lookup_values');
            $t->boolean('is_active')->default(true);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['organization_id', 'starts_on', 'ends_on'], 'hr_leave_periods_dates');
        });

        Schema::create('hr_leave_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->foreignUlid('leave_type_id')->constrained('lookup_values');
            $t->date('start_date');
            $t->date('end_date');
            $t->boolean('start_half')->default(false);
            $t->boolean('end_half')->default(false);
            $t->decimal('days', 7, 2);                                             // as counted when it was requested
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->text('reason')->nullable();
            $t->ulid('approval_request_id')->nullable();
            $t->ulid('rule_version_id')->nullable();                               // the policy version that decided the checks (history is never rewritten)
            $t->foreignUlid('requested_by')->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['employee_id', 'start_date']);
            $t->index(['organization_id', 'status_id', 'start_date'], 'hr_leave_requests_status');
            $t->foreign('rule_version_id')->references('id')->on('rule_versions')->nullOnDelete();
        });

        // One row per counted date of an APPROVED request: what attendance and the team calendar read.
        Schema::create('hr_leave_request_days', function (Blueprint $t) {
            $t->foreignUlid('request_id')->constrained('hr_leave_requests')->cascadeOnDelete();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->date('leave_date');
            $t->decimal('fraction', 3, 2);                                         // 1.00 or 0.50
            $t->primary(['request_id', 'leave_date']);
            $t->index(['employee_id', 'leave_date'], 'hr_leave_days_employee_date');
        });

        // Append-only. A balance is the sum of its rows; a mistake is corrected by a reversal or an adjustment, never an edit.
        Schema::create('hr_leave_ledger', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->foreignUlid('leave_type_id')->constrained('lookup_values');
            $t->foreignUlid('kind_id')->constrained('lookup_values');
            $t->date('entry_date');
            $t->decimal('days', 7, 2);                                             // + adds, - uses
            $t->unsignedSmallInteger('leave_year');
            $t->ulid('request_id')->nullable();
            $t->string('period_key', 20)->nullable();                              // makes accruals and year-end entries safe to run twice
            $t->text('reason')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
            $t->unique(['employee_id', 'leave_type_id', 'kind_id', 'period_key'], 'hr_leave_ledger_once');
            $t->index(['employee_id', 'leave_type_id', 'entry_date'], 'hr_leave_ledger_lookup');
            $t->foreign('request_id')->references('id')->on('hr_leave_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['hr_leave_ledger', 'hr_leave_request_days', 'hr_leave_requests', 'hr_leave_periods', 'hr_legal_leave_rules', 'hr_leave_profiles', 'hr_leave_type_settings'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
