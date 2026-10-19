<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Generic locked counters for human-readable references (asset tags, movement forms, ...).
        Schema::create('reference_counters', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('scope', 40);                       // asset | movement
            $t->unsignedSmallInteger('year')->default(0);  // 0 = never resets
            $t->unsignedInteger('last_number')->default(0);
            $t->unique(['organization_id', 'scope', 'year']);
        });

        Schema::create('assets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('asset_tag', 30);
            $t->foreignUlid('type_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->foreignUlid('condition_id')->constrained('lookup_values');
            $t->string('name');
            $t->string('manufacturer', 120)->nullable();
            $t->string('model', 120)->nullable();
            $t->string('serial_number', 120)->nullable();
            $t->date('purchase_date')->nullable();
            $t->decimal('purchase_cost', 14, 2)->nullable();
            $t->date('warranty_expires_on')->nullable();
            $t->foreignUlid('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->foreignUlid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $t->longText('notes')->nullable();                         // purified HTML
            $t->timestamps();
            $t->unique(['organization_id', 'asset_tag']);
            $t->index(['organization_id', 'status_id']);
            $t->index(['organization_id', 'assigned_user_id']);
        });

        // Append-only history of every asset (register trail).
        Schema::create('asset_events', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('asset_id')->constrained('assets')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('event_type_id')->constrained('lookup_values');
            $t->string('old_ref', 40)->nullable();
            $t->string('new_ref', 40)->nullable();
            $t->string('note', 250)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['asset_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_events');
        Schema::dropIfExists('assets');
        Schema::dropIfExists('reference_counters');
    }
};
