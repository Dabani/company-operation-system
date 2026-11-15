<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** COPS Phase 4c (part 2): configurable import profiles for any device or spreadsheet, device codes, work sites (geolocation). */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_attendance_import_profiles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->string('description', 255)->nullable();
            $t->boolean('is_system')->default(false);              // shipped starting points: read-only, duplicate them to adapt
            $t->string('layout', 10)->default('punches');           // punches = one row per punch; daily = one row per person and day with several time columns
            $t->string('sheet', 100)->nullable();                   // Excel sheet name; empty = the first sheet
            $t->unsignedTinyInteger('header_row')->default(1);     // 1-based; 0 = no header row
            $t->string('identify_by', 20)->default('either');       // employee_number | device_code | either
            $t->string('employee_column', 100)->nullable();         // a header text, an Excel letter (B) or a position (#2); empty = recognise the usual names
            $t->string('timestamp_column', 100)->nullable();
            $t->string('date_column', 100)->nullable();
            $t->string('time_column', 100)->nullable();
            $t->string('direction_column', 100)->nullable();
            $t->string('in_values', 500)->nullable();               // comma lists of what this device writes for in / out / rows to ignore
            $t->string('out_values', 500)->nullable();
            $t->string('ignore_values', 500)->nullable();
            $t->string('date_formats', 500)->nullable();            // comma list of PHP date formats, e.g. d/m/Y H:i
            $t->string('device_timezone', 64)->nullable();          // empty = the organization's timezone
            $t->timestamps();
            $t->unique(['organization_id', 'name']);
        });

        Schema::create('hr_attendance_import_slots', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('profile_id')->constrained('hr_attendance_import_profiles')->cascadeOnDelete();
            $t->unsignedTinyInteger('position')->default(0);
            $t->string('column_name', 100);                         // daily layout: the column that holds one time of day
            $t->string('direction', 3);                             // in | out
        });

        Schema::create('hr_attendance_device_codes', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $t->string('code', 60);                                 // the user / badge number the device uses for this person
            $t->string('label', 100)->nullable();                   // e.g. the device or site
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('hr_attendance_sites', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('name', 120);
            $t->decimal('latitude', 9, 6);
            $t->decimal('longitude', 9, 6);
            $t->unsignedSmallInteger('radius_meters')->default(100);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::table('hr_attendance_events', function (Blueprint $t) {      // no foreign key on the altered table: SQLite cannot add them
            $t->decimal('latitude', 9, 6)->nullable();                        // stored only while geolocation is switched on
            $t->decimal('longitude', 9, 6)->nullable();
            $t->unsignedSmallInteger('accuracy_m')->nullable();
            $t->ulid('site_id')->nullable();
        });

        Schema::table('hr_attendance_imports', function (Blueprint $t) {
            $t->ulid('profile_id')->nullable();
            $t->string('file_type', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_attendance_imports', fn (Blueprint $t) => $t->dropColumn(['profile_id', 'file_type']));
        Schema::table('hr_attendance_events', fn (Blueprint $t) => $t->dropColumn(['latitude', 'longitude', 'accuracy_m', 'site_id']));
        foreach (['hr_attendance_sites', 'hr_attendance_device_codes', 'hr_attendance_import_slots', 'hr_attendance_import_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
