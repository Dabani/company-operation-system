<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AttendanceImport;
use App\Models\AttendancePeriod;
use App\Services\Hr\Attendance\AttendanceImporter;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;

/** CSV import, month validation (the lock payroll relies on) and recomputation. */
class AttendanceAdminController extends Controller
{
    private function org(): string { return app(OrganizationContext::class)->organizationId(); }

    private function importShape(AttendanceImport $i): array
    {
        return ['id' => $i->id, 'filename' => $i->filename, 'rows_total' => $i->rows_total, 'rows_imported' => $i->rows_imported, 'rows_skipped' => $i->rows_skipped, 'rows_rejected' => $i->rows_rejected,
            'errors' => $i->errors, 'created_at' => $i->created_at?->toIso8601String()];
    }

    public function import(Request $request, AttendanceImporter $importer)
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);
        $f = $request->file('file');
        $i = $importer->run($f->getRealPath(), $f->getClientOriginalName(), $request->user(), $this->org());
        return response()->json(['data' => $this->importShape($i)], 201);
    }

    public function imports() { return ['data' => AttendanceImport::orderByDesc('created_at')->limit(50)->get()->map(fn ($i) => $this->importShape($i))]; }

    public function periods(Request $request)
    {
        $year = (int) ($request->query('year') ?: now()->year);
        $rows = AttendancePeriod::whereDate('month', '>=', "$year-01-01")->whereDate('month', '<=', "$year-12-31")->get()->keyBy(fn ($p) => substr($p->month->toDateString(), 0, 7));
        return ['data' => collect(range(1, 12))->map(function ($m) use ($year, $rows) {
            $key = sprintf('%d-%02d', $year, $m);
            $p = $rows[$key] ?? null;
            return ['month' => $key, 'validated' => $p && !$p->reopened_at, 'validated_at' => $p?->validated_at?->toIso8601String(), 'reopened' => (bool) $p?->reopened_at, 'reopen_reason' => $p?->reopen_reason];
        })->values()];
    }

    private function month(string $m): string
    {
        abort_unless(preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m), 422);
        return $m;
    }

    public function validateMonth(Request $request, string $month, AttendanceService $svc)
    {
        $p = $svc->validateMonth($this->org(), $this->month($month), $request->user());
        return response()->json(['data' => ['month' => $month, 'validated_at' => $p->validated_at->toIso8601String()]], 201);
    }

    public function reopenMonth(Request $request, string $month, AttendanceService $svc)
    {
        $d = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $svc->reopenMonth($this->org(), $this->month($month), $d['reason'], $request->user());
        return ['data' => ['month' => $month, 'reopened' => true]];
    }

    public function recompute(Request $request, AttendanceService $svc)
    {
        $d = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        return ['data' => ['employees' => $svc->recomputeMonth($this->org(), $d['month'])]];
    }
}
