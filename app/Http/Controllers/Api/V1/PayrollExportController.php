<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LookupType;
use App\Models\LookupValue;
use App\Models\PayComponent;
use App\Models\PayExport;
use App\Models\PayExportColumn;
use App\Models\PayExportProfile;
use App\Models\PayJournalAccount;
use App\Models\PayRun;
use App\Models\PayScheme;
use App\Services\AuditLogger;
use App\Services\Hr\Payroll\ExportFields;
use App\Services\Hr\Payroll\PayrollExportService;
use App\Services\OrganizationContext;
use App\Support\Lookup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Payroll export layouts, journal accounts and generated files (Guide 5.6). perm: hr.payroll.export (generate / download), hr.payroll.export.configure (layouts, accounts),
 * hr.payroll.verify (the second person who verifies a layout). Every generation and download is audited; file contents never appear in the audit trail.
 */
class PayrollExportController extends Controller
{
    public function __construct(private PayrollExportService $svc) {}

    private function org(): string { return app(OrganizationContext::class)->organizationId(); }
    private function fail(string $field, string $key, array $p = []): never { throw ValidationException::withMessages([$field => [__("payexport.err.$key", $p)]]); }
    private function lk(string $type): array { return ['required', Rule::exists('lookup_values', 'id')->whereIn('lookup_type_id', LookupType::where('code', $type)->pluck('id'))]; }

    private function profile(PayExportProfile $p): array
    {
        $p->loadMissing(['kind', 'layout', 'verification', 'columns.scheme', 'columns.component', 'preparer', 'verifier']);
        return ['id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'kind' => $p->kind->code, 'layout' => $p->layout->code, 'delimiter' => $p->delimiter, 'quote_char' => $p->quote_char, 'line_ending' => $p->line_ending,
            'encoding' => $p->encoding, 'decimal_separator' => $p->decimal_separator, 'date_format' => $p->date_format, 'include_header' => $p->include_header, 'header_template' => $p->header_template, 'trailer_template' => $p->trailer_template,
            'file_extension' => $p->file_extension, 'filename_template' => $p->filename_template, 'agreed_with' => $p->agreed_with, 'is_active' => $p->is_active, 'verification' => $p->verification->code,
            'prepared_by' => $p->preparer?->name, 'verified_by' => $p->verifier?->name,
            'columns' => $p->columns->map(fn ($c) => ['position' => $c->position, 'header' => $c->header, 'source' => $c->source, 'scheme' => $c->scheme?->code, 'component' => $c->component?->code, 'constant' => $c->constant, 'format' => $c->format,
                'decimals' => $c->decimals, 'width' => $c->width, 'align' => $c->align, 'pad_char' => $c->pad_char, 'truncate' => $c->truncate])->values()];
    }

    private function exportShape(PayExport $e): array
    {
        return ['id' => $e->id, 'run_id' => $e->run_id, 'kind' => $e->kind_code, 'profile' => $e->profile_name, 'file_name' => $e->file_name, 'rows' => $e->row_count, 'total_amount' => $e->total_amount === null ? null : (string) $e->total_amount,
            'checksum' => $e->checksum, 'generated_by' => $e->generator?->name, 'created_at' => $e->created_at?->toIso8601String()];
    }

    public function fields()
    {
        return ['data' => collect(['bank_transfer', 'declaration', 'journal'])->mapWithKeys(fn ($k) => [$k => ['sources' => ExportFields::allowed($k), 'needs_scheme' => ExportFields::SCHEME, 'needs_component' => ExportFields::COMPONENT, 'run_statuses' => ExportFields::runStatuses($k)]])];
    }

    // ---- layouts -----------------------------------------------------------------------------------------------------------------------
    public function profiles() { return ['data' => PayExportProfile::with(['kind', 'layout', 'verification', 'columns.scheme', 'columns.component', 'preparer', 'verifier'])->orderBy('code')->get()->map(fn ($p) => $this->profile($p))]; }

    public function storeProfile(Request $request)
    {
        $d = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('pay_export_profiles', 'code')->where('organization_id', $this->org())], 'name' => 'required|string|max:150',
            'kind_id' => $this->lk('pay_export_kind'), 'layout_id' => $this->lk('pay_export_layout'), 'delimiter' => 'nullable|string|max:4', 'quote_char' => 'nullable|string|max:1',
            'line_ending' => 'required|in:LF,CRLF', 'encoding' => 'required|in:UTF-8,ISO-8859-1', 'decimal_separator' => ['required', Rule::in(['.', ','])], 'date_format' => ['required', 'string', 'max:20', 'regex:/^[dDjmnMYyHis\-\/\. ]+$/'],
            'include_header' => 'boolean', 'header_template' => 'nullable|string|max:2000', 'trailer_template' => 'nullable|string|max:2000', 'file_extension' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'filename_template' => ['required', 'string', 'max:150', 'regex:/^[A-Za-z0-9._\-{}]+$/'], 'agreed_with' => 'required|string|max:255',
            'columns' => 'required|array|min:1|max:60', 'columns.*.header' => 'required|string|max:80', 'columns.*.source' => 'required|string|max:60', 'columns.*.scheme_id' => 'nullable|string', 'columns.*.component_id' => 'nullable|string',
            'columns.*.constant' => 'nullable|string|max:100', 'columns.*.format' => 'required|in:text,amount,integer_minor,integer,date', 'columns.*.decimals' => 'nullable|integer|between:0,4', 'columns.*.width' => 'nullable|integer|between:1,500',
            'columns.*.align' => 'nullable|in:left,right', 'columns.*.pad_char' => 'nullable|string|max:1', 'columns.*.truncate' => 'boolean']);
        $kind = LookupValue::find($d['kind_id'])->code;
        $layout = LookupValue::find($d['layout_id'])->code;
        if ($layout === 'delimited' && ($d['delimiter'] ?? '') === '') {
            $this->fail('delimiter', 'delimiter_required');
        }
        $allowed = ExportFields::allowed($kind);
        foreach ($d['columns'] as $i => $c) {
            $f = "columns.$i.source";
            if (!in_array($c['source'], $allowed, true)) {
                $this->fail($f, 'source_not_allowed', ['source' => $c['source'], 'kind' => $kind]);
            }
            if (ExportFields::needsScheme($c['source']) && (empty($c['scheme_id']) || !PayScheme::whereKey($c['scheme_id'])->exists())) {
                $this->fail($f, 'scheme_required');
            }
            if (ExportFields::needsComponent($c['source']) && (empty($c['component_id']) || !PayComponent::whereKey($c['component_id'])->exists())) {
                $this->fail($f, 'component_required');
            }
            if ($c['source'] === 'constant' && !array_key_exists('constant', $c)) {
                $this->fail($f, 'constant_required');
            }
            if ($layout === 'fixed_width' && empty($c['width'])) {
                $this->fail("columns.$i.width", 'width_required');
            }
        }
        $p = DB::transaction(function () use ($d, $request) {
            $p = PayExportProfile::create(collect($d)->except('columns')->all() + ['verification_status_id' => Lookup::id('rule_verification_status', 'unverified'), 'prepared_by' => $request->user()->id, 'is_active' => true]);
            foreach (array_values($d['columns']) as $i => $c) {
                PayExportColumn::create(['profile_id' => $p->id, 'position' => $i + 1, 'header' => $c['header'], 'source' => $c['source'], 'scheme_id' => $c['scheme_id'] ?? null, 'component_id' => $c['component_id'] ?? null,
                    'constant' => $c['constant'] ?? null, 'format' => $c['format'], 'decimals' => $c['decimals'] ?? null, 'width' => $c['width'] ?? null, 'align' => $c['align'] ?? null, 'pad_char' => $c['pad_char'] ?? null, 'truncate' => $c['truncate'] ?? false]);
            }
            return $p;
        });
        try {                                                                  // a layout that cannot even render the sample rows is refused now, not on payday
            $s = $this->svc->sampleRows($p);
            $this->svc->render($s['profile'], $s['rows'], $s['vars']);
        } catch (ValidationException $e) {
            $p->delete();
            throw $e;
        }
        AuditLogger::log('payroll.export_profile_created', $p, [], ['code' => $p->code, 'kind' => $kind, 'agreed_with' => $p->agreed_with]);
        return response()->json(['data' => $this->profile($p)], 201);
    }

    public function verifyProfile(Request $request, string $id)
    {
        $p = PayExportProfile::findOrFail($id);
        if ($p->prepared_by === $request->user()->id) {
            $this->fail('id', 'verify_not_author');
        }
        if ($p->verification_status_id === Lookup::id('rule_verification_status', 'verified')) {
            $this->fail('id', 'already_verified');
        }
        $p->update(['verification_status_id' => Lookup::id('rule_verification_status', 'verified'), 'verified_by' => $request->user()->id, 'verified_at' => now()]);
        AuditLogger::log('payroll.export_profile_verified', $p, ['verification' => 'unverified'], ['verification' => 'verified']);
        return ['data' => $this->profile($p->refresh())];
    }

    public function setActive(Request $request, string $id)
    {
        $d = $request->validate(['is_active' => 'required|boolean']);
        $p = PayExportProfile::findOrFail($id);
        $p->update($d);
        AuditLogger::log('payroll.export_profile_active', $p, [], $d);
        return ['data' => $this->profile($p->refresh())];
    }

    /** A verified layout is final (it may be switched off, never edited or deleted): a new code is a new layout. */
    public function destroyProfile(string $id)
    {
        $p = PayExportProfile::findOrFail($id);
        if ($p->verification_status_id === Lookup::id('rule_verification_status', 'verified')) {
            $this->fail('id', 'verified_is_final');
        }
        AuditLogger::log('payroll.export_profile_deleted', $p, ['code' => $p->code]);
        $p->delete();
        return response()->noContent();
    }

    /** The layout applied to invented rows: to check with the bank / RRA / RSSB before anyone verifies it. */
    public function preview(string $id)
    {
        $p = PayExportProfile::findOrFail($id);
        $s = $this->svc->sampleRows($p);
        $content = $this->svc->render($s['profile'], $s['rows'], $s['vars']);
        return ['data' => ['sample' => true, 'encoding' => $p->encoding, 'text' => $p->encoding === 'UTF-8' ? $content : mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1'), 'rows' => count($s['rows'])]];
    }

    // ---- journal accounts ------------------------------------------------------------------------------------------------------------------
    public function journalAccounts() { return ['data' => PayJournalAccount::orderBy('account_key')->get()->map(fn ($a) => ['account_key' => $a->account_key, 'account_code' => $a->account_code, 'label' => $a->label])->values()]; }

    public function saveJournalAccounts(Request $request)
    {
        $d = $request->validate(['accounts' => 'required|array|min:1|max:300', 'accounts.*.account_key' => ['required', 'string', 'max:80', 'regex:/^(net_pay|component\.[a-z0-9_]+|scheme\.[a-z0-9_]+\.(employee_liability|employer_expense|employer_liability))$/'],
            'accounts.*.account_code' => 'required|string|max:40', 'accounts.*.label' => 'nullable|string|max:150']);
        foreach ($d['accounts'] as $a) {
            $row = PayJournalAccount::updateOrCreate(['account_key' => $a['account_key']], ['account_code' => $a['account_code'], 'label' => $a['label'] ?? null, 'created_by' => $request->user()->id]);
            AuditLogger::log('payroll.journal_account_saved', $row, [], ['key' => $a['account_key'], 'account' => $a['account_code']]);
        }
        return $this->journalAccounts();
    }

    public function requiredAccounts(string $id) { return ['data' => $this->svc->requiredAccounts(PayRun::findOrFail($id))]; }

    // ---- generated files ---------------------------------------------------------------------------------------------------------------------
    public function generate(Request $request, string $id)
    {
        $d = $request->validate(['profile_id' => ['required', Rule::exists('pay_export_profiles', 'id')->where('organization_id', $this->org())]]);
        $e = $this->svc->generate(PayRun::findOrFail($id), PayExportProfile::findOrFail($d['profile_id']), $request->user());
        return response()->json(['data' => $this->exportShape($e->load('generator'))], 201);
    }

    public function exports(string $id)
    {
        return ['data' => PayExport::with('generator')->where('run_id', PayRun::findOrFail($id)->id)->orderByDesc('created_at')->get()->map(fn ($e) => $this->exportShape($e))->values()];
    }

    public function download(string $exportId)
    {
        $e = PayExport::findOrFail($exportId);
        AuditLogger::log('payroll.export_downloaded', $e, [], ['run' => $e->run_id, 'kind' => $e->kind_code, 'checksum' => $e->checksum]);
        return response($e->content, 200, ['Content-Type' => $e->mime, 'Content-Disposition' => 'attachment; filename="' . $e->file_name . '"', 'X-Content-SHA256' => $e->checksum]);
    }
}
