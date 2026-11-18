<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 8a (Guide section 7): supplier master with verified bank details, and purchase requisitions. Statuses and kinds are lookups. Bank account numbers are encrypted. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->string('name', 190);
            $t->string('legal_name', 190)->nullable();
            $t->string('tax_number', 60)->nullable();
            $t->string('registration_number', 60)->nullable();
            $t->char('country_code', 2)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('contact_name', 150)->nullable();
            $t->string('address', 500)->nullable();
            $t->foreignUlid('category_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');     // draft | under_review | approved | suspended | blocked | archived
            $t->string('status_reason', 1000)->nullable();
            $t->ulid('approval_request_id')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status_id']);
        });

        Schema::create('supplier_bank_accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $t->string('bank_name', 150);
            $t->string('account_name', 190);
            $t->text('account_number');                                      // encrypted cast
            $t->string('swift_code', 20)->nullable();
            $t->char('currency_code', 3)->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');     // pending_verification | verified | replaced | rejected
            $t->foreignUlid('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();   // never the same person as proposed_by
            $t->timestamp('verified_at')->nullable();
            $t->string('verification_note', 1000)->nullable();
            $t->timestamps();
            $t->index(['supplier_id', 'status_id']);
        });

        Schema::create('supplier_status_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('supplier_id')->constrained('suppliers')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 1000)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['supplier_id', 'created_at']);
        });

        Schema::create('purchase_requisitions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->string('title', 200);
            $t->longText('purpose')->nullable();                             // purified HTML
            $t->foreignUlid('procurement_type_id')->constrained('lookup_values');   // goods | works | consultancy | services
            $t->foreignUlid('department_id')->constrained('departments');            // the cost centre
            $t->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->foreignUlid('requester_id')->constrained('users');
            $t->foreignUlid('preferred_supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $t->string('preferred_supplier_reason', 1000)->nullable();       // a preference needs a reason (restricted sourcing is recorded)
            $t->date('needed_by');
            $t->string('delivery_location', 300);
            $t->char('currency_code', 3);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->foreignUlid('status_id')->constrained('lookup_values');     // draft | pending_approval | approved | rejected | cancelled
            $t->boolean('over_budget')->default(false);
            $t->string('exception_reason', 1000)->nullable();                // required to submit an over-budget requisition
            $t->boolean('split_suspected')->default(false);                  // repeated purchases that together cross the threshold
            $t->ulid('approval_request_id')->nullable();
            $t->string('decision_note', 2000)->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status_id']);
            $t->index(['requester_id', 'status_id']);
        });

        Schema::create('purchase_requisition_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('description', 500);
            $t->decimal('quantity', 14, 3);
            $t->string('unit', 30)->nullable();
            $t->decimal('unit_price', 15, 2);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
            $t->unique(['requisition_id', 'line_no']);
        });

        Schema::create('purchase_requisition_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('requisition_id')->constrained('purchase_requisitions')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 1000)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['requisition_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['purchase_requisition_history', 'purchase_requisition_lines', 'purchase_requisitions', 'supplier_status_history', 'supplier_bank_accounts', 'suppliers'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
