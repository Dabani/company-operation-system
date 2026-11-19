<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 8d (Guide 7.4 sourcing): requests for quotation raised from an approved requisition, supplier quotes per requisition line, a documented award, and the link to the purchase order. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('rfqs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->foreignUlid('requisition_id')->constrained('purchase_requisitions');
            $t->string('title', 200);
            $t->longText('instructions')->nullable();                         // purified HTML sent to suppliers
            $t->date('closing_date');
            $t->foreignUlid('status_id')->constrained('lookup_values');      // draft | open | closed | awarded | cancelled
            $t->string('sole_source_reason', 1000)->nullable();              // required when fewer suppliers than the minimum are invited
            $t->ulid('awarded_quote_id')->nullable();
            $t->string('award_reason', 1000)->nullable();                    // required when the award is not the lowest complete quote
            $t->foreignUlid('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('awarded_at')->nullable();
            $t->string('status_note', 1000)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status_id']);
            $t->index('requisition_id');
        });

        Schema::create('rfq_quotes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $t->foreignUlid('supplier_id')->constrained('suppliers');
            $t->unsignedSmallInteger('lead_days')->nullable();
            $t->date('valid_until')->nullable();
            $t->string('notes', 2000)->nullable();
            $t->decimal('total_amount', 15, 2)->nullable();
            $t->boolean('declined')->default(false);
            $t->string('decline_reason', 500)->nullable();
            $t->timestamp('submitted_at')->nullable();                       // null = invited, nothing received yet
            $t->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['rfq_id', 'supplier_id']);
        });

        Schema::create('rfq_quote_lines', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('quote_id')->constrained('rfq_quotes')->cascadeOnDelete();
            $t->foreignUlid('requisition_line_id')->constrained('purchase_requisition_lines');
            $t->decimal('unit_price', 15, 2);
            $t->decimal('line_total', 15, 2);
            $t->unique(['quote_id', 'requisition_line_id']);
        });

        Schema::create('rfq_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('rfq_id')->constrained('rfqs')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 2000)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::table('purchase_orders', function (Blueprint $t) {
            $t->ulid('rfq_id')->nullable()->after('requisition_id');         // set when the order comes from an awarded RFQ
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', fn (Blueprint $t) => $t->dropColumn('rfq_id'));
        Schema::dropIfExists('rfq_history');
        Schema::dropIfExists('rfq_quote_lines');
        Schema::dropIfExists('rfq_quotes');
        Schema::dropIfExists('rfqs');
    }
};
