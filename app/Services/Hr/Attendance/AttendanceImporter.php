<?php

namespace App\Services\Hr\Attendance;

use App\Models\AttendanceDeviceCode;
use App\Models\AttendanceEvent;
use App\Models\AttendanceImport;
use App\Models\AttendanceImportProfile;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\Import\CellParser;
use App\Services\Hr\Attendance\Import\ColumnRef;
use App\Services\Hr\Attendance\Import\ImportFileException;
use App\Services\Hr\Attendance\Import\ImportMapper;
use App\Services\Hr\Attendance\Import\SpreadsheetReader;
use App\Services\Hr\HrSettings;
use App\Support\Lookup;
use DateTimeImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Imports clock punches from any file (Excel, OpenDocument, HTML table, CSV) following an import profile (see Import\ImportMapper): which sheet, where the header is,
 * which columns hold the person / date / time / direction, what the device writes for in and out. People are recognised by employee number or by the device's own
 * code (Device codes). A dry run ("preview") shows exactly what would happen without changing anything. Re-importing a file is safe.
 */
class AttendanceImporter
{
    public function __construct(private AttendanceService $attendance, private SpreadsheetReader $reader) {}

    private function fail(ImportFileException $e): never
    {
        throw ValidationException::withMessages(['file' => [__("attendance.{$e->key}", $e->params)]]);
    }

    /** What is in the file: sheets, the probable header row and its columns, a few rows. Used to build or choose a profile. */
    public function inspect(string $path, ?string $sheet = null, ?int $headerRow = null): array
    {
        try {
            $f = $this->reader->read($path, $sheet);
        } catch (ImportFileException $e) {
            $this->fail($e);
        }
        $rows = $f['rows'];
        $guess = $headerRow;
        if (!$guess) {
            $guess = 1;
            foreach (array_slice($rows, 0, 15, true) as $i => $r) {
                $cells = array_values(array_filter($r, fn ($c) => CellParser::text($c) !== ''));
                $words = array_filter($cells, fn ($c) => !CellParser::isNumber($c) && !is_numeric(CellParser::text($c)));
                if (count($cells) >= 2 && count($words) * 2 >= count($cells)) {
                    $guess = $i + 1;
                    break;
                }
            }
        }
        $header = $rows[$guess - 1] ?? [];
        $columns = [];
        foreach ($header as $i => $c) {
            $columns[] = ['index' => $i, 'letter' => ColumnRef::letter($i), 'label' => CellParser::text($c) ?: '(' . ColumnRef::letter($i) . ')'];
        }
        $sample = array_map(fn ($r) => array_map(fn ($c) => mb_substr(CellParser::text($c), 0, 60), $r), array_slice($rows, $guess, 5));
        return ['type' => $f['type'], 'sheets' => $f['sheets'], 'sheet' => $f['sheet'], 'header_row' => $guess, 'row_count' => count($rows), 'columns' => $columns, 'sample' => $sample];
    }

    /** @return array<string,string> lookup key => employee id for the way this profile identifies people */
    private function directory(string $orgId, string $identifyBy): array
    {
        $dir = [];
        if (in_array($identifyBy, ['employee_number', 'either'], true)) {
            foreach (Employee::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('id', 'employee_number') as $number => $id) {
                $dir[strtoupper((string) $number)] = $id;
            }
        }
        if (in_array($identifyBy, ['device_code', 'either'], true)) {
            foreach (AttendanceDeviceCode::withoutGlobalScopes()->where('organization_id', $orgId)->pluck('employee_id', 'code') as $code => $id) {
                $dir['#' . strtoupper(ltrim((string) $code, '0') ?: '0')] = $id;       // codes also match without leading zeros: 0101 = 101
            }
        }
        return $dir;
    }

    private function lookup(array $dir, string $ref): ?string
    {
        $u = strtoupper($ref);
        return $dir[$u] ?? $dir['#' . (ltrim($u, '0') ?: '0')] ?? null;
    }

    /**
     * @return array<string,mixed> counts, rejected lines, unknown codes and a sample; with $commit = false nothing is written (preview)
     */
    public function run(string $path, string $filename, User $by, string $orgId, ?string $profileId = null, bool $commit = true, ?string $sheet = null): array
    {
        $tz = AttendanceService::tz($orgId);
        $profile = $profileId ? AttendanceImportProfile::with('slots')->findOrFail($profileId) : null;
        $settings = ($profile?->settings() ?? []) + ['layout' => 'punches', 'header_row' => 1, 'identify_by' => 'either'];
        $slots = $profile ? $profile->slots->map(fn ($s) => ['column' => $s->column_name, 'direction' => $s->direction])->all() : [];
        try {
            $file = $this->reader->read($path, $sheet !== null && trim($sheet) !== '' ? $sheet : ($settings['sheet'] ?? null));
        } catch (ImportFileException $e) {
            $this->fail($e);
        }
        $m = ImportMapper::map($file['rows'], $settings, $slots, $tz);
        if ($m['fatal']) {
            $this->fail(new ImportFileException($m['fatal']['key'], $m['fatal']['params']));
        }

        $dir = $this->directory($orgId, $settings['identify_by'] ?? 'either');
        $nowTs = now()->getTimestamp();
        $window = HrSettings::get($orgId, 'pairing_window_hours') * 3600;
        $minGap = max(1, HrSettings::get($orgId, 'punch_min_interval_seconds'));
        $rejected = $m['rejected'];
        $unknown = [];
        $byEmp = [];
        foreach ($m['punches'] as $p) {
            $emp = $this->lookup($dir, $p['ref']);
            if (!$emp) {
                $rejected[] = ['line' => $p['line'], 'reason' => 'unknown_employee'];
                $unknown[$p['ref']] = true;
            } elseif ($p['ts'] > $nowTs + 300) {
                $rejected[] = ['line' => $p['line'], 'reason' => 'future'];
            } elseif ($this->attendance->isValidated($orgId, AttendanceCalculator::localDate($tz, gmdate('Y-m-d H:i:s', $p['ts'])))) {
                $rejected[] = ['line' => $p['line'], 'reason' => 'period_validated'];
            } else {
                $byEmp[$emp][] = $p;
            }
        }
        usort($rejected, fn ($a, $b) => $a['line'] <=> $b['line']);

        $people = Employee::withoutGlobalScopes()->whereIn('id', array_keys($byEmp))->get()->keyBy('id');
        $import = $commit ? AttendanceImport::create(['filename' => mb_substr($filename, 0, 255), 'created_by' => $by->id, 'created_at' => now(), 'profile_id' => $profile?->id, 'file_type' => $file['type']]) : null;
        $sourceId = $commit ? Lookup::id('attendance_source', 'import') : null;
        $imported = 0;
        $skipped = $m['ignored'];
        $sample = [];
        foreach ($byEmp as $empId => $rows) {
            usort($rows, fn ($a, $b) => $a['ts'] <=> $b['ts']);
            $lo = $rows[0]['ts'];
            $hi = end($rows)['ts'];
            $timeline = AttendanceEvent::withoutGlobalScopes()->where('employee_id', $empId)->where('occurred_at', '>=', gmdate('Y-m-d H:i:s', $lo - $window))
                ->where('occurred_at', '<=', gmdate('Y-m-d H:i:s', $hi))->orderBy('occurred_at')->orderBy('id')->get()->map(fn ($x) => ['dir' => $x->direction, 'ts' => $x->occurred_at->getTimestamp()])->all();
            foreach ($rows as $r) {
                $prev = null;
                foreach ($timeline as $t) {
                    if ($t['ts'] <= $r['ts']) { $prev = $t; }
                }
                $isNew = !($prev && $r['ts'] - $prev['ts'] < $minGap);                    // the same punch again, or a double tap
                $d = $r['dir'] ?? EventPairer::infer($prev ? ['dir' => $prev['dir'], 'at' => gmdate('Y-m-d H:i:s', $prev['ts'])] : null, gmdate('Y-m-d H:i:s', $r['ts']), $window);
                if (count($sample) < 20) {
                    $sample[] = ['line' => $r['line'], 'employee' => $people[$empId]->full_name, 'employee_number' => $people[$empId]->employee_number, 'ref' => $r['ref'],
                        'at' => (new DateTimeImmutable('@' . $r['ts']))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d H:i:s'), 'direction' => $d, 'new' => $isNew];
                }
                if (!$isNew) { $skipped++; continue; }
                if ($commit) {
                    AttendanceEvent::create(['employee_id' => $empId, 'direction' => $d, 'occurred_at' => gmdate('Y-m-d H:i:s', $r['ts']), 'source_id' => $sourceId, 'import_id' => $import->id, 'created_by' => $by->id]);
                }
                $timeline[] = ['dir' => $d, 'ts' => $r['ts']];
                usort($timeline, fn ($a, $b) => $a['ts'] <=> $b['ts']);
                $imported++;
            }
            if ($commit) {
                $this->attendance->recompute($people[$empId], (new DateTimeImmutable(AttendanceCalculator::localDate($tz, gmdate('Y-m-d H:i:s', $lo))))->modify('-1 day')->format('Y-m-d'),
                    AttendanceCalculator::localDate($tz, gmdate('Y-m-d H:i:s', $hi)));
            }
        }

        $errors = array_map(fn ($r) => 'line ' . $r['line'] . ': ' . __("attendance.import_reason.{$r['reason']}"), array_slice($rejected, 0, 50));
        $result = ['dry_run' => !$commit, 'import_id' => $import?->id, 'file_type' => $file['type'], 'sheet' => $file['sheet'], 'profile' => $profile?->name, 'columns' => $m['columns'],
            'rows_total' => $m['total'], 'rows_imported' => $imported, 'rows_skipped' => $skipped, 'rows_rejected' => count($rejected), 'errors' => $errors ? implode("\n", $errors) : null,
            'unknown_ids' => array_slice(array_map('strval', array_keys($unknown)), 0, 50), 'sample' => $sample, 'created_at' => $import?->created_at?->toIso8601String()];
        if ($commit) {
            $import->update(['rows_total' => $m['total'], 'rows_imported' => $imported, 'rows_skipped' => $skipped, 'rows_rejected' => count($rejected), 'errors' => $result['errors']]);
            AuditLogger::log('attendance.imported', $import, [], ['rows' => $m['total'], 'imported' => $imported, 'skipped' => $skipped, 'rejected' => count($rejected), 'type' => $file['type'], 'profile' => $profile?->name]);
        }
        return $result;
    }
}
