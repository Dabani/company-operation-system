<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Response targets per severity. Editable data, not code.
        Schema::create('incident_severity_rules', function (Blueprint $t) {
            $t->foreignUlid('severity_id')->primary()->constrained('lookup_values')->cascadeOnDelete();
            $t->unsignedSmallInteger('containment_hours');
            $t->unsignedSmallInteger('rca_business_days');
        });

        // An incident IS a case (type "incident"): timeline, comments, attachments and notifications come from the case.
        Schema::create('incident_details', function (Blueprint $t) {
            $t->foreignUlid('case_id')->primary()->constrained('cases')->cascadeOnDelete();
            $t->foreignUlid('severity_id')->constrained('lookup_values');
            $t->timestamp('detected_at')->nullable();
            $t->longText('impact')->nullable();
            $t->longText('immediate_action')->nullable();
            $t->timestamp('containment_due_at')->nullable();
            $t->timestamp('contained_at')->nullable();
            $t->timestamp('rca_due_at')->nullable();
            $t->timestamp('rca_completed_at')->nullable();
            $t->longText('root_cause')->nullable();
            $t->longText('preventive_action_plan')->nullable();
            $t->boolean('follow_up_required')->default(false);
            $t->boolean('personal_data_breach')->default(false);
            $t->timestamp('regulator_notified_at')->nullable();
            $t->timestamp('data_subjects_notified_at')->nullable();
            $t->foreignUlid('affected_asset_id')->nullable()->constrained('assets')->nullOnDelete();
            $t->timestamp('containment_alerted_at')->nullable();
            $t->timestamp('rca_alerted_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('incident_details');
        Schema::dropIfExists('incident_severity_rules');
    }
};
