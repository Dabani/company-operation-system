<?php

namespace App\Services\Hr\Attendance\Import;

/**
 * Applies an import profile to the rows of a file and returns normalised punches (pure, unit-tested). Nothing here touches the database.
 *
 * Profile keys: layout (punches|daily), header_row (1-based, 0 = no header), employee_column, timestamp_column | date_column + time_column, direction_column,
 * in_values / out_values / ignore_values (comma lists), date_formats (comma list of PHP formats), device_timezone. A column is a header text, an Excel letter or #3.
 * A key left empty means "recognise the usual header names". Daily layout: one row per person and day, with a slot (column + direction) for each time cell.
 */
final class ImportMapper
{
    private const EMPLOYEE = ['employee number', 'employee', 'number', 'badge', 'badge number', 'user id', 'userid', 'person id', 'emp no', 'emp id', 'employee id', 'staff id', 'staff number', 'card no', 'card', 'id', 'code', 'no'];
    private const TIMESTAMP = ['timestamp', 'datetime', 'date time', 'date and time', 'punch', 'check time', 'checktime', 'event time'];
    private const DATE = ['date', 'day', 'work date', 'punch date', 'attendance date'];
    private const TIME = ['time', 'hour', 'punch time', 'clock time'];
    private const DIRECTION = ['direction', 'type', 'in out', 'in/out', 'status', 'state', 'event', 'punch state', 'attendance state', 'check type', 'checktype', 'flag', 'mode'];

    /**
     * @param array<int,array<int,mixed>> $rows every row of the sheet, header included
     * @param array<string,mixed> $profile
     * @param array<int,array{column:string,direction:string}> $slots daily layout only
     * @return array{fatal:?array{key:string,params:array},columns:array<string,string>,punches:array<int,array{line:int,ref:string,ts:int,dir:?string}>,rejected:array<int,array{line:int,reason:string}>,ignored:int,total:int}
     */
    public static function map(array $rows, array $profile, array $slots, string $orgTz): array
    {
        $headerRow = max(0, (int) ($profile['header_row'] ?? 1));
        $labels = $headerRow >= 1 ? array_map(fn ($c) => CellParser::text($c), $rows[$headerRow - 1] ?? []) : [];
        $headers = array_map(fn ($c) => CellParser::norm($c), $labels);
        $tz = trim((string) ($profile['device_timezone'] ?? '')) ?: $orgTz;
        $formats = DirectionMap::split($profile['date_formats'] ?? '');
        $daily = ($profile['layout'] ?? 'punches') === 'daily';
        $set = fn (string $k) => trim((string) ($profile[$k] ?? '')) !== '';
        $label = fn (?int $i) => $i === null ? '' : ($labels[$i] ?? ColumnRef::letter($i));

        $emp = ColumnRef::find($headers, $profile['employee_column'] ?? null, self::EMPLOYEE);
        $missing = [];
        if ($emp === null) {
            $missing[] = $set('employee_column') ? $profile['employee_column'] : 'employee';
        }

        $ts = $dateCol = $timeCol = $dirCol = null;
        $slotCols = [];
        if ($daily) {
            $dateCol = ColumnRef::find($headers, $profile['date_column'] ?? null, self::DATE);
            if ($dateCol === null) {
                $missing[] = $set('date_column') ? $profile['date_column'] : 'date';
            }
            foreach ($slots as $s) {
                $i = ColumnRef::find($headers, $s['column'], []);
                if ($i !== null && $i < max(count($headers), 1) + 50) {
                    $slotCols[] = ['index' => $i, 'dir' => $s['direction'], 'label' => $label($i)];
                }
            }
            if (!$slotCols && !$missing) {
                return self::fail('no_slot_columns');
            }
        } else {
            if ($set('timestamp_column')) {
                $ts = ColumnRef::find($headers, $profile['timestamp_column'], []);
                $ts === null && $missing[] = $profile['timestamp_column'];
            } elseif ($set('date_column') || $set('time_column')) {
                $dateCol = ColumnRef::find($headers, $profile['date_column'] ?? null, self::DATE);
                $timeCol = ColumnRef::find($headers, $profile['time_column'] ?? null, self::TIME);
                $dateCol === null && $missing[] = $set('date_column') ? $profile['date_column'] : 'date';
                $timeCol === null && $missing[] = $set('time_column') ? $profile['time_column'] : 'time';
            } else {
                $strong = ColumnRef::find($headers, null, self::TIMESTAMP);
                $d = ColumnRef::find($headers, null, self::DATE);
                $t = ColumnRef::find($headers, null, self::TIME);
                if ($strong !== null) {
                    $ts = $strong;
                } elseif ($d !== null && $t !== null) {
                    [$dateCol, $timeCol] = [$d, $t];
                } elseif ($t !== null) {
                    $ts = $t;
                } else {
                    $missing[] = 'timestamp';
                }
            }
            $dirCol = ColumnRef::find($headers, $profile['direction_column'] ?? null, self::DIRECTION);
            if ($set('direction_column') && $dirCol === null) {
                $missing[] = $profile['direction_column'];
            }
        }
        $columns = ['employee' => $label($emp), 'timestamp' => $label($ts), 'date' => $label($dateCol), 'time' => $label($timeCol), 'direction' => $label($dirCol),
            'slots' => implode(', ', array_map(fn ($s) => $s['label'] . ' (' . $s['dir'] . ')', $slotCols))];
        if ($missing) {
            return ['fatal' => ['key' => 'columns_missing', 'params' => ['columns' => implode(', ', array_unique($missing))]], 'columns' => $columns, 'punches' => [], 'rejected' => [], 'ignored' => 0, 'total' => 0];
        }

        $map = new DirectionMap($profile['in_values'] ?? null, $profile['out_values'] ?? null, $profile['ignore_values'] ?? null);
        $punches = $rejected = [];
        $ignored = $total = 0;
        for ($i = $headerRow; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (!array_filter($row, fn ($c) => CellParser::text($c) !== '')) {
                continue;
            }
            $line = $i + 1;
            $total++;
            $ref = CellParser::text($row[$emp] ?? null);
            if ($ref === '') {
                $rejected[] = ['line' => $line, 'reason' => 'missing_employee'];
                continue;
            }
            if ($daily) {
                $date = CellParser::date($row[$dateCol] ?? null, $formats);
                if ($date === null) {
                    $rejected[] = ['line' => $line, 'reason' => 'bad_timestamp'];
                    continue;
                }
                foreach ($slotCols as $s) {
                    $cell = $row[$s['index']] ?? null;
                    if (CellParser::text($cell) === '') {
                        continue;
                    }
                    $sec = CellParser::timeOfDay($cell);
                    $sec === null ? $rejected[] = ['line' => $line, 'reason' => 'bad_timestamp'] : $punches[] = ['line' => $line, 'ref' => $ref, 'ts' => CellParser::timestamp($date, $sec, $tz), 'dir' => $s['dir']];
                }
                continue;
            }
            if ($ts !== null) {
                $stamp = CellParser::dateTime($row[$ts] ?? null, $tz, $formats);
            } else {
                $date = CellParser::date($row[$dateCol] ?? null, $formats);
                $sec = CellParser::timeOfDay($row[$timeCol] ?? null);
                $stamp = $date !== null && $sec !== null ? CellParser::timestamp($date, $sec, $tz) : null;
            }
            if ($stamp === null) {
                $rejected[] = ['line' => $line, 'reason' => 'bad_timestamp'];
                continue;
            }
            $dir = $dirCol === null ? null : $map->classify($row[$dirCol] ?? null);
            if ($dir === 'ignore') {
                $ignored++;
                continue;
            }
            if ($dir === 'invalid') {
                $rejected[] = ['line' => $line, 'reason' => 'bad_direction'];
                continue;
            }
            $punches[] = ['line' => $line, 'ref' => $ref, 'ts' => $stamp, 'dir' => $dir];
        }
        return ['fatal' => null, 'columns' => $columns, 'punches' => $punches, 'rejected' => $rejected, 'ignored' => $ignored, 'total' => $total];
    }

    private static function fail(string $key): array
    {
        return ['fatal' => ['key' => $key, 'params' => []], 'columns' => [], 'punches' => [], 'rejected' => [], 'ignored' => 0, 'total' => 0];
    }
}
