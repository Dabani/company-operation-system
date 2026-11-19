<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 7g: chat and polls of online meetings. Chat is kept with the meeting (moderators can remove a message, the removal is recorded).
 * Polls are run by the hosts; who voted is stored apart from what was voted, so an anonymous poll cannot be traced back to a person from the vote rows.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('meeting_chat_messages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('invitation_id')->nullable()->constrained('meeting_invitations')->nullOnDelete();
            $t->string('body', 1000);
            $t->timestamp('removed_at')->nullable();
            $t->foreignUlid('removed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['meeting_id', 'id']);
        });

        Schema::create('meeting_polls', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('meeting_id')->constrained('meetings')->cascadeOnDelete();
            $t->foreignUlid('agenda_item_id')->nullable()->constrained('meeting_agenda_items')->nullOnDelete();
            $t->string('question', 300);
            $t->foreignUlid('status_id')->constrained('lookup_values');            // open | closed
            $t->boolean('multiple')->default(false);
            $t->unsignedTinyInteger('max_choices')->default(0);                     // 0 = no limit (multiple choice only)
            $t->boolean('anonymous')->default(false);
            $t->boolean('guests_vote')->default(false);
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('closed_at')->nullable();
            $t->timestamps();
            $t->index(['meeting_id', 'created_at']);
        });

        Schema::create('meeting_poll_options', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('poll_id')->constrained('meeting_polls')->cascadeOnDelete();
            $t->string('label', 150);
            $t->unsignedTinyInteger('position');
        });

        Schema::create('meeting_poll_voters', function (Blueprint $t) {            // who has voted (once per poll), never what
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('poll_id')->constrained('meeting_polls')->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('invitation_id')->nullable()->constrained('meeting_invitations')->nullOnDelete();
            $t->timestamp('voted_at')->useCurrent();
            $t->unique(['poll_id', 'user_id']);
            $t->unique(['poll_id', 'invitation_id']);
        });

        Schema::create('meeting_poll_votes', function (Blueprint $t) {             // what was voted; voter_id is null in anonymous polls
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('poll_id')->constrained('meeting_polls')->cascadeOnDelete();
            $t->foreignUlid('option_id')->constrained('meeting_poll_options')->cascadeOnDelete();
            $t->foreignUlid('voter_id')->nullable()->constrained('meeting_poll_voters')->nullOnDelete();
            $t->index(['poll_id', 'option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_poll_votes');
        Schema::dropIfExists('meeting_poll_voters');
        Schema::dropIfExists('meeting_poll_options');
        Schema::dropIfExists('meeting_polls');
        Schema::dropIfExists('meeting_chat_messages');
    }
};
