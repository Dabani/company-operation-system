<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Backup Verification Register: evidence that backups were checked, by whom, with what result.
        Schema::create('backup_verifications', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->date('backup_date');                                              // when the backup ran
            $t->foreignUlid('location_id')->constrained('lookup_values');         // backup_location: primary | offsite | ...
            $t->foreignUlid('result_id')->constrained('lookup_values');           // verified_ok | verified_issues | failed
            $t->foreignUlid('verified_by')->constrained('users');
            $t->timestamp('verified_at')->useCurrent();
            $t->longText('notes')->nullable();                                    // purified HTML
            $t->timestamps();
            $t->index(['organization_id', 'backup_date']);
        });

        Schema::create('training_sessions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->foreignUlid('topic_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');           // planned | held | cancelled
            $t->dateTime('session_date');
            $t->unsignedSmallInteger('duration_minutes')->nullable();
            $t->string('trainer', 150)->nullable();
            $t->string('venue', 150)->nullable();
            $t->boolean('is_mandatory')->default(true);
            $t->longText('notes')->nullable();                                    // purified HTML
            $t->date('retain_until')->nullable();                                 // attendance evidence kept this long
            $t->foreignUlid('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['organization_id', 'session_date']);
        });

        Schema::create('training_attendees', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('session_id')->constrained('training_sessions')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');
            $t->boolean('attended')->nullable();                                  // NULL = not recorded yet
            $t->timestamp('signed_at')->nullable();                               // participant confirmed their attendance
            $t->foreignUlid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unique(['session_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_attendees');
        Schema::dropIfExists('training_sessions');
        Schema::dropIfExists('backup_verifications');
    }
};
