<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 7e: event expenses against the event budget, approved through the approval engine; receipts are controlled files (link type "event_expense"). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_expenses', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('event_id')->constrained('events')->cascadeOnDelete();
            $t->foreignUlid('category_id')->constrained('lookup_values');
            $t->string('description', 500);
            $t->string('vendor', 190)->nullable();
            $t->date('expense_date');
            $t->decimal('amount', 15, 2);                                   // in the currency of the event
            $t->foreignUlid('status_id')->constrained('lookup_values');     // draft | pending_approval | approved | rejected
            $t->boolean('over_budget')->default(false);                     // flagged when submitted: it needed an explicit approval beyond the budget
            $t->ulid('approval_request_id')->nullable();
            $t->timestamp('submitted_at')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 2000)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['event_id', 'status_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('event_expenses'); }
};
