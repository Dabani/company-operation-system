<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Sidebar accordion (max 3 levels, enforced in MenuController/seed). organization_id NULL = global.
        Schema::create('menu_items', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->nullable()->index();
            $t->ulid('parent_id')->nullable();
            $t->string('code', 80)->unique();
            $t->string('label_key')->nullable();       // i18n key; falls back to label
            $t->string('label')->nullable();
            $t->string('icon', 60)->nullable();        // Tabler icon name
            $t->string('route')->nullable();
            $t->foreignUlid('permission_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->foreign('parent_id')->references('id')->on('menu_items')->cascadeOnDelete();
        });

        Schema::create('dashboard_widgets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->string('code', 80)->unique();         // also the data-provider key
            $t->string('name_key');
            $t->foreignUlid('widget_type_id')->constrained('lookup_values');
            $t->foreignUlid('permission_id')->nullable()->constrained()->nullOnDelete();
            $t->unsignedTinyInteger('default_width')->default(4);   // 1..12 grid columns
            $t->string('icon', 60)->nullable();
            $t->string('color', 20)->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('role_dashboard_widgets', function (Blueprint $t) {
            $t->foreignUlid('role_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('widget_id')->constrained('dashboard_widgets')->cascadeOnDelete();
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->unsignedTinyInteger('width')->nullable();
            $t->primary(['role_id', 'widget_id']);
        });

        // Append-only. Before/after values are stored as rows (no JSON).
        Schema::create('audit_logs', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->ulid('organization_id')->nullable()->index();
            $t->ulid('user_id')->nullable()->index();
            $t->string('action', 100)->index();
            $t->string('entity_type', 100)->nullable();
            $t->string('entity_id', 40)->nullable();
            $t->foreignUlid('outcome_id')->constrained('lookup_values');
            $t->string('request_id', 60)->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->string('reason')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['entity_type', 'entity_id']);
        });

        Schema::create('audit_log_changes', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('audit_log_id')->constrained('audit_logs')->cascadeOnDelete();
            $t->string('field', 100);
            $t->text('old_value')->nullable();
            $t->text('new_value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log_changes');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('role_dashboard_widgets');
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('menu_items');
    }
};
