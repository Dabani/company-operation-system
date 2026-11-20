<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPS Phase 6c: payroll exports (Guide 5.6). The bank, PAYE and RSSB layouts and the journal accounts are the accountant's: they are configured here, NEVER built in.
 * A layout records who agreed it and is verified by a second person before any file can be generated from it. A generated file is stored immutable (encrypted, checksummed).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('pay_export_profiles', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('code', 40);
            $t->string('name', 150);
            $t->foreignUlid('kind_id')->constrained('lookup_values');              // bank_transfer | declaration | journal
            $t->foreignUlid('layout_id')->constrained('lookup_values');            // delimited | fixed_width
            $t->string('delimiter', 4)->nullable();
            $t->string('quote_char', 1)->nullable();
            $t->string('line_ending', 4)->default('LF');                            // LF | CRLF
            $t->string('encoding', 20)->default('UTF-8');                           // UTF-8 | ISO-8859-1
            $t->string('decimal_separator', 1)->default('.');
            $t->string('date_format', 20)->default('Y-m-d');
            $t->boolean('include_header')->default(false);
            $t->text('header_template')->nullable();                                // {run_code} {period_from} {period_to} {paid_on} {currency} {count} {total} {generated_on}
            $t->text('trailer_template')->nullable();
            $t->string('file_extension', 10)->default('csv');
            $t->string('filename_template', 150)->default('payroll-{run_code}');
            $t->string('agreed_with', 255);                                         // who agreed this layout (the bank, RRA, RSSB, the accountant) and the reference
            $t->boolean('is_active')->default(true);
            $t->foreignUlid('verification_status_id')->constrained('lookup_values');
            $t->foreignUlid('prepared_by')->constrained('users');
            $t->foreignUlid('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('verified_at')->nullable();
            $t->timestamps();
            $t->unique(['organization_id', 'code']);
        });

        Schema::create('pay_export_columns', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('profile_id')->constrained('pay_export_profiles')->cascadeOnDelete();
            $t->unsignedSmallInteger('position');
            $t->string('header', 80);
            $t->string('source', 60);                                                // see ExportFields
            $t->foreignUlid('scheme_id')->nullable()->constrained('pay_schemes')->restrictOnDelete();
            $t->foreignUlid('component_id')->nullable()->constrained('pay_components')->restrictOnDelete();
            $t->string('constant', 100)->nullable();
            $t->string('format', 20)->default('text');                               // text | amount | integer_minor | integer | date
            $t->unsignedTinyInteger('decimals')->nullable();
            $t->unsignedSmallInteger('width')->nullable();                           // required for fixed width
            $t->string('align', 5)->nullable();                                      // left | right
            $t->string('pad_char', 1)->nullable();
            $t->boolean('truncate')->default(false);
            $t->index(['profile_id', 'position']);
        });

        Schema::create('pay_journal_accounts', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->string('account_key', 80);                                           // component.{code} | scheme.{code}.employee_liability | .employer_expense | .employer_liability | net_pay
            $t->string('account_code', 40);
            $t->string('label', 150)->nullable();
            $t->foreignUlid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['organization_id', 'account_key']);
        });

        Schema::create('pay_exports', function (Blueprint $t) {
            $t->ulid('id')->primary();
            $t->foreignUlid('organization_id')->constrained()->cascadeOnDelete();
            $t->foreignUlid('run_id')->constrained('pay_runs')->cascadeOnDelete();
            $t->foreignUlid('profile_id')->nullable()->constrained('pay_export_profiles')->nullOnDelete();
            $t->string('kind_code', 30);
            $t->string('profile_name', 150);                                         // snapshot
            $t->string('file_name', 200);
            $t->string('mime', 60);
            $t->unsignedInteger('row_count');
            $t->decimal('total_amount', 15, 2)->nullable();
            $t->string('checksum', 64);                                              // sha256 of the stored bytes
            $t->longText('content');                                                 // encrypted cast: bank accounts and salaries
            $t->foreignUlid('generated_by')->constrained('users');
            $t->timestamp('created_at');
            $t->index(['run_id', 'kind_code']);
        });
    }

    public function down(): void
    {
        foreach (['pay_exports', 'pay_journal_accounts', 'pay_export_columns', 'pay_export_profiles'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
