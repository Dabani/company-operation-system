<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 8c (Guide 7.4 stages 10 and 11): supplier invoices with three-way match (order, receipt, invoice), discrepancy holds, approved exceptions and the payment handoff. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->foreignUlid('order_id')->constrained('purchase_orders');
            $t->foreignUlid('supplier_id')->constrained('suppliers');
            $t->string('invoice_number', 80);                                  // as printed by the supplier
            $t->string('number_key', 80);                                      // normalized form used to catch duplicates
            $t->date('invoice_date');
            $t->date('due_date')->nullable();
            $t->char('currency_code', 3);
            $t->decimal('subtotal', 15, 2);
            $t->decimal('tax_amount', 15, 2)->default(0);
            $t->decimal('total_amount', 15, 2);
            $t->foreignUlid('status_id')->constrained('lookup_values');        // received | on_hold | matched | approved | paid | rejected | cancelled
            $t->boolean('exception_approved')->default(false);
            $t->string('exception_note', 1000)->nullable();
            $t->foreignUlid('exception_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('decision_note', 1000)->nullable();
            $t->foreignUlid('recorded_by')->constrained('users');
            $t->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('approved_at')->nullable();
            $t->foreignUlid('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $t->date('paid_on')->nullable();
            $t->string('payment_reference', 120)->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status_id']);
            $t->index(['organization_id', 'supplier_id', 'number_key']);
            $t->index('order_id');
        });

        Schema::create('supplier_invoice_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $t->foreignUlid('order_line_id')->constrained('purchase_order_lines');
            $t->decimal('quantity', 14, 3);
            $t->decimal('unit_price', 15, 2);
            $t->decimal('line_total', 15, 2);
            $t->timestamps();
            $t->index('order_line_id');
        });

        Schema::create('supplier_invoice_issues', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $t->foreignUlid('order_line_id')->nullable()->constrained('purchase_order_lines')->nullOnDelete();
            $t->string('code', 40);                                            // qty_over | price_over | tax_mismatch | currency | not_received
            $t->string('detail', 300)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index('invoice_id');
        });

        Schema::create('supplier_invoice_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 1000)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['invoice_id', 'created_at']);
        });
    }

    public function down(): void
    {
        foreach (['supplier_invoice_history', 'supplier_invoice_issues', 'supplier_invoice_lines', 'supplier_invoices'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
