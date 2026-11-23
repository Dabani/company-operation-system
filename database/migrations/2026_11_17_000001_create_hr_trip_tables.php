<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 5b: business trips and expenses (Guide 5.5). Money is DECIMAL(15,2) with an explicit currency, never float. No enums, no JSON.
 * Rates are effective-dated rows (a change = a new row), advances and settlements are never edited after the fact.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_trip_rates', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('kind_id')->constrained('lookup_values');             // per_diem | accommodation | transport
            $t->foreignUlid('zone_id')->constrained('lookup_values');             // destination zone
            $t->ulid('grade_id')->nullable();                                       // empty = every grade
            $t->foreignUlid('currency_id')->constrained('lookup_values');
            $t->decimal('amount', 15, 2);
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('source_note', 255)->nullable();                           // the internal decision / policy this rate comes from
            $t->boolean('is_active')->default(true);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->foreign('grade_id')->references('id')->on('hr_grades')->nullOnDelete();
            $t->index(['organization_id', 'kind_id', 'zone_id', 'valid_from'], 'hr_trip_rates_lookup');
        });

        Schema::create('hr_trips', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->foreignUlid('requested_by')->constrained('users');
            $t->text('purpose');
            $t->string('destination', 200);
            $t->foreignUlid('zone_id')->constrained('lookup_values');
            $t->date('start_date');
            $t->date('end_date');
            $t->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->string('linked_meeting', 200)->nullable();
            $t->text('itinerary')->nullable();
            $t->foreignUlid('currency_id')->constrained('lookup_values');
            $t->decimal('budget_estimate', 15, 2);
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->ulid('approval_request_id')->nullable();
            $t->string('authorization_number', 30)->nullable();                   // mission authorization, issued on approval
            $t->timestamp('authorized_at')->nullable();
            $t->text('report_text')->nullable();
            $t->timestamp('report_submitted_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->foreignUlid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->unique(['organization_id', 'authorization_number'], 'hr_trips_authorization');
            $t->index(['organization_id', 'status_id', 'start_date'], 'hr_trips_status');
        });

        Schema::create('hr_trip_travelers', function (Blueprint $t) {
            $t->foreignUlid('trip_id')->constrained('hr_trips')->cascadeOnDelete();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->primary(['trip_id', 'employee_id']);
            $t->index('employee_id');
        });

        Schema::create('hr_trip_bookings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('trip_id')->constrained('hr_trips')->cascadeOnDelete();
            $t->foreignUlid('kind_id')->constrained('lookup_values');             // flight | bus | hotel | vehicle | other
            $t->string('provider', 150);
            $t->string('reference', 100)->nullable();
            $t->date('starts_on')->nullable();
            $t->date('ends_on')->nullable();
            $t->decimal('amount', 15, 2)->nullable();
            $t->text('notes')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('hr_trip_advances', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('trip_id')->constrained('hr_trips')->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->decimal('amount', 15, 2);
            $t->foreignUlid('status_id')->constrained('lookup_values');           // requested | paid | retired
            $t->date('paid_on')->nullable();
            $t->date('due_on')->nullable();                                         // the date the advance must be retired by
            $t->foreignUlid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('retired_at')->nullable();
            $t->date('last_reminded_on')->nullable();
            $t->timestamps();
            $t->unique(['trip_id', 'employee_id']);
            $t->index(['organization_id', 'status_id', 'due_on'], 'hr_trip_advances_due');
        });

        Schema::create('hr_trip_expenses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('trip_id')->constrained('hr_trips')->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->foreignUlid('category_id')->constrained('lookup_values');
            $t->date('expense_date');
            $t->string('description', 255);
            $t->decimal('amount', 15, 2);
            $t->boolean('is_generated')->default(false);                           // per diem lines computed from the rate table
            $t->foreignUlid('status_id')->constrained('lookup_values');           // draft | submitted | accepted | rejected
            $t->text('review_note')->nullable();
            $t->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamps();
            $t->index(['trip_id', 'employee_id']);
        });

        Schema::create('hr_trip_settlements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('trip_id')->constrained('hr_trips')->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->decimal('accepted_total', 15, 2);
            $t->decimal('advance_total', 15, 2);
            $t->decimal('balance', 15, 2);                                         // accepted - advance: + the organization owes, - the traveller owes
            $t->foreignUlid('resolution_id')->constrained('lookup_values');       // paid_to_employee | refunded_by_employee | payroll_deduction | waived
            $t->text('note')->nullable();
            $t->foreignUlid('settled_by')->constrained('users');
            $t->timestamp('settled_at');
            $t->timestamp('created_at')->nullable();
            $t->unique(['trip_id', 'employee_id']);
        });

        // A payroll deduction is possible only with the employee's recorded consent and a stated lawful basis (Guide 5.5). Phase 6 reads this table, never executes without it.
        Schema::create('hr_trip_deduction_consents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('settlement_id')->constrained('hr_trip_settlements')->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->decimal('amount', 15, 2);
            $t->date('consented_on');
            $t->text('lawful_basis');
            $t->foreignUlid('recorded_by')->constrained('users');
            $t->timestamp('created_at')->nullable();
            $t->unique('settlement_id');
        });
    }

    public function down(): void
    {
        foreach (['hr_trip_deduction_consents', 'hr_trip_settlements', 'hr_trip_expenses', 'hr_trip_advances', 'hr_trip_bookings', 'hr_trip_travelers', 'hr_trips', 'hr_trip_rates'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
