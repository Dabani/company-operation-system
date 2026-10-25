<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Physical archive access register: who asked to see which record, who authorised it, when it left and came back.
        Schema::create('archive_access_logs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('record_id')->constrained('records');
            $t->foreignUlid('requested_by')->constrained('users');
            $t->string('purpose', 500);
            $t->foreignUlid('status_id')->constrained('lookup_values');     // requested | authorized | denied | returned
            $t->foreignUlid('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestamp('accessed_at')->nullable();
            $t->date('due_back_on')->nullable();
            $t->timestamp('returned_at')->nullable();
            $t->foreignUlid('received_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['organization_id', 'status_id']);
            $t->index(['record_id', 'status_id']);
        });

        // Periodic inventory of physical records; missing items are discrepancies reported to compliance.
        Schema::create('inventory_counts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->foreignUlid('status_id')->constrained('lookup_values');     // in_progress | completed
            $t->foreignUlid('location_id')->nullable()->constrained('locations')->nullOnDelete();   // NULL = everywhere
            $t->foreignUlid('started_by')->constrained('users');
            $t->timestamp('started_at')->useCurrent();
            $t->timestamp('completed_at')->nullable();
            $t->unsignedInteger('total_expected')->default(0);
            $t->unsignedInteger('total_found')->default(0);
            $t->unsignedInteger('total_missing')->default(0);
            $t->string('notes', 1000)->nullable();
            $t->timestamps();
        });

        Schema::create('inventory_count_items', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $t->foreignUlid('record_id')->constrained('records');
            $t->boolean('found')->nullable();                               // NULL = not checked yet
            $t->string('note', 250)->nullable();
            $t->timestamp('checked_at')->nullable();
            $t->foreignUlid('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['count_id', 'record_id']);
        });

        // Disposal needs a second person: proposer != approver; execution leaves a destruction certificate.
        Schema::create('disposal_batches', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40)->unique();                         // DSP-2026-000001
            $t->foreignUlid('status_id')->constrained('lookup_values');     // pending | approved | rejected | executed
            $t->foreignUlid('proposed_by')->constrained('users');
            $t->string('note', 500)->nullable();
            $t->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->foreignUlid('executed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('executed_at')->nullable();
            $t->string('method', 150)->nullable();                         // e.g. cross-cut shredding, secure erase
            $t->string('witness', 150)->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status_id']);
        });

        Schema::create('disposal_batch_items', function (Blueprint $t) {
            $t->foreignUlid('batch_id')->constrained('disposal_batches')->cascadeOnDelete();
            $t->foreignUlid('record_id')->constrained('records');
            $t->primary(['batch_id', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disposal_batch_items');
        Schema::dropIfExists('disposal_batches');
        Schema::dropIfExists('inventory_count_items');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('archive_access_logs');
    }
};
