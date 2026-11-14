<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 4c: attendance and working time. Raw clock events are append-only; daily records are derived and frozen once a month is validated. All instants are UTC. */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_shifts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 30);
            $t->string('name', 150);
            $t->string('start_time', 5);                            // local wall-clock HH:MM in the organization timezone
            $t->string('end_time', 5);                              // at or before the start = the shift crosses midnight
            $t->unsignedSmallInteger('break_minutes')->default(0);  // unpaid
            $t->boolean('auto_deduct_break')->default(false);       // deduct the break when only one in/out pair was punched
            $t->unsignedSmallInteger('grace_minutes')->default(0);  // lateness is counted only beyond this
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('hr_schedules', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 150);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('hr_schedule_days', function (Blueprint $t) {
            $t->foreignUlid('schedule_id')->constrained('hr_schedules')->cascadeOnDelete();
            $t->unsignedTinyInteger('weekday');                     // ISO: 1 = Monday ... 7 = Sunday; a weekday without a row is a day off
            $t->foreignUlid('shift_id')->constrained('hr_shifts');
            $t->primary(['schedule_id', 'weekday']);
        });

        Schema::create('hr_schedule_assignments', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->foreignUlid('schedule_id')->constrained('hr_schedules');
            $t->date('effective_from');                             // dated, never rewritten: the latest row on or before a date applies
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'effective_from']);
        });

        Schema::create('hr_shift_overrides', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->date('work_date');
            $t->ulid('shift_id')->nullable();                       // null = a day off on that date
            $t->string('reason', 255)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date']);
            $t->foreign('shift_id')->references('id')->on('hr_shifts')->cascadeOnDelete();
        });

        Schema::create('hr_public_holidays', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->date('holiday_date');
            $t->string('name', 150);
            $t->timestamps();
            $t->unique(['organization_id', 'holiday_date']);
        });

        Schema::create('hr_attendance_networks', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('label', 100);
            $t->string('cidr', 64);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('hr_attendance_imports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('filename', 255);
            $t->unsignedInteger('rows_total')->default(0);
            $t->unsignedInteger('rows_imported')->default(0);
            $t->unsignedInteger('rows_skipped')->default(0);       // duplicates and double taps
            $t->unsignedInteger('rows_rejected')->default(0);
            $t->text('errors')->nullable();                         // the first rejected lines with the reason
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
        });

        Schema::create('hr_attendance_events', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->string('direction', 3);                             // in | out
            $t->timestamp('occurred_at');                           // UTC; the server clock for web punches
            $t->foreignUlid('source_id')->constrained('lookup_values');
            $t->string('ip_address', 45)->nullable();               // stored only while a network restriction is active
            $t->ulid('import_id')->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at');
            $t->index(['employee_id', 'occurred_at']);
            $t->foreign('import_id')->references('id')->on('hr_attendance_imports')->nullOnDelete();
        });

        Schema::create('hr_attendance_corrections', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->date('work_date');
            $t->timestamp('requested_in_at');
            $t->timestamp('requested_out_at');
            $t->text('reason');
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->ulid('approval_request_id')->nullable();
            $t->foreignUlid('requested_by')->constrained('users');
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'work_date']);
        });

        Schema::create('hr_attendance_days', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->date('work_date');
            $t->ulid('shift_id')->nullable();
            $t->unsignedInteger('expected_minutes')->default(0);
            $t->timestamp('first_in_at')->nullable();
            $t->timestamp('last_out_at')->nullable();
            $t->unsignedInteger('worked_minutes')->default(0);
            $t->unsignedInteger('late_minutes')->default(0);
            $t->unsignedInteger('overtime_minutes')->default(0);
            $t->foreignUlid('status_id')->constrained('lookup_values');
            $t->boolean('is_open')->default(false);
            $t->ulid('correction_id')->nullable();                  // the approved correction this row is based on
            $t->timestamp('computed_at');
            $t->unique(['employee_id', 'work_date']);
            $t->index(['organization_id', 'work_date']);
            $t->foreign('shift_id')->references('id')->on('hr_shifts')->nullOnDelete();
            $t->foreign('correction_id')->references('id')->on('hr_attendance_corrections')->nullOnDelete();
        });

        Schema::create('hr_attendance_periods', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->date('month');                                      // first day of the month
            $t->timestamp('validated_at');
            $t->foreignUlid('validated_by')->constrained('users');
            $t->timestamp('reopened_at')->nullable();               // set while the month is open again (every change is also in the audit log)
            $t->foreignUlid('reopened_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('reopen_reason')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'month']);
        });
    }

    public function down(): void
    {
        foreach (['hr_attendance_periods', 'hr_attendance_days', 'hr_attendance_corrections', 'hr_attendance_events', 'hr_attendance_imports', 'hr_attendance_networks',
            'hr_public_holidays', 'hr_shift_overrides', 'hr_schedule_assignments', 'hr_schedule_days', 'hr_schedules', 'hr_shifts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
