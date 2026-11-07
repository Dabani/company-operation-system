<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 2b - invoices, payments, credit notes. Money is DECIMAL(15,2) with a currency code per document.
 * Every calculation input is stored (rates, rounding, tax percent, source time entries) so totals stay reproducible years later.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('projects', fn (Blueprint $t) => $t->foreignUlid('billing_model_id')->nullable()->after('rate_card_id')->constrained('lookup_values')->nullOnDelete());
        Schema::table('milestones', fn (Blueprint $t) => $t->decimal('billing_amount', 15, 2)->nullable()->after('due_date'));

        Schema::create('tax_rates', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 100);
            $t->decimal('rate_percent', 7, 4);
            $t->date('valid_from');
            $t->date('valid_to')->nullable();
            $t->boolean('is_default')->default(false);
            $t->string('source_citation', 255)->nullable();
            $t->foreignUlid('verification_status_id')->constrained('lookup_values');
            $t->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'valid_from']);
        });

        Schema::create('document_sequences', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('seq_key', 60);                                  // e.g. INV-2026
            $t->unsignedInteger('last_value')->default(0);
            $t->unique(['organization_id', 'seq_key']);
        });

        Schema::create('invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 40)->nullable();                       // assigned at issue: no gaps from cancelled drafts
            $t->foreignUlid('project_id')->constrained('projects');
            $t->foreignUlid('client_id')->constrained('clients');
            $t->foreignUlid('billing_model_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->char('currency_code', 3);
            $t->date('issue_date')->nullable();
            $t->date('due_date')->nullable();
            $t->unsignedSmallInteger('payment_terms_days')->default(30);
            $t->text('notes')->nullable();
            // calculation snapshot
            $t->unsignedSmallInteger('rounding_minutes')->default(1);
            $t->string('rounding_mode', 12)->default('half_up');
            $t->foreignUlid('tax_rate_id')->nullable()->constrained('tax_rates')->nullOnDelete();
            $t->string('tax_name', 100)->nullable();
            $t->decimal('tax_percent', 7, 4)->nullable();
            $t->boolean('tax_exempt')->default(false);
            $t->decimal('subtotal', 15, 2)->default(0);
            $t->decimal('tax_total', 15, 2)->default(0);
            $t->decimal('total', 15, 2)->default(0);
            $t->decimal('paid_total', 15, 2)->default(0);
            $t->decimal('credited_total', 15, 2)->default(0);
            $t->decimal('balance', 15, 2)->default(0);
            // client details frozen at issue
            $t->string('client_name')->nullable();
            $t->text('client_address')->nullable();
            $t->string('client_tax_number', 60)->nullable();
            $t->ulid('approval_request_id')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
            $t->index(['organization_id', 'status_id']);
            $t->index(['project_id']);
            $t->foreign('approval_request_id')->references('id')->on('approval_requests')->nullOnDelete();
        });

        Schema::create('invoice_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('invoice_id')->constrained()->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->foreignUlid('kind_id')->constrained('lookup_values');   // time | fixed | milestone | manual
            $t->string('description', 500);
            $t->decimal('quantity', 15, 4);
            $t->string('unit', 20)->default('h');
            $t->decimal('unit_price', 15, 2);
            $t->decimal('amount', 15, 2);
            $t->foreignUlid('rate_card_line_id')->nullable()->constrained('rate_card_lines')->nullOnDelete();
            $t->integer('raw_seconds')->nullable();                      // before rounding
            $t->decimal('rounded_minutes', 15, 2)->nullable();           // after rounding (what was billed)
            $t->foreignUlid('milestone_id')->nullable()->constrained('milestones')->nullOnDelete();
            $t->timestamps();
            $t->unique('milestone_id');
            $t->index('invoice_id');
        });

        Schema::create('invoice_line_sources', function (Blueprint $t) {  // which approved time entries a line was built from
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('invoice_line_id')->constrained('invoice_lines')->cascadeOnDelete();
            $t->foreignUlid('time_entry_id')->constrained('time_entries');
            $t->integer('seconds');
            $t->decimal('hourly_rate', 15, 2);
            $t->unique('time_entry_id');                                 // one entry can be invoiced only once
        });

        Schema::create('invoice_payments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('invoice_id')->constrained('invoices');
            $t->decimal('amount', 15, 2);                                // negative for a reversal
            $t->date('paid_on');
            $t->foreignUlid('method_id')->constrained('lookup_values');
            $t->string('reference', 120)->nullable();
            $t->text('notes')->nullable();
            $t->ulid('reversal_of_id')->nullable();
            $t->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index('invoice_id');
            $t->foreign('reversal_of_id')->references('id')->on('invoice_payments')->nullOnDelete();
        });

        Schema::create('credit_notes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('number', 40)->nullable();
            $t->foreignUlid('invoice_id')->constrained('invoices');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->text('reason');
            $t->decimal('amount', 15, 2);                                // before tax
            $t->decimal('tax_total', 15, 2)->default(0);
            $t->decimal('total', 15, 2);
            $t->ulid('approval_request_id')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('issued_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'number']);
            $t->index('invoice_id');
            $t->foreign('approval_request_id')->references('id')->on('approval_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['credit_notes', 'invoice_payments', 'invoice_line_sources', 'invoice_lines', 'invoices', 'document_sequences', 'tax_rates'] as $t) Schema::dropIfExists($t);
        Schema::table('milestones', fn (Blueprint $t) => $t->dropColumn('billing_amount'));
        Schema::table('projects', function (Blueprint $t) { $t->dropForeign(['billing_model_id']); $t->dropColumn('billing_model_id'); });
    }
};
