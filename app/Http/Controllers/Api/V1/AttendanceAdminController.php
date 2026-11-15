<?php

namespace App\Http\Controllers\Api\V1;

use App\Rules\RichTextMin;
use App\Http\Controllers\Controller;
use App\Models\AttendancePeriod;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\OrganizationContext;
use Illuminate\Http\Request;

/** Month validation (the lock payroll relies on) and recomputation. The file import lives in AttendanceImportController. */
class AttendanceAdminController extends Controller
{
    private function org(): string { return app(OrganizationContext::class)->organizationId(); }

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
        $d = $request->validate(['reason' => ['required', 'string', 'max:5000', new RichTextMin(5)]]);
        $svc->reopenMonth($this->org(), $this->month($month), $d['reason'], $request->user());
        return ['data' => ['month' => $month, 'reopened' => true]];
    }

    public function recompute(Request $request, AttendanceService $svc)
    {
        $d = $request->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']]);
        return ['data' => ['employees' => $svc->recomputeMonth($this->org(), $d['month'])]];
    }
}
