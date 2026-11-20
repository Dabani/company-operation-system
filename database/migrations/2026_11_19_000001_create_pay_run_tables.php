<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 6b: payroll runs and payslips (Guide 5.6.1). A run moves Draft -> Calculated -> Reviewed -> Approved -> Locked -> Paid -> Declared with different
 * people at each stage. Payslips persist every line with its inputs (base, rate, rounding, scheme version) so they can be reproduced years later.
 * A locked run is immutable (the models refuse any change); a correction is an adjustment run. Money DECIMAL(15,2), rates DECIMAL(9,6). No enums, no JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pay_runs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->foreignUlid('kind_id')->constrained('lookup_values');              // regular | adjustment
            $t->date('period_from');
            $t->date('period_to');
            $t->foreignUlid('currency_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->ulid('parent_run_id')->nullable();                                   // an adjustment run corrects this locked run
            $t->boolean('md_required');                                              // snapshot of the setting when the run was created
            $t->unsignedTinyInteger('proration_mode');                               // snapshot: 0 none, 1 calendar days, 2 working days
            $t->unsignedTinyInteger('earnings_decimals');                            // snapshot
            $t->foreignUlid('prepared_by')->constrained('users');
            $t->foreignUlid('calculated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('calculated_at')->nullable();
            $t->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('reviewed_at')->nullable();
            $t->foreignUlid('finance_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('finance_at')->nullable();
            $t->foreignUlid('md_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('md_at')->nullable();
            $t->foreignUlid('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('locked_at')->nullable();
            $t->date('paid_on')->nullable();
            $t->foreignUlid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('payment_reference', 100)->nullable();
            $t->date('declared_on')->nullable();
            $t->foreignUlid('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('declaration_reference', 100)->nullable();
            $t->text('note')->nullable();
            $t->text('returned_reason')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status_id', 'period_from'], 'pay_runs_status');
            $t->foreign('parent_run_id')->references('id')->on('pay_runs')->restrictOnDelete();
        });

        Schema::create('pay_run_members', function (Blueprint $t) {                 // the employees of an adjustment run
            $t->foreignUlid('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->primary(['run_id', 'employee_id']);
        });

        Schema::create('pay_run_exclusions', function (Blueprint $t) {              // deliberately left out of this run, with a reason
            $t->foreignUlid('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->string('reason', 255);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->primary(['run_id', 'employee_id']);
        });

        Schema::create('pay_run_inputs', function (Blueprint $t) {                  // variable lines entered by the preparer (overtime, bonus, an unpaid-leave deduction...)
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->foreignUlid('component_id')->constrained('pay_components');
            $t->decimal('amount', 15, 2);
            $t->string('note', 255)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['run_id', 'employee_id']);
        });

        Schema::create('pay_payslips', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->foreignUlid('status_id')->constrained('lookup_values');            // calculated | blocked | excluded
            $t->string('employee_name', 200);                                        // snapshots: a payslip never changes when the employee record does
            $t->string('employee_number', 40)->nullable();
            $t->text('bank_account')->nullable();                                    // encrypted cast
            $t->string('currency_code', 8);
            $t->string('category_code', 60)->nullable();
            $t->decimal('proration_factor', 9, 6)->nullable();
            $t->unsignedSmallInteger('worked_days')->nullable();
            $t->unsignedSmallInteger('period_days')->nullable();
            $t->decimal('gross', 15, 2)->default(0);
            $t->decimal('taxable_earnings', 15, 2)->default(0);
            $t->decimal('pensionable_earnings', 15, 2)->default(0);
            $t->decimal('employee_contributions', 15, 2)->default(0);
            $t->decimal('deductions', 15, 2)->default(0);
            $t->decimal('net', 15, 2)->default(0);
            $t->decimal('employer_contributions', 15, 2)->default(0);
            $t->decimal('employer_cost', 15, 2)->default(0);
            $t->ulid('original_payslip_id')->nullable();                             // adjustment runs: the payslip being corrected
            $t->decimal('net_difference', 15, 2)->nullable();                        // new net - original net
            $t->string('excluded_reason', 255)->nullable();
            $t->ulid('employment_record_id')->nullable();
            $t->timestamps();
            $t->unique(['run_id', 'employee_id']);
            $t->index('employee_id');
        });

        Schema::create('pay_payslip_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('payslip_id')->constrained('pay_payslips')->cascadeOnDelete();
            $t->foreignUlid('kind_id')->constrained('lookup_values');              // earning | scheme_employee | scheme_employer | deduction
            $t->string('code', 40);
            $t->string('name', 150);                                                 // the name at the time of the run
            $t->string('source', 20)->nullable();                                    // earnings: basic_salary | recurring | variable
            $t->decimal('base', 15, 2)->nullable();
            $t->decimal('rate', 9, 6)->nullable();
            $t->decimal('amount', 15, 2);
            $t->boolean('taxable')->nullable();
            $t->boolean('pensionable')->nullable();
            $t->ulid('version_id')->nullable();                                      // the scheme version that produced the line
            $t->string('base_kind', 40)->nullable();
            $t->string('rounding', 10)->nullable();
            $t->unsignedTinyInteger('decimals')->nullable();
            $t->string('statutory_type', 30)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->index('payslip_id');
        });

        Schema::create('pay_payslip_bands', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('line_id')->constrained('pay_payslip_lines')->cascadeOnDelete();
            $t->decimal('lower', 15, 2);
            $t->decimal('upper', 15, 2)->nullable();
            $t->decimal('rate', 9, 6);
            $t->decimal('part', 15, 2);
            $t->decimal('amount', 15, 4);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->index('line_id');
        });

        Schema::create('pay_payslip_flags', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('payslip_id')->constrained('pay_payslips')->cascadeOnDelete();
            $t->foreignUlid('severity_id')->constrained('lookup_values');          // blocker | warning | info
            $t->string('code', 40);
            $t->string('detail', 255)->nullable();                                   // the parameters of the message, as text
            $t->index('payslip_id');
        });
    }

    public function down(): void
    {
        foreach (['pay_payslip_flags', 'pay_payslip_bands', 'pay_payslip_lines', 'pay_payslips', 'pay_run_inputs', 'pay_run_exclusions', 'pay_run_members', 'pay_runs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
