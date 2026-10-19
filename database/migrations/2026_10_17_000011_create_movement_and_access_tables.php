<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Equipment Movement Authorization (equipment leaving the premises).
        Schema::create('equipment_movements', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40)->unique();                    // MOV-2026-000012
            $t->foreignUlid('requested_by')->constrained('users');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->longText('purpose')->nullable();                      // purified HTML
            $t->string('destination');
            $t->date('departure_date');
            $t->date('expected_return_date')->nullable();
            $t->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();   // approver (department head / manager)
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->foreignUlid('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('checked_out_at')->nullable();
            $t->foreignUlid('returned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('returned_at')->nullable();
            $t->string('return_note', 500)->nullable();
            $t->timestamp('overdue_alerted_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status_id']);
        });

        Schema::create('equipment_movement_items', function (Blueprint $t) {
            $t->foreignUlid('movement_id')->constrained('equipment_movements')->cascadeOnDelete();
            $t->foreignUlid('asset_id')->constrained('assets');
            $t->foreignUlid('status_before_id')->nullable()->constrained('lookup_values');     // restored on return
            $t->foreignUlid('condition_out_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('condition_in_id')->nullable()->constrained('lookup_values');
            $t->primary(['movement_id', 'asset_id']);
        });

        // Access Control Register: who has which access to which system, who authorised it, when it was revoked.
        Schema::create('access_grants', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');
            $t->foreignUlid('resource_id')->constrained('lookup_values');         // system_resource
            $t->foreignUlid('access_level_id')->constrained('lookup_values');     // access_level
            $t->longText('justification')->nullable();                            // purified HTML
            $t->foreignUlid('granted_by')->constrained('users');
            $t->timestamp('granted_at')->useCurrent();
            $t->date('expires_on')->nullable();
            $t->timestamp('last_reviewed_at')->nullable();
            $t->foreignUlid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('revoked_at')->nullable();
            $t->foreignUlid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('revocation_reason', 500)->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'user_id']);
            $t->index(['organization_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_grants');
        Schema::dropIfExists('equipment_movement_items');
        Schema::dropIfExists('equipment_movements');
    }
};
