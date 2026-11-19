<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 7f: online meetings. A meeting can get a video room (Jitsi), join sessions are logged for online attendance, and participants request
 * interventions (the right to speak) in an ordered queue that the chair manages. No ENUM, no JSON: kinds and statuses are lookups.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $t) {
            $t->string('online_room', 60)->nullable()->after('online_url');       // set = the meeting has a COPS video room (random, never shown outside the call link)
            $t->boolean('online_allow_guests')->default(false)->after('online_room');
            $t->unique('online_room');
        });

        Schema::create('meeting_online_sessions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('invitation_id')->nullable()->constrained('meeting_invitations')->nullOnDelete();   // external guest
            $t->boolean('moderator')->default(false);
            $t->timestamp('joined_at');
            $t->timestamp('left_at')->nullable();
            $t->index(['meeting_id', 'joined_at']);
        });

        Schema::create('meeting_interventions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('invitation_id')->nullable()->constrained('meeting_invitations')->nullOnDelete();
            $t->foreignUlid('agenda_item_id')->nullable()->constrained('meeting_agenda_items')->nullOnDelete();
            $t->foreignUlid('kind_id')->constrained('lookup_values');               // speak | question | point_of_order | reply
            $t->foreignUlid('status_id')->constrained('lookup_values');             // requested | speaking | done | dismissed | withdrawn
            $t->string('topic', 200)->nullable();
            $t->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('decision_note', 300)->nullable();
            $t->timestamp('requested_at');
            $t->timestamp('granted_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['meeting_id', 'status_id', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_interventions');
        Schema::dropIfExists('meeting_online_sessions');
        Schema::table('meetings', function (Blueprint $t) {
            $t->dropUnique(['online_room']);
            $t->dropColumn(['online_room', 'online_allow_guests']);
        });
    }
};
