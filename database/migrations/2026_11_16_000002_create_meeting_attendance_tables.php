<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 7b: meeting attendance (Guide 6.5). Statuses and check-in methods are lookups; every change is kept in an immutable history. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_attendances', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('invitation_id')->nullable()->constrained('meeting_invitations')->nullOnDelete();   // null for a walk-in
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('guest_name', 150)->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');         // expected | present | late | online | absent | excused
            $t->timestamp('arrived_at')->nullable();
            $t->foreignUlid('method_id')->nullable()->constrained('lookup_values'); // secretary | self | walk_in
            $t->text('note')->nullable();
            $t->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('acknowledged_at')->nullable();                      // the person confirmed their own line of the sheet
            $t->timestamps();
            $t->unique(['meeting_id', 'invitation_id']);
            $t->unique(['meeting_id', 'user_id']);
            $t->index(['meeting_id', 'status_id']);
        });

        Schema::create('meeting_attendance_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('attendance_id')->constrained('meeting_attendances')->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->boolean('is_correction')->default(false);                       // changed after the meeting was held
            $t->text('reason')->nullable();
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
            $t->index(['meeting_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendance_history');
        Schema::dropIfExists('meeting_attendances');
    }
};
