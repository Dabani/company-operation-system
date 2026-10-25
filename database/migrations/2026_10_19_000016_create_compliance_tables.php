<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Recurring duties (weekly backup check, quarterly inventory, ...). Data, so each organization tunes its own calendar.
        Schema::create('compliance_obligations', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 60);
            $t->string('title');
            $t->longText('description')->nullable();                              // purified HTML
            $t->foreignUlid('frequency_id')->constrained('lookup_values');        // weekly | monthly | quarterly | semi_annual | annual
            $t->foreignUlid('responsible_role_id')->nullable()->constrained('roles')->nullOnDelete();
            $t->foreignUlid('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignUlid('legal_reference_id')->nullable()->constrained('legal_references')->nullOnDelete();
            $t->string('link_route', 120)->nullable();                            // where the work is done, e.g. /archive/inventory
            $t->date('next_due_on');
            $t->date('last_completed_on')->nullable();
            $t->date('alerted_for')->nullable();                                  // due date already alerted (one alert per cycle)
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
            $t->index(['organization_id', 'next_due_on']);
        });

        Schema::create('obligation_completions', function (Blueprint $t) {
            $t->id();
            $t->foreignUlid('obligation_id')->constrained('compliance_obligations')->cascadeOnDelete();
            $t->date('due_on');
            $t->date('completed_on');
            $t->foreignUlid('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('notes', 1000)->nullable();
            $t->boolean('automatic')->default(false);                             // completed by the system when the work was recorded elsewhere
            $t->timestamp('created_at')->useCurrent();
            $t->index(['obligation_id', 'completed_on']);
        });

        Schema::create('compliance_audits', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('reference', 40)->unique();                                // AUD-2026-000001
            $t->string('title');
            $t->string('period_label', 60)->nullable();                           // e.g. 2026 H1
            $t->longText('scope')->nullable();
            $t->foreignUlid('status_id')->constrained('lookup_values');           // planned | in_progress | completed | reported
            $t->foreignUlid('lead_auditor_id')->constrained('users');
            $t->date('started_on')->nullable();
            $t->date('completed_on')->nullable();
            $t->longText('summary')->nullable();                                  // purified HTML
            $t->timestamp('reported_at')->nullable();
            $t->foreignUlid('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });

        Schema::create('audit_findings', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('audit_id')->constrained('compliance_audits')->cascadeOnDelete();
            $t->string('title');
            $t->longText('description')->nullable();
            $t->foreignUlid('severity_id')->constrained('lookup_values');         // incident_severity scale
            $t->foreignUlid('status_id')->constrained('lookup_values');           // open | in_progress | resolved | accepted_risk
            $t->foreignUlid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->date('due_on')->nullable();
            $t->date('closed_on')->nullable();
            $t->string('resolution_note', 1000)->nullable();
            $t->foreignUlid('created_by')->constrained('users');
            $t->timestamps();
            $t->index(['audit_id', 'status_id']);
        });

        // What was formally sent to senior management, when, by whom (training reports, audit reports).
        Schema::create('management_reports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('kind_id')->constrained('lookup_values');             // training | audit | other
            $t->string('title');
            $t->string('period_label', 60)->nullable();
            $t->longText('body');                                                 // purified HTML summary
            $t->foreignUlid('submitted_by')->constrained('users');
            $t->timestamp('submitted_at')->useCurrent();
            $t->unsignedSmallInteger('recipients_count')->default(0);
            $t->ulid('source_id')->nullable();                                    // e.g. the audit id
            $t->index(['organization_id', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('management_reports');
        Schema::dropIfExists('audit_findings');
        Schema::dropIfExists('compliance_audits');
        Schema::dropIfExists('obligation_completions');
        Schema::dropIfExists('compliance_obligations');
    }
};
