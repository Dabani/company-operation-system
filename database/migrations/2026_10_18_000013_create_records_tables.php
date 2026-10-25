<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Laws, regulations, standards the organization relies on. organization_id NULL = shared starter content.
        Schema::create('legal_references', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->nullable()->index();
            $t->string('code', 60);
            $t->string('title');
            $t->text('citation')->nullable();
            $t->string('jurisdiction', 60)->nullable();
            $t->string('topic', 120)->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');       // verified | unreviewed | superseded
            $t->string('url')->nullable();
            $t->text('notes')->nullable();
            $t->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        // How long each category of record is kept. A rule with organization_id NULL is the shared default;
        // an organization overrides it by adding its own rule for the same category.
        Schema::create('retention_rules', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->nullable()->index();
            $t->foreignUlid('category_id')->constrained('lookup_values');
            $t->unsignedSmallInteger('retention_years');
            $t->foreignUlid('disposal_action_id')->constrained('lookup_values');   // destroy | review | transfer
            $t->foreignUlid('legal_reference_id')->nullable()->constrained('legal_references')->nullOnDelete();
            $t->string('notes', 500)->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'category_id']);
        });

        Schema::create('records', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40)->unique();                         // REC-2026-000123
            $t->string('title');
            $t->longText('description')->nullable();                       // purified HTML
            $t->foreignUlid('format_id')->constrained('lookup_values');    // physical | digital | hybrid
            $t->foreignUlid('category_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');    // active | inactive | archived | disposal_pending | disposed
            $t->foreignUlid('classification_id')->constrained('lookup_values');
            $t->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->foreignUlid('custodian_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $t->date('record_date');                                       // date of the document / engagement
            $t->string('client_name', 150)->nullable();
            $t->string('project_name', 150)->nullable();
            $t->string('index_code', 160);                                 // DEPT-CATEGORY-YYYYMMDD
            $t->date('became_inactive_on')->nullable();
            $t->date('transfer_due_on')->nullable();                       // inactive + transfer window (physical/hybrid)
            $t->date('archived_on')->nullable();
            $t->date('retention_until')->nullable();
            $t->boolean('legal_hold')->default(false);
            $t->string('hold_reason', 500)->nullable();
            $t->date('disposed_on')->nullable();
            $t->foreignUlid('created_by')->constrained('users');
            $t->timestamp('transfer_alerted_at')->nullable();
            $t->timestamp('retention_alerted_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status_id']);
            $t->index(['organization_id', 'retention_until']);
            $t->index(['organization_id', 'index_code']);
        });

        Schema::create('record_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('record_id')->constrained('records')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('event_type_id')->constrained('lookup_values');
            $t->string('old_ref', 40)->nullable();
            $t->string('new_ref', 40)->nullable();
            $t->string('note', 250)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['record_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_events');
        Schema::dropIfExists('records');
        Schema::dropIfExists('retention_rules');
        Schema::dropIfExists('legal_references');
    }
};
