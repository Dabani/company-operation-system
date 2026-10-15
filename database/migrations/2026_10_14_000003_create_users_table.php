<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces Laravel's default 0001_01_01_000000_create_users_table.php (delete that file).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('users', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('phone', 40)->nullable();
            $t->timestamp('email_verified_at')->nullable();
            $t->string('password');
            $t->string('avatar_path')->nullable();
            $t->foreignUlid('language_id')->nullable()->constrained('languages')->nullOnDelete();
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->boolean('must_change_password')->default(false);
            $t->timestamp('last_login_at')->nullable();
            $t->rememberToken();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('password_reset_tokens', function (Blueprint $t) {
            $t->string('email')->primary();
            $t->string('token');
            $t->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->ulid('user_id')->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
