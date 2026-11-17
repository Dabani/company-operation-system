<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 7a: meetings and events, core. Rooms, meetings (and their recurrence), room bookings with conflict detection,
 * agenda, invitations / RSVP, schedule history. No ENUM and no JSON columns: every state is a lookup.
 * Instants are stored in UTC (App\Casts\UtcDateTime); presentation uses the organization timezone.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_rooms', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->foreignUlid('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $t->unsignedSmallInteger('capacity')->nullable();
            $t->text('equipment')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'name']);
        });

        Schema::create('meeting_recurrences', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('frequency_id')->constrained('lookup_values');      // daily | weekly | monthly
            $t->unsignedSmallInteger('interval_count')->default(1);
            $t->date('ends_on')->nullable();
            $t->unsignedSmallInteger('occurrences')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('meetings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('title', 200);
            $t->text('purpose')->nullable();                                    // purified rich text
            $t->foreignUlid('type_id')->constrained('lookup_values');           // board, executive, departmental, project, training, event...
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->foreignUlid('confidentiality_id')->constrained('lookup_values');
            $t->foreignUlid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->foreignUlid('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $t->foreignUlid('organizer_id')->constrained('users');
            $t->foreignUlid('chair_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('secretary_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('facilitator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->foreignUlid('room_id')->nullable()->constrained('meeting_rooms')->nullOnDelete();
            $t->string('venue', 255)->nullable();                               // free-text venue (outside the room catalogue)
            $t->string('online_url', 500)->nullable();
            $t->foreignUlid('recurrence_id')->nullable()->constrained('meeting_recurrences')->nullOnDelete();
            $t->foreignUlid('agenda_status_id')->constrained('lookup_values'); // draft | circulated | approved
            $t->foreignUlid('agenda_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('agenda_approved_at')->nullable();
            $t->timestamp('rsvp_deadline')->nullable();
            $t->unsignedInteger('ics_sequence')->default(0);                    // raised on every change that calendar clients must pick up
            $t->string('checkin_token', 64)->nullable();                        // self check-in link / QR (opened only while the meeting is in progress)
            $t->timestamp('started_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamp('closed_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->text('cancel_reason')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'starts_at']);
            $t->index(['organization_id', 'status_id']);
        });

        Schema::create('meeting_room_bookings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('room_id')->constrained('meeting_rooms');
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->timestamp('starts_at');
            $t->timestamp('ends_at');
            $t->timestamp('released_at')->nullable();                           // a released booking no longer blocks the room
            $t->timestamps();
            $t->index(['room_id', 'starts_at', 'ends_at']);
            $t->index('meeting_id');
        });

        Schema::create('meeting_agenda_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->unsignedSmallInteger('position');
            $t->string('title', 200);
            $t->text('description')->nullable();                                // purified rich text
            $t->foreignUlid('presenter_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('presenter_name', 150)->nullable();                      // external presenter
            $t->unsignedSmallInteger('duration_minutes')->nullable();
            $t->foreignUlid('priority_id')->constrained('lookup_values');
            $t->text('expected_decision')->nullable();
            $t->boolean('is_confidential')->default(false);                     // item-level visibility
            $t->timestamps();
            $t->index(['meeting_id', 'position']);
        });

        Schema::create('meeting_invitations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $t->string('guest_name', 150)->nullable();
            $t->string('guest_email', 190)->nullable();
            $t->foreignUlid('via_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $t->foreignUlid('response_id')->constrained('lookup_values');       // pending | accepted | declined | proposed
            $t->text('response_note')->nullable();
            $t->timestamp('proposed_starts_at')->nullable();
            $t->timestamp('responded_at')->nullable();
            $t->string('token', 64)->unique();                                  // guest RSVP link and calendar download
            $t->timestamp('invited_at')->nullable();
            $t->timestamps();
            $t->unique(['meeting_id', 'user_id']);
            $t->unique(['meeting_id', 'guest_email']);
        });

        Schema::create('meeting_reminder_log', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('invitation_id')->constrained('meeting_invitations')->cascadeOnDelete();
            $t->unsignedInteger('offset_minutes');
            $t->timestamp('sent_at');
            $t->unique(['invitation_id', 'offset_minutes']);                    // each reminder goes once
        });

        Schema::create('meeting_status_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('from_status_id')->nullable()->constrained('lookup_values');
            $t->foreignUlid('to_status_id')->constrained('lookup_values');
            $t->text('note')->nullable();
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
        });

        Schema::create('meeting_schedule_history', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->timestamp('old_starts_at');
            $t->timestamp('old_ends_at');
            $t->foreignUlid('old_room_id')->nullable()->constrained('meeting_rooms')->nullOnDelete();
            $t->string('old_venue', 255)->nullable();
            $t->text('reason')->nullable();
            $t->foreignUlid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['meeting_schedule_history', 'meeting_status_history', 'meeting_reminder_log', 'meeting_invitations', 'meeting_agenda_items', 'meeting_room_bookings', 'meetings', 'meeting_recurrences', 'meeting_rooms'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
