<?php

namespace App\Services\Hr\Attendance;

use App\Models\AttendanceEvent;
use App\Models\AttendanceImport;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Hr\HrSettings;
use App\Support\Lookup;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

/**
 * Imports clock punches from a CSV file (a biometric device export or a spreadsheet saved as CSV). Device-neutral on purpose: columns are found by name.
 *   employee_number (also: employee, number, badge, id)   timestamp (also: datetime, time, punch)   direction (also: type, in_out, status) - optional
 * Timestamps are local time in the organization timezone: 2026-11-02 08:03[:05], 2026-11-02T08:03, 02/11/2026 08:03, or ISO 8601 with an offset.
 * Without a direction column the direction alternates per employee (in, out, in, ...). Re-importing a file is safe: duplicates and double taps are skipped.
 */
class AttendanceImporter
{
    public const MAX_ROWS = 20000;
    private const FORMATS = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'd/m/Y H:i:s', 'd/m/Y H:i'];
    private const COLS = ['employee' => ['employee_number', 'employee', 'number', 'badge', 'id'], 'time' => ['timestamp', 'datetime', 'time', 'punch', 'date_time'], 'dir' => ['direction', 'type', 'in_out', 'status']];

    public function __construct(private AttendanceService $attendance) {}

    private function fail(string $key, array $p = []): never
    {
        throw ValidationException::withMessages(['file' => [__("attendance.$key", $p)]]);
    }

    private function parseTime(string $raw, string $tz): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $raw)) {
            try { return (new DateTimeImmutable($raw))->getTimestamp(); } catch (\Throwable) { return null; }
        }
        foreach (self::FORMATS as $f) {
            $d = DateTimeImmutable::createFromFormat($f, $raw, new DateTimeZone($tz));
            if ($d && $d->format($f) === $raw) {
                return $d->getTimestamp();
            }
        }
        return null;
    }

    private function direction(?string $raw): ?string
    {
        $v = strtolower(trim((string) $raw));
        return match (true) {
            $v === '' => null,
            in_array($v, ['in', 'i', '1', 'entry', 'ci', 'check-in', 'checkin'], true) => 'in',
            in_array($v, ['out', 'o', '0', 'exit', 'co', 'check-out', 'checkout'], true) => 'out',
            default => 'invalid',
        };
    }

    public function run(string $path, string $filename, User $by, string $orgId): AttendanceImport
    {
        $tz = AttendanceService::tz($orgId);
        $h = fopen($path, 'r');
        $first = fgets($h);
        if ($first === false) {
            $this->fail('import_empty');
        }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $delim = collect([',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t")])->sortDesc()->keys()->first();
        $header = array_map(fn ($c) => strtolower(trim($c)), str_getcsv($first, $delim));
        $col = [];
        foreach (self::COLS as $k => $names) {
            foreach ($names as $n) {
                if (($i = array_search($n, $header, true)) !== false) { $col[$k] = $i; break; }
            }
        }
        if (!isset($col['employee'], $col['time'])) {
            $this->fail('import_columns');
        }

        $staff = Employee::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'employee_number');
        $now = now()->getTimestamp();
        $window = HrSettings::get($orgId, 'pairing_window_hours') * 3600;
        $minGap = HrSettings::get($orgId, 'punch_min_interval_seconds');
        $total = 0; $rejected = 0; $errors = [];
        $byEmp = [];
        $line = 1;
        $reject = function (string $why) use (&$rejected, &$errors, &$line) { $rejected++; if (count($errors) < 50) { $errors[] = "line $line: " . __("attendance.import_reason.$why"); } };
        while (($row = fgetcsv($h, 0, $delim)) !== false) {
            $line++;
            if ($row === [null] || $row === []) {
                continue;
            }
            if (++$total > self::MAX_ROWS) {
                fclose($h);
                $this->fail('import_too_many', ['max' => self::MAX_ROWS]);
            }
            $emp = $staff[trim((string) ($row[$col['employee']] ?? ''))] ?? null;
            $ts = $this->parseTime((string) ($row[$col['time']] ?? ''), $tz);
            $dir = isset($col['dir']) ? $this->direction($row[$col['dir']] ?? null) : null;
            if (!$emp) { $reject('unknown_employee'); continue; }
            if ($ts === null) { $reject('bad_timestamp'); continue; }
            if ($ts > $now + 300) { $reject('future'); continue; }
            if ($dir === 'invalid') { $reject('bad_direction'); continue; }
            if ($this->attendance->isValidated($orgId, AttendanceCalculator::localDate($tz, gmdate('Y-m-d H:i:s', $ts)))) { $reject('period_validated'); continue; }
            $byEmp[$emp][] = ['ts' => $ts, 'dir' => $dir];
        }
        fclose($h);

        $imported = 0; $skipped = 0;
        $import = AttendanceImport::create(['filename' => mb_substr($filename, 0, 255), 'created_by' => $by->id, 'created_at' => now()]);
        $sourceId = Lookup::id('attendance_source', 'import');
        foreach ($byEmp as $empId => $rows) {
            usort($rows, fn ($a, $b) => $a['ts'] <=> $b['ts']);
            $lo = $rows[0]['ts']; $hi = end($rows)['ts'];
            $existing = AttendanceEvent::withoutGlobalScopes()->where('employee_id', $empId)->where('occurred_at', '>=', gmdate('Y-m-d H:i:s', $lo - $window))
                ->where('occurred_at', '<=', gmdate('Y-m-d H:i:s', $hi))->orderBy('occurred_at')->orderBy('id')->get();
            $timeline = $existing->map(fn ($x) => ['dir' => $x->direction, 'ts' => $x->occurred_at->getTimestamp()])->all();   // everything already known, oldest first
            foreach ($rows as $r) {
                $prev = null;
                foreach ($timeline as $t) {
                    if ($t['ts'] <= $r['ts']) { $prev = $t; }
                }
                if ($prev && $r['ts'] - $prev['ts'] < max(1, $minGap)) { $skipped++; continue; }          // the same punch again, or a double tap
                $dir = $r['dir'] ?? EventPairer::infer($prev ? ['dir' => $prev['dir'], 'at' => gmdate('Y-m-d H:i:s', $prev['ts'])] : null, gmdate('Y-m-d H:i:s', $r['ts']), $window);
                AttendanceEvent::create(['employee_id' => $empId, 'direction' => $dir, 'occurred_at' => gmdate('Y-m-d H:i:s', $r['ts']), 'source_id' => $sourceId, 'import_id' => $import->id, 'created_by' => $by->id]);
                $timeline[] = ['dir' => $dir, 'ts' => $r['ts']];
                usort($timeline, fn ($a, $b) => $a['ts'] <=> $b['ts']);
                $imported++;
            }
            $emp = Employee::withoutGlobalScopes()->find($empId);
            $emp && $this->attendance->recompute($emp, (new DateTimeImmutable(AttendanceCalculator::localDate($tz, gmdate('Y-m-d H:i:s', $lo))))->modify('-1 day')->format('Y-m-d'),
                AttendanceCalculator::localDate($tz, gmdate('Y-m-d H:i:s', $hi)));
        }
        $import->update(['rows_total' => $total, 'rows_imported' => $imported, 'rows_skipped' => $skipped, 'rows_rejected' => $rejected, 'errors' => $errors ? implode("\n", $errors) : null]);
        AuditLogger::log('attendance.imported', $import, [], ['rows' => $total, 'imported' => $imported, 'skipped' => $skipped, 'rejected' => $rejected]);
        return $import->refresh();
    }
}
