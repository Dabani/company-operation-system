<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDeviceCode;
use App\Models\AttendanceImport;
use App\Models\AttendanceImportProfile;
use App\Models\Employee;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceImporter;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Attendance file import (any format, configurable profiles), device codes. Every route needs hr.attendance.manage. */
class AttendanceImportController extends Controller
{
    private const EXTENSIONS = ['', 'csv', 'txt', 'tsv', 'xlsx', 'xlsm', 'xls', 'ods', 'html', 'htm'];

    private function org(): string { return app(OrganizationContext::class)->organizationId(); }

    private function upload(Request $request): UploadedFile
    {
        $request->validate(['file' => 'required|file|max:5120']);
        $f = $request->file('file');
        if (!in_array(strtolower($f->getClientOriginalExtension()), self::EXTENSIONS, true)) {
            throw ValidationException::withMessages(['file' => [__('attendance.file_type_not_supported')]]);
        }
        return $f;
    }

    // ---- files -------------------------------------------------------------------------------------------------------
    /** What is in a file (sheets, the likely header row and its columns, sample rows) so that a profile can be chosen or built. */
    public function inspect(Request $request, AttendanceImporter $importer)
    {
        $f = $this->upload($request);
        $d = $request->validate(['sheet' => 'nullable|string|max:100', 'header_row' => 'nullable|integer|min:1|max:50']);
        return ['data' => $importer->inspect($f->getRealPath(), $d['sheet'] ?? null, $d['header_row'] ?? null)];
    }

    private function profileId(Request $request): ?string
    {
        $d = $request->validate(['profile_id' => ['nullable', Rule::exists('hr_attendance_import_profiles', 'id')->where('organization_id', $this->org())], 'sheet' => 'nullable|string|max:100']);
        return $d['profile_id'] ?? null;
    }

    /** A dry run: the same reading as the import, but nothing is saved. */
    public function preview(Request $request, AttendanceImporter $importer)
    {
        $f = $this->upload($request);
        return ['data' => $importer->run($f->getRealPath(), $f->getClientOriginalName(), $request->user(), $this->org(), $this->profileId($request), false, $request->input('sheet'))];
    }

    public function import(Request $request, AttendanceImporter $importer)
    {
        $f = $this->upload($request);
        return response()->json(['data' => $importer->run($f->getRealPath(), $f->getClientOriginalName(), $request->user(), $this->org(), $this->profileId($request), true, $request->input('sheet'))], 201);
    }

    public function imports()
    {
        return ['data' => AttendanceImport::orderByDesc('created_at')->limit(50)->get()->map(fn ($i) => ['id' => $i->id, 'filename' => $i->filename, 'file_type' => $i->file_type, 'rows_total' => $i->rows_total,
            'rows_imported' => $i->rows_imported, 'rows_skipped' => $i->rows_skipped, 'rows_rejected' => $i->rows_rejected, 'errors' => $i->errors, 'created_at' => $i->created_at?->toIso8601String()])];
    }

    // ---- profiles -------------------------------------------------------------------------------------------------------
    private function profileShape(AttendanceImportProfile $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'description' => $p->description, 'is_system' => $p->is_system] + $p->settings()
            + ['slots' => $p->slots->map(fn ($s) => ['column' => $s->column_name, 'direction' => $s->direction])->values()];
    }

    private function profileRules(bool $create, ?string $ignoreId = null): array
    {
        $col = 'nullable|string|max:100';
        $list = 'nullable|string|max:500';
        $req = $create ? 'required' : 'sometimes';
        return [
            'name' => [$req, 'string', 'max:120', Rule::unique('hr_attendance_import_profiles', 'name')->where('organization_id', $this->org())->ignore($ignoreId)],
            'description' => 'nullable|string|max:255', 'layout' => ['sometimes', Rule::in(['punches', 'daily'])], 'sheet' => $col, 'header_row' => 'sometimes|integer|min:0|max:50',
            'identify_by' => ['sometimes', Rule::in(['employee_number', 'device_code', 'either'])], 'employee_column' => $col, 'timestamp_column' => $col, 'date_column' => $col, 'time_column' => $col,
            'direction_column' => $col, 'in_values' => $list, 'out_values' => $list, 'ignore_values' => $list,
            'date_formats' => ['nullable', 'string', 'max:500', fn ($a, $v, $fail) => collect(explode(',', (string) $v))->filter(fn ($f) => trim($f) !== '')->every(fn ($f) => preg_match('/^[dDjmnYyHhGgisaAT\s\-\/\.:,\\\\]+$/', trim($f)) && preg_match('/[dj]/', $f) && preg_match('/[mnY]/', $f)) ?: $fail(__('attendance.date_format_invalid'))],
            'device_timezone' => ['nullable', Rule::in(\DateTimeZone::listIdentifiers())],
            'slots' => 'sometimes|array|max:24', 'slots.*.column' => 'required|string|max:100', 'slots.*.direction' => ['required', Rule::in(['in', 'out'])],
        ];
    }

    private function saveSlots(AttendanceImportProfile $p, ?array $slots): void
    {
        if ($slots === null) {
            return;
        }
        $p->slots()->delete();
        foreach (array_values($slots) as $i => $s) {
            $p->slots()->create(['position' => $i, 'column_name' => $s['column'], 'direction' => $s['direction']]);
        }
    }

    public function profiles()
    {
        return ['data' => AttendanceImportProfile::with('slots')->orderByDesc('is_system')->orderBy('name')->get()->map(fn ($p) => $this->profileShape($p))];
    }

    public function storeProfile(Request $request)
    {
        $d = $request->validate($this->profileRules(true));
        if (($d['layout'] ?? 'punches') === 'daily' && empty($d['slots'])) {
            throw ValidationException::withMessages(['slots' => [__('attendance.profile_slots_required')]]);
        }
        $p = DB::transaction(function () use ($d) {
            $p = AttendanceImportProfile::create(collect($d)->except('slots')->all() + ['is_system' => false]);
            $this->saveSlots($p, $d['slots'] ?? null);
            return $p;
        });
        AuditLogger::log('attendance.profile_created', $p);
        return response()->json(['data' => $this->profileShape($p->refresh()->load('slots'))], 201);
    }

    public function updateProfile(Request $request, string $id)
    {
        $p = AttendanceImportProfile::findOrFail($id);
        if ($p->is_system) {
            throw ValidationException::withMessages(['profile' => [__('attendance.profile_system')]]);
        }
        $d = $request->validate($this->profileRules(false, $p->id));
        DB::transaction(function () use ($p, $d) {
            $p->update(collect($d)->except('slots')->all());
            $this->saveSlots($p, $d['slots'] ?? null);
        });
        AuditLogger::log('attendance.profile_updated', $p);
        return ['data' => $this->profileShape($p->refresh()->load('slots'))];
    }

    public function duplicateProfile(string $id)
    {
        $src = AttendanceImportProfile::with('slots')->findOrFail($id);
        $name = $src->name . ' (copy)';
        for ($n = 2; AttendanceImportProfile::where('name', $name)->exists(); $n++) {
            $name = $src->name . " (copy $n)";
        }
        $p = DB::transaction(function () use ($src, $name) {
            $p = AttendanceImportProfile::create($src->settings() + ['name' => $name, 'description' => $src->description, 'is_system' => false]);
            $this->saveSlots($p, $src->slots->map(fn ($s) => ['column' => $s->column_name, 'direction' => $s->direction])->all());
            return $p;
        });
        AuditLogger::log('attendance.profile_created', $p, [], ['copy_of' => $src->id]);
        return response()->json(['data' => $this->profileShape($p->refresh()->load('slots'))], 201);
    }

    public function destroyProfile(string $id)
    {
        $p = AttendanceImportProfile::findOrFail($id);
        if ($p->is_system) {
            throw ValidationException::withMessages(['profile' => [__('attendance.profile_system')]]);
        }
        $p->delete();
        AuditLogger::log('attendance.profile_deleted', $p);
        return response()->noContent();
    }

    // ---- device codes ----------------------------------------------------------------------------------------------------
    private function codeShape(AttendanceDeviceCode $c): array
    {
        return ['id' => $c->id, 'employee_id' => $c->employee_id, 'employee' => $c->employee?->full_name, 'employee_number' => $c->employee?->employee_number, 'code' => $c->code, 'label' => $c->label];
    }

    public function deviceCodes(Request $request)
    {
        $q = AttendanceDeviceCode::with('employee')->orderBy('code')->limit(2000);
        if ($e = $request->query('employee_id')) {
            $q->where('employee_id', $e);
        }
        return ['data' => $q->get()->map(fn ($c) => $this->codeShape($c))];
    }

    /** Map one or several device codes to employees (the "unknown codes" of an import). Existing codes are refused, never silently reassigned. */
    public function storeDeviceCodes(Request $request)
    {
        $d = $request->validate(['codes' => 'required|array|min:1|max:500', 'codes.*.employee_id' => ['required', Rule::exists('hr_employees', 'id')->where('organization_id', $this->org())],
            'codes.*.code' => 'required|string|max:60', 'codes.*.label' => 'nullable|string|max:100']);
        $rows = collect($d['codes'])->map(fn ($c) => ['employee_id' => $c['employee_id'], 'code' => trim($c['code']), 'label' => $c['label'] ?? null]);
        $existing = AttendanceDeviceCode::whereIn('code', $rows->pluck('code'))->pluck('code')->all();
        if ($existing || $rows->pluck('code')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['codes' => [__('attendance.device_code_exists', ['codes' => implode(', ', array_unique(array_merge($existing, $rows->pluck('code')->duplicates()->all())))])]]);
        }
        DB::transaction(fn () => $rows->each(fn ($r) => AttendanceDeviceCode::create($r)));
        AuditLogger::log('attendance.device_codes_added', null, [], ['count' => $rows->count()]);
        return response()->json(['data' => ['created' => $rows->count()]], 201);
    }

    public function destroyDeviceCode(string $id)
    {
        $c = AttendanceDeviceCode::findOrFail($id);
        $c->delete();
        AuditLogger::log('attendance.device_code_removed', $c);
        return response()->noContent();
    }
}
