<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Minimum role level needed to see files of a classification (owner and linked-case participants excepted).
        Schema::create('file_classification_rules', function (Blueprint $t) {
            $t->foreignUlid('classification_id')->primary()->constrained('lookup_values')->cascadeOnDelete();
            $t->unsignedSmallInteger('min_level')->default(0);
        });

        // Logical file. Content lives in immutable versions.
        Schema::create('file_assets', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->text('description')->nullable();                       // purified HTML
            $t->foreignUlid('owner_id')->constrained('users');
            $t->foreignUlid('classification_id')->constrained('lookup_values');
            $t->foreignUlid('status_id')->constrained('lookup_values'); // active | archived
            $t->ulid('current_version_id')->nullable();
            $t->string('extension', 10)->nullable();
            $t->string('mime_type', 120)->nullable();
            $t->unsignedBigInteger('size_bytes')->default(0);
            $t->unsignedSmallInteger('version_count')->default(1);
            $t->boolean('legal_hold')->default(false);                 // blocks deletion
            $t->timestamp('archived_at')->nullable();
            $t->timestamps();
            $t->softDeletes();                                         // deletion = hidden; blobs purged after retention
            $t->index(['organization_id', 'status_id']);
            $t->index(['organization_id', 'owner_id']);
        });

        Schema::create('file_versions', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('file_id')->constrained('file_assets')->cascadeOnDelete();
            $t->unsignedSmallInteger('version_number');
            $t->string('original_name');
            $t->string('storage_path');                                // random name, never the client's file name
            $t->string('mime_type', 120)->nullable();
            $t->string('extension', 10)->nullable();
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->foreignUlid('uploaded_by')->constrained('users');
            $t->timestamp('purged_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['file_id', 'version_number']);
        });

        Schema::create('file_links', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('file_id')->constrained('file_assets')->cascadeOnDelete();
            $t->foreignUlid('link_type_id')->constrained('lookup_values');   // general | case | user_avatar | org_logo
            $t->string('link_id', 40);
            $t->index(['link_type_id', 'link_id']);
        });

        Schema::create('file_comments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('file_id')->constrained('file_assets')->cascadeOnDelete();
            $t->foreignUlid('user_id')->constrained('users');
            $t->foreignUlid('kind_id')->constrained('lookup_values');        // comment | deletion_request | decision
            $t->longText('body');                                            // purified HTML
            $t->timestamp('created_at')->useCurrent();
            $t->index(['file_id', 'created_at']);
        });

        // Formal deletion request: raised as a file comment, decided by the file owner or an administrator.
        Schema::create('file_deletion_requests', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('file_id')->constrained('file_assets')->cascadeOnDelete();
            $t->foreignUlid('requested_by')->constrained('users');
            $t->foreignUlid('comment_id')->constrained('file_comments');
            $t->foreignUlid('status_id')->constrained('lookup_values');      // pending | approved | rejected | cancelled
            $t->foreignUlid('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('decided_at')->nullable();
            $t->string('decision_note', 500)->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['organization_id', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_deletion_requests');
        Schema::dropIfExists('file_comments');
        Schema::dropIfExists('file_links');
        Schema::dropIfExists('file_versions');
        Schema::dropIfExists('file_assets');
        Schema::dropIfExists('file_classification_rules');
    }
};
