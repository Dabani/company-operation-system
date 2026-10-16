<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // In-app (live) notifications shown in the bell.
        Schema::create('app_notifications', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->index();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->string('title');
            $t->string('body', 500)->nullable();
            $t->ulid('case_id')->nullable();
            $t->string('link')->nullable();                        // SPA route, e.g. /cases/{id}
            $t->timestamp('read_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['user_id', 'read_at']);
        });

        // One row per email/SMS attempt: proof of delivery status for audits and support.
        Schema::create('notification_deliveries', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->nullable()->index();
            $t->foreignUlid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->ulid('case_id')->nullable();
            $t->foreignUlid('channel_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->string('recipient');
            $t->string('subject');
            $t->text('body');
            $t->string('action_url')->nullable();
            $t->string('locale', 10)->default('en');
            $t->string('provider_reference')->nullable();
            $t->string('error', 500)->nullable();
            $t->unsignedTinyInteger('attempts')->default(0);
            $t->timestamp('sent_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['status_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
        Schema::dropIfExists('app_notifications');
    }
};
