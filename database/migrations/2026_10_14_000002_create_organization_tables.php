<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('name');
            $t->string('slug')->unique();
            $t->string('legal_name')->nullable();
            $t->string('email')->nullable();
            $t->string('phone', 40)->nullable();
            $t->string('website')->nullable();
            $t->text('address')->nullable();
            $t->string('logo_path')->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->foreignUlid('default_language_id')->constrained('languages');
            $t->foreignUlid('timezone_id')->constrained('lookup_values');
            $t->foreignUlid('currency_id')->constrained('lookup_values');
            $t->foreignUlid('date_format_id')->constrained('lookup_values');
            $t->timestamps();
        });

        // Typed key/value settings (no JSON): the value lives in one typed column chosen by value_type.
        Schema::create('settings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('key', 120);
            $t->foreignUlid('value_type_id')->constrained('lookup_values');
            $t->text('value_text')->nullable();
            $t->bigInteger('value_integer')->nullable();
            $t->decimal('value_decimal', 18, 4)->nullable();
            $t->boolean('value_boolean')->nullable();
            $t->date('value_date')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'key']);
        });

        Schema::create('departments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->ulid('parent_id')->nullable();
            $t->string('code', 30);
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->foreign('parent_id')->references('id')->on('departments')->nullOnDelete();
        });

        Schema::create('locations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->ulid('parent_id')->nullable();
            $t->foreignUlid('location_type_id')->constrained('lookup_values');
            $t->string('code', 30);
            $t->string('name');
            $t->text('description')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->foreign('parent_id')->references('id')->on('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('organizations');
    }
};
