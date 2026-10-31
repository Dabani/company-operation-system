<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One row per invitation e-mail. Only a hash of the token is stored; the raw token exists only in the e-mailed link.
        Schema::create('user_invitations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignUlid('organization_id')->nullable()->constrained('organizations')->nullOnDelete();
            $t->char('token_hash', 64)->unique();
            $t->foreignUlid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedSmallInteger('send_count')->default(1);
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_invitations');
    }
};
