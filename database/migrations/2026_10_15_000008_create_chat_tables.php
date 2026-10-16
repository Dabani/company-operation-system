<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chat_conversations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');                    // requester
            $t->foreignUlid('status_id')->constrained('lookup_values');         // bot | waiting_agent | with_agent | closed
            $t->foreignUlid('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'status_id', 'last_message_at']);
        });

        Schema::create('chat_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('conversation_id')->constrained('chat_conversations')->cascadeOnDelete();
            $t->foreignUlid('sender_type_id')->constrained('lookup_values');    // user | agent | bot
            $t->foreignUlid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $t->text('body');                                                   // plain text
            $t->timestamp('created_at')->useCurrent();
            $t->index(['conversation_id', 'id']);
        });

        // Support bot knowledge: data, not code. Admins can extend it without a deployment.
        Schema::create('bot_intents', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->nullable()->index();                   // NULL = shared
            $t->string('code', 60)->unique();
            $t->foreignUlid('action_id')->constrained('lookup_values');         // none | escalate | suggest_case
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('bot_intent_keywords', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('intent_id')->constrained('bot_intents')->cascadeOnDelete();
            $t->foreignUlid('language_id')->constrained('languages');
            $t->string('keyword', 80);
            $t->index(['language_id', 'keyword']);
        });

        Schema::create('bot_intent_responses', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('intent_id')->constrained('bot_intents')->cascadeOnDelete();
            $t->foreignUlid('language_id')->constrained('languages');
            $t->text('response');
            $t->unique(['intent_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_intent_responses');
        Schema::dropIfExists('bot_intent_keywords');
        Schema::dropIfExists('bot_intents');
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_conversations');
    }
};
