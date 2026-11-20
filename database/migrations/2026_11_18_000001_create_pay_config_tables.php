<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 6a: payroll configuration (Guide 5.6). No rate, threshold, band or exemption is ever seeded: the accountant enters each one with its source,
 * and a DIFFERENT person verifies it. Everything is effective-dated; a verified row is never edited (it is ended and a new one entered).
 * Money DECIMAL(15,2), rates DECIMAL(9,6) as percentages. No enums, no JSON.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pay_components', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name', 150);
            $t->foreignUlid('kind_id')->constrained('lookup_values');             // earning | deduction
            $t->foreignUlid('source_id')->constrained('lookup_values');           // basic_salary | recurring | variable
            $t->boolean('is_taxable');                                              // no default: the accountant decides, nothing is assumed
            $t->boolean('is_pensionable');
            $t->string('ledger_account', 40)->nullable();                          // for the accounting payroll journal
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('pay_schemes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name', 150);
            $t->foreignUlid('statutory_type_id')->constrained('lookup_values');   // paye | pension | maternity | health | occupational | other (declarations)
            $t->foreignUlid('mode_id')->constrained('lookup_values');             // flat_rate | brackets
            $t->unsignedSmallInteger('calc_order');                                // schemes run in this order
            $t->foreignUlid('base_kind_id')->constrained('lookup_values');
            $t->boolean('reduces_taxable_base');                                    // the employee contribution lowers the base of later schemes
            $t->foreignUlid('rounding_id')->constrained('lookup_values');
            $t->unsignedTinyInteger('decimals');                                    // 0 for a currency without minor units
            $t->boolean('round_each_band')->default(false);
            $t->boolean('is_active')->default(true);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('pay_scheme_versions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('scheme_id')->constrained('pay_schemes')->cascadeOnDelete();
            $t->decimal('employee_rate', 9, 6)->nullable();
            $t->decimal('employer_rate', 9, 6)->nullable();
            $t->decimal('base_cap', 15, 2)->nullable();
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('source_citation', 500);                                     // the official notice / the accountant's signed schedule
            $t->foreignUlid('verification_status_id')->constrained('lookup_values');
            $t->foreignUlid('prepared_by')->constrained('users');
            $t->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['scheme_id', 'valid_from']);
        });

        Schema::create('pay_tax_brackets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('version_id')->constrained('pay_scheme_versions')->cascadeOnDelete();
            $t->decimal('lower', 15, 2);
            $t->decimal('upper', 15, 2)->nullable();
            $t->decimal('rate', 9, 6);
            $t->index('version_id');
        });

        // Which schemes apply to which employee category, plus approved exceptions for one employee. No assignment = the scheme does not apply (and the dry-run says so).
        Schema::create('pay_scheme_assignments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('scheme_id')->constrained('pay_schemes')->cascadeOnDelete();
            $t->foreignUlid('category_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('employee_id')->nullable()->constrained('hr_employees')->cascadeOnDelete();
            $t->boolean('applies');
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('reason', 500)->nullable();                                  // required for an employee exception
            $t->foreignUlid('verification_status_id')->constrained('lookup_values');
            $t->foreignUlid('prepared_by')->constrained('users');
            $t->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['scheme_id', 'category_id', 'valid_from'], 'pay_assign_category');
            $t->index(['scheme_id', 'employee_id', 'valid_from'], 'pay_assign_employee');
        });

        // Recurring pay items of an employee (a housing allowance, a fixed deduction...). Also verified by a second person before they count.
        Schema::create('pay_employee_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->foreignUlid('component_id')->constrained('pay_components');
            $t->decimal('amount', 15, 2);
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->string('reason', 500)->nullable();
            $t->foreignUlid('verification_status_id')->constrained('lookup_values');
            $t->foreignUlid('prepared_by')->constrained('users');
            $t->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'valid_from']);
        });
    }

    public function down(): void
    {
        foreach (['pay_employee_items', 'pay_scheme_assignments', 'pay_tax_brackets', 'pay_scheme_versions', 'pay_schemes', 'pay_components'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
