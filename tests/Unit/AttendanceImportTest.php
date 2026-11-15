<?php

namespace Tests\Unit;

use App\Services\Hr\Attendance\Import\CellParser;
use App\Services\Hr\Attendance\Import\ColumnRef;
use App\Services\Hr\Attendance\Import\DirectionMap;
use App\Services\Hr\Attendance\Import\Geo;
use App\Services\Hr\Attendance\Import\ImportFileException;
use App\Services\Hr\Attendance\Import\ImportMapper;
use App\Services\Hr\Attendance\Import\SpreadsheetReader;
use PHPUnit\Framework\TestCase;

class AttendanceImportTest extends TestCase
{
    private const TZ = 'Africa/Kigali';                          // UTC+2

    private function fixture(string $name): string { return __DIR__ . '/../fixtures/attendance/' . $name; }
    private function utc(int $ts): string { return gmdate('Y-m-d H:i:s', $ts); }
    private function map(array $rows, array $profile = [], array $slots = []): array { return ImportMapper::map($rows, $profile + ['header_row' => 1], $slots, self::TZ); }

    public function test_cells_dates_times_and_serials(): void
    {
        $this->assertSame('101', CellParser::text(101.0));
        $this->assertSame('EMP-1', CellParser::text('  EMP-1 '));
        $this->assertSame('employee number', CellParser::norm('Employee_Number'));
        $this->assertSame('2026-11-09', CellParser::serialDate(46335.0));
        $this->assertSame('1900-03-01', CellParser::serialDate(61.0));                      // after Excel's fictional 29 February 1900
        $this->assertNull(CellParser::serialDate(0.3354));                                  // a time of day has no date
        $this->assertSame(8 * 3600 + 3 * 60, CellParser::serialSeconds(0.3354166666666666)); // rounds to the nearest second: 08:03:00
        $this->assertSame(17 * 3600 + 30, CellParser::serialSeconds(0.7086805555555555));
        $this->assertSame(8 * 3600 + 15 * 60, CellParser::timeOfDay('08:15'));
        $this->assertSame(8 * 3600 + 15 * 60, CellParser::timeOfDay('8.15'));
        $this->assertSame(20 * 3600 + 5 * 60 + 9, CellParser::timeOfDay('8:05:09 PM'));
        $this->assertSame(0, CellParser::timeOfDay('12:00 AM'));
        $this->assertNull(CellParser::timeOfDay('25:00'));
        $this->assertSame('2026-11-09', CellParser::date('09/11/2026'));                    // day first by default
        $this->assertSame('2026-11-09', CellParser::date('2026-11-09 08:03:00'));
        $this->assertSame('2026-09-11', CellParser::date('09/11/2026', ['m/d/Y']));         // a profile can say otherwise
        $this->assertNull(CellParser::date('31/02/2026'));
    }

    public function test_date_time_cells_are_read_in_the_device_timezone(): void
    {
        $this->assertSame('2026-11-09 06:03:00', $this->utc(CellParser::dateTime('2026-11-09 08:03', self::TZ)));
        $this->assertSame('2026-11-09 06:03:00', $this->utc(CellParser::dateTime(46335.3354166666667, self::TZ)));      // an Excel date-time cell
        $this->assertSame('2026-11-09 06:03:00', $this->utc(CellParser::dateTime('09/11/2026 8:03 AM', self::TZ)));
        $this->assertSame('2026-11-09 08:03:00', $this->utc(CellParser::dateTime('2026-11-09T08:03:00Z', self::TZ)));    // an explicit offset wins over the timezone
        $this->assertSame('2026-11-09 06:03:00', $this->utc(CellParser::dateTime('09-11-2026 08:03', 'Africa/Kigali', ['d-m-Y H:i'])));
        $this->assertNull(CellParser::dateTime('not a date', self::TZ));
        $this->assertNull(CellParser::dateTime('', self::TZ));
    }

    public function test_columns_are_found_by_name_letter_or_position(): void
    {
        $h = array_map([CellParser::class, 'norm'], ['Badge No', 'Punch Time', 'Mode']);
        $this->assertSame(0, ColumnRef::find($h, 'badge no'));
        $this->assertSame(1, ColumnRef::find($h, 'B'));
        $this->assertSame(2, ColumnRef::find($h, '#3'));
        $this->assertNull(ColumnRef::find($h, 'Nope Here'));
        $this->assertSame(1, ColumnRef::find($h, null, ['timestamp', 'punch time']));
        $this->assertSame(26, ColumnRef::letterIndex('AA'));
        $this->assertSame('AB', ColumnRef::letter(27));
    }

    public function test_direction_words_numbers_and_ignored_values(): void
    {
        $d = new DirectionMap();
        $this->assertSame('in', $d->classify('C/In'));
        $this->assertSame('out', $d->classify('Sortie'));
        $this->assertSame('invalid', $d->classify('1'));                                     // numbers mean different things on different devices: never guessed
        $this->assertNull($d->classify('  '));
        $z = new DirectionMap('0, 3, 4', '1, 2, 5', '9');                                    // a device that writes numbers
        $this->assertSame('in', $z->classify(0.0));
        $this->assertSame('out', $z->classify('1'));
        $this->assertSame('ignore', $z->classify(9));
        $this->assertSame('invalid', $z->classify('Check-in'));                              // a profile's own lists replace the defaults
    }

    public function test_the_automatic_profile_recognises_common_headers(): void
    {
        $r = $this->map([['Employee Number', 'Timestamp', 'Direction'], ['EMP-1', '2026-11-09 08:03', 'IN'], ['EMP-1', '2026-11-09 17:00', 'out'], [null, null, null], ['EMP-2', 'xx', 'in'], ['', '2026-11-09 08:00', 'in'], ['EMP-3', '2026-11-09 08:00', 'maybe']]);
        $this->assertNull($r['fatal']);
        $this->assertSame(5, $r['total']);                                                    // the blank row does not count
        $this->assertSame(['in', 'out'], array_column($r['punches'], 'dir'));
        $this->assertSame('2026-11-09 06:03:00', $this->utc($r['punches'][0]['ts']));
        $this->assertSame([[ 'line' => 5, 'reason' => 'bad_timestamp'], ['line' => 6, 'reason' => 'missing_employee'], ['line' => 7, 'reason' => 'bad_direction']], $r['rejected']);
    }

    public function test_separate_date_and_time_columns_and_no_direction_column(): void
    {
        $r = $this->map([['Badge', 'Date', 'Time'], ['101', '2026-11-09', '08:03'], ['101', '2026-11-09', '17:00']]);
        $this->assertNull($r['fatal']);
        $this->assertSame('Date', $r['columns']['date']);
        $this->assertSame('Time', $r['columns']['time']);
        $this->assertSame([null, null], array_column($r['punches'], 'dir'));                 // worked out later: in, out, in...
        $this->assertSame('2026-11-09 15:00:00', $this->utc($r['punches'][1]['ts']));
        $only = $this->map([['Badge', 'Time'], ['101', '2026-11-09 08:03']]);
        $this->assertCount(1, $only['punches']);                                              // a lone "Time" column holds the whole date-time
    }

    public function test_explicit_columns_and_a_header_on_a_later_row(): void
    {
        $rows = [['Report'], [], ['User', 'When', 'What'], ['7', '09/11/2026 08:03', 'Entree'], ['7', '09/11/2026 17:00', 'Sortie'], ['7', '09/11/2026 12:00', 'Pause']];
        $r = $this->map($rows, ['header_row' => 3, 'employee_column' => 'User', 'timestamp_column' => 'When', 'direction_column' => 'What', 'ignore_values' => 'Pause']);
        $this->assertNull($r['fatal']);
        $this->assertSame(['in', 'out'], array_column($r['punches'], 'dir'));
        $this->assertSame(1, $r['ignored']);
        $this->assertSame(4, $r['punches'][0]['line']);
        $letters = $this->map([['x'], ['7', '2026-11-09 08:03']], ['header_row' => 0, 'employee_column' => 'A', 'timestamp_column' => 'B']);
        $this->assertCount(1, $letters['punches']);                                           // no header at all: columns by letter
        $bad = $this->map([['Badge', 'Time']], ['employee_column' => 'Matricule']);
        $this->assertSame('columns_missing', $bad['fatal']['key']);
        $this->assertSame('Matricule', $bad['fatal']['params']['columns']);
    }

    public function test_daily_layout_turns_each_time_cell_into_a_punch(): void
    {
        $rows = [['Badge', 'Date', 'In 1', 'Out 1', 'In 2', 'Out 2'], ['101', '2026-11-10', '08:00', '12:00', '13:00', '17:00'], ['101', '2026-11-11', '08:30', '16:45', '', ''], ['101', 'bad', '08:30', '', '', '']];
        $slots = [['column' => 'In 1', 'direction' => 'in'], ['column' => 'Out 1', 'direction' => 'out'], ['column' => 'In 2', 'direction' => 'in'], ['column' => 'Out 2', 'direction' => 'out'], ['column' => 'Overtime', 'direction' => 'in']];
        $r = $this->map($rows, ['layout' => 'daily'], $slots);
        $this->assertNull($r['fatal']);
        $this->assertSame(['in', 'out', 'in', 'out', 'in', 'out'], array_column($r['punches'], 'dir'));
        $this->assertSame('2026-11-10 11:00:00', $this->utc($r['punches'][2]['ts']));         // 13:00 local
        $this->assertSame([['line' => 4, 'reason' => 'bad_timestamp']], $r['rejected']);
        $none = $this->map($rows, ['layout' => 'daily'], [['column' => 'Nowhere', 'direction' => 'in']]);
        $this->assertSame('no_slot_columns', $none['fatal']['key']);
    }

    public function test_excel_files_are_read_whatever_their_format(): void
    {
        $reader = new SpreadsheetReader();
        $x = $reader->read($this->fixture('punches_badge.xlsx'));
        $this->assertSame('xlsx', $x['type']);
        $this->assertSame(['Punches', 'Notes'], $x['sheets']);
        $this->assertSame(['Badge', 'Date', 'Time', 'Status'], $x['rows'][0]);
        $r = ImportMapper::map($x['rows'], ['header_row' => 1], [], self::TZ);
        $this->assertNull($r['fatal']);
        $this->assertSame(['101', '101', '102'], array_column($r['punches'], 'ref'));
        $this->assertSame(['in', 'out', 'in'], array_column($r['punches'], 'dir'));          // C/In and C/Out are understood
        $this->assertSame('2026-11-09 06:03:00', $this->utc($r['punches'][0]['ts']));        // real Excel date + time cells
        $this->assertSame('2026-11-09 15:00:30', $this->utc($r['punches'][1]['ts']));
        $this->assertSame('2026-11-09 06:15:00', $this->utc($r['punches'][2]['ts']));        // the same data written as text

        $old = $reader->read($this->fixture('log.xls'));
        $this->assertSame('xls', $old['type']);
        $o = ImportMapper::map($old['rows'], ['header_row' => 1], [], self::TZ);
        $this->assertSame(['2026-11-09 06:03:00', '2026-11-09 15:00:00'], array_map(fn ($p) => $this->utc($p['ts']), $o['punches']));
        $this->assertSame(['101', '101'], array_column($o['punches'], 'ref'));

        $html = $reader->read($this->fixture('device_report.xls'));                          // an HTML table with an .xls name
        $this->assertSame('html', $html['type']);
        $h = ImportMapper::map($html['rows'], ['header_row' => 1], [], self::TZ);
        $this->assertSame(['in', 'out'], array_column($h['punches'], 'dir'));

        $other = $reader->read($this->fixture('punches_badge.xlsx'), 'Notes');
        $this->assertSame([['not attendance']], $other['rows']);
        try { $reader->read($this->fixture('punches_badge.xlsx'), 'Missing'); $this->fail('an unknown sheet must be refused'); } catch (ImportFileException $e) { $this->assertSame('sheet_not_found', $e->key); }
    }

    public function test_daily_report_with_title_rows_and_text_files(): void
    {
        $reader = new SpreadsheetReader();
        $d = $reader->read($this->fixture('daily_report.xlsx'));
        $slots = [['column' => 'In 1', 'direction' => 'in'], ['column' => 'Out 1', 'direction' => 'out'], ['column' => 'In 2', 'direction' => 'in'], ['column' => 'Out 2', 'direction' => 'out']];
        $r = ImportMapper::map($d['rows'], ['layout' => 'daily', 'header_row' => 3], $slots, self::TZ);
        $this->assertNull($r['fatal']);
        $this->assertCount(6, $r['punches']);
        $this->assertSame('2026-11-10 06:00:00', $this->utc($r['punches'][0]['ts']));

        $tmp = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($tmp, "\xEF\xBB\xBFBadge;Time;Direction\r\n101;2026-11-09 08:03;in\r\n101;2026-11-09 17:00;out\r\n");        // BOM, semicolons, Windows line ends
        $c = $reader->read($tmp);
        $this->assertSame('csv', $c['type']);
        $this->assertSame(['Badge', 'Time', 'Direction'], $c['rows'][0]);
        file_put_contents($tmp, "Badge\tTime\n101\t2026-11-09 08:03\n");
        $this->assertSame('101', $reader->read($tmp)['rows'][1][0]);
        file_put_contents($tmp, "Matricule,Heure\n1,caf\xE9\n");                                     // Windows-1252 text
        $this->assertSame('café', $reader->read($tmp)['rows'][1][1]);
        file_put_contents($tmp, '');
        try { $reader->read($tmp); $this->fail('an empty file must be refused'); } catch (ImportFileException $e) { $this->assertSame('import_empty', $e->key); }
        unlink($tmp);
    }

    public function test_distance_and_site_check(): void
    {
        $this->assertTrue(abs(Geo::distanceMeters(-1.9441, 30.0619, -1.9441, 30.0619)) < 0.001);
        $d = Geo::distanceMeters(-1.9441, 30.0619, -1.9441, 30.0709);                       // about 0.009 degrees of longitude at the equator
        $this->assertTrue($d > 950 && $d < 1050, "distance was $d");
        $site = (object) ['id' => 's1', 'latitude' => '-1.944100', 'longitude' => '30.061900', 'radius_meters' => 150];
        $this->assertSame('s1', Geo::insideSite(-1.9445, 30.0622, [$site])?->id);          // about 55 m away
        $this->assertNull(Geo::insideSite(-1.9441, 30.0709, [$site]));
        $this->assertTrue(Geo::validCoordinates('-1.9', '30.0'));
        $this->assertFalse(Geo::validCoordinates('91', '0'));
        $this->assertFalse(Geo::validCoordinates('abc', '0'));
    }
}
