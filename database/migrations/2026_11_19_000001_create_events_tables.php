<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 7e: events with registration, capacity / waitlist and attendance (Guide 6, Appendix A.4). Statuses and kinds are lookups; times are UTC. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->string('title');
            $t->longText('description')->nullable();                      // purified HTML
            $t->foreignUlid('type_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->string('venue')->nullable();
            $t->string('online_url', 500)->nullable();
            $t->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->foreignUlid('organizer_id')->constrained('users');
            $t->unsignedInteger('capacity')->nullable();                  // null = unlimited
            $t->boolean('waitlist_enabled')->default(true);
            $t->boolean('allow_guests')->default(false);                  // external people may register through the public link
            $t->timestamp('registration_opens_at')->nullable();
            $t->timestamp('registration_closes_at')->nullable();
            $t->string('public_token', 64)->unique();
            $t->decimal('budget_amount', 15, 2)->nullable();
            $t->char('currency_code', 3)->nullable();
            $t->string('cancel_reason', 1000)->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'status_id', 'starts_at']);
        });

        Schema::create('event_registrations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('event_id')->constrained('events')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->string('guest_name', 150)->nullable();
            $t->string('guest_email', 190)->nullable();
            $t->string('guest_phone', 40)->nullable();
            $t->string('guest_organization', 150)->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');   // registered | waitlisted | cancelled
            $t->string('ticket_token', 64)->unique();                     // the personal link / QR content
            $t->timestamp('registered_at');
            $t->timestamp('cancelled_at')->nullable();
            $t->string('note', 500)->nullable();
            $t->foreignUlid('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['event_id', 'user_id']);
            $t->index(['event_id', 'guest_email']);
            $t->index(['event_id', 'status_id', 'registered_at']);
        });

        Schema::create('event_attendance', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('event_id')->constrained('events')->cascadeOnDelete();
            $t->foreignUlid('registration_id')->constrained('event_registrations')->cascadeOnDelete();
            $t->foreignUlid('method_id')->constrained('lookup_values');   // ticket | manual
            $t->timestamp('checked_in_at');
            $t->foreignUlid('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 500)->nullable();
            $t->timestamps();
            $t->unique('registration_id');
        });

        Schema::create('event_status_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('event_id')->constrained('events')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note', 1000)->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_status_history');
        Schema::dropIfExists('event_attendance');
        Schema::dropIfExists('event_registrations');
        Schema::dropIfExists('events');
    }
};
