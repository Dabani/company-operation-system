<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 10)->unique();          // en, fr, rw, sw
            $t->string('name');
            $t->string('native_name');
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('lookup_types', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 60)->unique();
            $t->string('name');
            $t->boolean('is_system')->default(false);
            $t->timestamps();
        });

        // organization_id NULL = global value shared by every organization.
        Schema::create('lookup_values', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('lookup_type_id')->constrained('lookup_types')->cascadeOnDelete();
            $t->ulid('organization_id')->nullable()->index();
            $t->string('code', 60);
            $t->string('color', 20)->nullable();       // Mantine colour name or hex for state labels
            $t->string('icon', 60)->nullable();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_system')->default(false);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['lookup_type_id', 'organization_id', 'code']);
        });

        Schema::create('lookup_value_translations', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('lookup_value_id')->constrained('lookup_values')->cascadeOnDelete();
            $t->foreignUlid('language_id')->constrained('languages')->cascadeOnDelete();
            $t->string('label');
            $t->string('description')->nullable();
            $t->unique(['lookup_value_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lookup_value_translations');
        Schema::dropIfExists('lookup_values');
        Schema::dropIfExists('lookup_types');
        Schema::dropIfExists('languages');
    }
};
