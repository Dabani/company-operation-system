<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organization_memberships', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('department_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->string('job_title')->nullable();
            $t->boolean('is_default')->default(false);
            $t->timestamps();
            $t->unique(['organization_id', 'user_id']);
        });

        // level = authority rank. A user may only manage roles/users strictly below their own highest level
        // (unless they hold the manage permission AND the top level).
        Schema::create('roles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->string('name');
            $t->text('description')->nullable();
            $t->unsignedSmallInteger('level')->default(10);
            $t->string('color', 20)->nullable();
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 100)->unique();         // e.g. user.view
            $t->foreignUlid('group_id')->constrained('lookup_values');   // permission_group lookup
            $t->string('name');
            $t->string('description')->nullable();
            $t->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        Schema::create('membership_roles', function (Blueprint $t) {
            $t->foreignUlid('membership_id')->constrained('organization_memberships')->cascadeOnDelete();
            $t->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->primary(['membership_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('organization_memberships');
    }
};
