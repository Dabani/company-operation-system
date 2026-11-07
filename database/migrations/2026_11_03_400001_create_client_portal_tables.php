<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 3 - client portal: invitations, MFA, visibility flags, client comments and shared deliverables. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('client_identities', function (Blueprint $t) {
            $t->string('password')->nullable()->change();                       // null until the invitation is accepted
            $t->text('two_factor_secret')->nullable();                          // encrypted cast (ciphertext)
            $t->timestamp('two_factor_confirmed_at')->nullable();
            $t->unsignedBigInteger('two_factor_last_step')->nullable();         // blocks re-use of a one-time code
            $t->timestamp('invited_at')->nullable();
        });

        Schema::create('client_invitations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('client_identity_id')->constrained('client_identities')->cascadeOnDelete();
            $t->string('token_hash', 64);                                       // SHA-256; the raw token exists only in the e-mailed link
            $t->string('kind', 10)->default('invite');                          // invite | reset
            $t->foreignUlid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $t->unsignedSmallInteger('send_count')->default(1);
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamp('expires_at');
            $t->timestamp('accepted_at')->nullable();
            $t->timestamps();
            $t->index(['client_identity_id', 'token_hash']);
        });

        Schema::create('client_recovery_codes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('client_identity_id')->constrained('client_identities')->cascadeOnDelete();
            $t->string('code_hash', 64);
            $t->timestamp('used_at')->nullable();
            $t->index('client_identity_id');
        });

        // Portal switches are OFF until enabled per client or per project (Guide: progress-only by default).
        Schema::table('clients', function (Blueprint $t) {
            $t->boolean('portal_invoices')->default(false)->after('portal_enabled');
            $t->boolean('portal_uploads')->default(false)->after('portal_invoices');
        });
        Schema::table('projects', function (Blueprint $t) {
            $t->boolean('portal_visible')->default(false);                          // the client sees only projects that are explicitly shared
            $t->boolean('portal_invoices')->default(false);
            $t->boolean('portal_uploads')->default(false);
        });

        Schema::create('project_comments', function (Blueprint $t) {            // internal by default; visible to the client only when flagged
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('client_identity_id')->nullable()->constrained('client_identities')->nullOnDelete();
            $t->text('body');
            $t->boolean('is_client_visible')->default(false);
            $t->timestamps();
            $t->index(['project_id', 'created_at']);
        });

        Schema::create('portal_file_shares', function (Blueprint $t) {          // a row = this Controlled File is visible to the project's client
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('project_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('file_id')->constrained('file_assets')->cascadeOnDelete();
            $t->foreignUlid('client_identity_id')->nullable()->constrained('client_identities')->nullOnDelete();   // set when the client uploaded it
            $t->foreignUlid('shared_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['project_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_file_shares');
        Schema::dropIfExists('project_comments');
        Schema::table('projects', fn (Blueprint $t) => $t->dropColumn(['portal_visible', 'portal_invoices', 'portal_uploads']));
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn(['portal_invoices', 'portal_uploads']));
        Schema::dropIfExists('client_recovery_codes');
        Schema::dropIfExists('client_invitations');
        Schema::table('client_identities', fn (Blueprint $t) => $t->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_last_step', 'invited_at']));
    }
};
