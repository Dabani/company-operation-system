<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 0 - shared foundations: rules engine, approval engine, department scope, employee master, clients.
 * No ENUM and no JSON columns: every list is a lookup_values row, every rule is relational.
 * Monetary values are DECIMAL(15,2). Timestamps are UTC.
 */
return new class extends Migration {
  public function up(): void
  {
    // ---- employee master -------------------------------------------------------------------------------------------
    Schema::create('hr_employees', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->string('employee_number', 30);
      $t->string('first_name', 100);
      $t->string('last_name', 100);
      $t->string('email')->nullable();
      $t->string('phone', 40)->nullable();
      $t->foreignUlid('category_id')->constrained('lookup_values');
      $t->foreignUlid('status_id')->constrained('lookup_values');
      $t->ulid('department_id')->nullable();
      $t->ulid('manager_employee_id')->nullable();
      $t->ulid('user_id')->nullable();                       // optional one-to-one login
      $t->string('position_title', 150)->nullable();
      $t->date('hired_on')->nullable();
      $t->date('ended_on')->nullable();
      $t->text('national_id')->nullable();                   // encrypted cast (ciphertext)
      $t->text('bank_account')->nullable();                  // encrypted cast (ciphertext)
      $t->timestamps();
      $t->softDeletes();
      $t->unique(['organization_id', 'employee_number']);
      $t->unique('user_id');
      $t->index(['organization_id', 'department_id']);
      $t->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
      $t->foreign('user_id')->references('id')->on('users')->nullOnDelete();
      $t->foreign('manager_employee_id')->references('id')->on('hr_employees')->nullOnDelete();
    });

    // ---- department scope --------------------------------------------------------------------------------------------
    Schema::create('user_department_assignments', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('user_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('department_id')->nullable()->constrained()->cascadeOnDelete();   // null only for organization scope
      $t->foreignUlid('scope_level_id')->constrained('lookup_values');
      $t->boolean('is_head')->default(false);               // department head: resolves "department_head" approval steps
      $t->timestamps();
      $t->unique(['user_id', 'department_id']);
      $t->index(['organization_id', 'department_id', 'is_head']);
    });

    // ---- rules and policy engine ------------------------------------------------------------------------------------
    Schema::create('rule_sets', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('domain_id')->constrained('lookup_values');
      $t->string('code', 60);
      $t->string('name');
      $t->text('description')->nullable();
      $t->foreignUlid('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
      $t->boolean('is_legal_profile')->default(false);       // activation needs a recorded human sign-off
      $t->boolean('requires_full_coverage')->default(false); // amount bands must leave no gap (blocks activation)
      $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
      $t->timestamps();
      $t->unique(['organization_id', 'code']);
    });

    Schema::create('approval_flows', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->string('code', 60);
      $t->string('name');
      $t->text('description')->nullable();
      $t->foreignUlid('subject_type_id')->nullable()->constrained('lookup_values');   // null = any subject
      $t->unsignedInteger('version')->default(1);
      $t->date('effective_from');
      $t->date('effective_to')->nullable();
      $t->boolean('is_active')->default(true);
      $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
      $t->timestamps();
      $t->unique(['organization_id', 'code', 'version']);
    });

    Schema::create('approval_steps', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('flow_id')->constrained('approval_flows')->cascadeOnDelete();
      $t->unsignedSmallInteger('step_order');
      $t->string('label', 120);
      $t->foreignUlid('step_type_id')->constrained('lookup_values');
      $t->foreignUlid('role_id')->nullable()->constrained('roles')->nullOnDelete();
      $t->foreignUlid('user_id')->nullable()->constrained('users')->nullOnDelete();
      $t->unsignedInteger('escalate_after_hours')->nullable();
      $t->timestamps();
      $t->unique(['flow_id', 'step_order']);
    });

    Schema::create('rule_versions', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('rule_set_id')->constrained('rule_sets')->cascadeOnDelete();
      $t->unsignedInteger('version');
      $t->foreignUlid('status_id')->constrained('lookup_values');
      $t->unsignedInteger('priority')->default(100);
      $t->date('effective_from');
      $t->date('effective_to')->nullable();
      $t->text('rationale')->nullable();
      $t->foreignUlid('verification_status_id')->constrained('lookup_values');
      $t->string('source_reference', 255)->nullable();       // legal / policy source
      $t->foreignUlid('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
      $t->timestamp('signed_off_at')->nullable();
      $t->text('signoff_note')->nullable();
      $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
      $t->ulid('approval_request_id')->nullable();
      $t->timestamp('submitted_at')->nullable();
      $t->timestamp('activated_at')->nullable();
      $t->timestamps();
      $t->unique(['rule_set_id', 'version']);
      $t->index(['organization_id', 'status_id', 'effective_from']);
    });

    Schema::create('rule_conditions', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('rule_version_id')->constrained('rule_versions')->cascadeOnDelete();
      $t->foreignUlid('attribute_id')->constrained('lookup_values');
      $t->foreignUlid('operator_id')->constrained('lookup_values');
      $t->string('value_text', 255)->nullable();
      $t->decimal('value_decimal', 15, 2)->nullable();
      $t->decimal('value_decimal_to', 15, 2)->nullable();    // upper bound for "between"
      $t->timestamps();
    });

    Schema::create('rule_outcomes', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('rule_version_id')->constrained('rule_versions')->cascadeOnDelete();
      $t->string('outcome_key', 80);                          // e.g. approval_flow, min_quotations, max_days
      $t->foreignUlid('approval_flow_id')->nullable()->constrained('approval_flows')->nullOnDelete();
      $t->string('value_text', 255)->nullable();
      $t->decimal('value_decimal', 15, 2)->nullable();
      $t->bigInteger('value_integer')->nullable();
      $t->boolean('value_boolean')->nullable();
      $t->timestamps();
      $t->unique(['rule_version_id', 'outcome_key']);
    });

    // Immutable lifecycle history of every rule version (who moved it where, when).
    Schema::create('rule_activations', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('rule_version_id')->constrained('rule_versions')->cascadeOnDelete();
      $t->foreignUlid('status_id')->constrained('lookup_values');
      $t->foreignUlid('actor_id')->nullable()->constrained('users')->nullOnDelete();
      $t->text('note')->nullable();
      $t->timestamp('created_at')->useCurrent();
    });

    Schema::create('legal_parameters', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('domain_id')->constrained('lookup_values');
      $t->string('code', 80);
      $t->string('name');
      $t->foreignUlid('bound_type_id')->constrained('lookup_values');   // floor | ceiling
      $t->decimal('value_decimal', 15, 4);
      $t->string('unit', 30)->nullable();
      $t->date('valid_from');
      $t->date('valid_to')->nullable();
      $t->string('source_citation', 255)->nullable();
      $t->foreignUlid('verification_status_id')->constrained('lookup_values');
      $t->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
      $t->timestamp('verified_at')->nullable();
      $t->timestamps();
      $t->unique(['organization_id', 'code', 'valid_from']);
    });

    // ---- approval requests and actions --------------------------------------------------------------------------------
    Schema::create('approval_requests', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('flow_id')->constrained('approval_flows');
      $t->foreignUlid('subject_type_id')->constrained('lookup_values');
      $t->ulid('subject_id');                                   // polymorphic by lookup code + id (no morph class names stored)
      $t->foreignUlid('requester_id')->constrained('users');
      $t->ulid('department_id')->nullable();
      $t->string('title');
      $t->decimal('amount', 15, 2)->nullable();
      $t->char('currency_code', 3)->nullable();
      $t->foreignUlid('status_id')->constrained('lookup_values');
      $t->unsignedSmallInteger('current_step')->default(1);
      $t->ulid('rule_version_id')->nullable();                  // rule version used to pick the flow (never rewritten)
      $t->boolean('used_default_flow')->default(false);         // true when no rule applied (safe default)
      $t->timestamp('submitted_at');
      $t->timestamp('step_started_at');
      $t->timestamp('last_escalated_at')->nullable();
      $t->timestamp('completed_at')->nullable();
      $t->text('requester_comment')->nullable();
      $t->timestamps();
      $t->index(['organization_id', 'status_id']);
      $t->index(['subject_type_id', 'subject_id']);
      $t->foreign('department_id')->references('id')->on('departments')->nullOnDelete();
      $t->foreign('rule_version_id')->references('id')->on('rule_versions')->nullOnDelete();
    });
    Schema::table('rule_versions', fn(Blueprint $t) => $t->foreign('approval_request_id')->references('id')->on('approval_requests')->nullOnDelete());

    Schema::create('approval_actions', function (Blueprint $t) {   // immutable decisions
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('request_id')->constrained('approval_requests')->cascadeOnDelete();
      $t->unsignedSmallInteger('step_order');
      $t->foreignUlid('actor_id')->constrained('users');
      $t->foreignUlid('on_behalf_of_id')->nullable()->constrained('users');   // delegation context
      $t->foreignUlid('decision_id')->constrained('lookup_values');
      $t->text('comment')->nullable();
      $t->unsignedInteger('rule_version_number')->nullable();
      $t->timestamp('acted_at')->useCurrent();
      $t->index(['request_id', 'step_order']);
    });

    Schema::create('approval_delegations', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('delegator_id')->constrained('users')->cascadeOnDelete();
      $t->foreignUlid('delegate_id')->constrained('users')->cascadeOnDelete();
      $t->date('valid_from');
      $t->date('valid_to');
      $t->boolean('is_active')->default(true);
      $t->timestamps();
      $t->index(['organization_id', 'delegate_id', 'is_active']);
    });

    // ---- clients and the external (portal) identity ---------------------------------------------------------------------
    Schema::create('clients', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->string('code', 30);
      $t->string('name');
      $t->foreignUlid('status_id')->constrained('lookup_values');
      $t->string('email')->nullable();
      $t->string('phone', 40)->nullable();
      $t->text('address')->nullable();
      $t->string('tax_number', 60)->nullable();
      $t->boolean('portal_enabled')->default(false);
      $t->timestamps();
      $t->softDeletes();
      $t->unique(['organization_id', 'code']);
    });

    Schema::create('client_contacts', function (Blueprint $t) {
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('client_id')->constrained()->cascadeOnDelete();
      $t->string('name');
      $t->string('email')->nullable();
      $t->string('phone', 40)->nullable();
      $t->string('job_title', 120)->nullable();
      $t->boolean('is_primary')->default(false);
      $t->timestamps();
      $t->index(['client_id']);
    });

    Schema::create('client_identities', function (Blueprint $t) {   // never shares the internal users table or permission matrix
      $t->ulid('id')->primary();
      $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('client_id')->constrained()->cascadeOnDelete();
      $t->foreignUlid('client_contact_id')->nullable()->constrained('client_contacts')->nullOnDelete();
      $t->string('email');
      $t->string('password');
      $t->boolean('is_active')->default(true);
      $t->timestamp('last_login_at')->nullable();
      $t->rememberToken();
      $t->timestamps();
      $t->unique(['organization_id', 'email']);
    });
  }

  public function down(): void
  {
    Schema::table('rule_versions', fn(Blueprint $t) => $t->dropForeign(['approval_request_id']));
    foreach (
      [
        'client_identities',
        'client_contacts',
        'clients',
        'approval_delegations',
        'approval_actions',
        'approval_requests',
        'legal_parameters',
        'rule_activations',
        'rule_outcomes',
        'rule_conditions',
        'rule_versions',
        'approval_steps',
        'approval_flows',
        'rule_sets',
        'user_department_assignments',
        'hr_employees'
      ] as $table
    ) {
      Schema::dropIfExists($table);
    }
  }
};
