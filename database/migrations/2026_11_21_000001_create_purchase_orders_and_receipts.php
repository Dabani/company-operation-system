<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 8b (Guide 7.4 steps 8 and 9): purchase orders raised from approved requisitions, and goods receipts with acceptance per line. Statuses are lookups. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->foreignUlid('requisition_id')->constrained('purchase_requisitions');
            $t->foreignUlid('supplier_id')->constrained('suppliers');
            $t->string('title', 200);
            $t->longText('terms')->nullable();                                // purified HTML: payment and delivery terms
            $t->string('delivery_location', 300);
            $t->date('needed_by');
            $t->char('currency_code', 3);
            $t->decimal('total_amount', 15, 2)->default(0);
            $t->foreignUlid('status_id')->constrained('lookup_values');      // draft | pending_approval | approved | issued | partially_received | received | closed | cancelled
            $t->ulid('approval_request_id')->nullable();
            $t->string('status_note', 2000)->nullable();
            $t->timestamp('issued_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status_id']);
            $t->index('requisition_id');
        });

        Schema::create('purchase_order_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $t->unsignedSmallInteger('line_no');
            $t->string('description', 500);
            $t->decimal('quantity', 14, 3);
            $t->string('unit', 30)->nullable();
            $t->decimal('unit_price', 15, 2);
            $t->decimal('line_total', 15, 2);
            $t->decimal('accepted_quantity', 14, 3)->default(0);             // running total of what was received and accepted
            $t->timestamps();
            $t->unique(['order_id', 'line_no']);
        });

        Schema::create('purchase_order_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 1000)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['order_id', 'created_at']);
        });

        Schema::create('goods_receipts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->foreignUlid('order_id')->constrained('purchase_orders')->cascadeOnDelete();
            $t->date('received_on');
            $t->string('delivery_note', 120)->nullable();                     // the supplier's delivery note number
            $t->string('note', 1000)->nullable();
            $t->foreignUlid('received_by')->constrained('users');
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index('order_id');
        });

        Schema::create('goods_receipt_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('receipt_id')->constrained('goods_receipts')->cascadeOnDelete();
            $t->foreignUlid('order_line_id')->constrained('purchase_order_lines');
            $t->decimal('quantity_received', 14, 3);
            $t->decimal('quantity_accepted', 14, 3);                          // what passed inspection; the rest is rejected
            $t->string('note', 500)->nullable();
            $t->timestamps();
            $t->index('order_line_id');
        });
    }

    public function down(): void
    {
        foreach (['goods_receipt_lines', 'goods_receipts', 'purchase_order_history', 'purchase_order_lines', 'purchase_orders'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
