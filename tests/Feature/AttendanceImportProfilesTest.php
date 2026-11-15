<?php

namespace Tests\Feature;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Employee;
use App\Models\User;
use App\Services\Hr\Attendance\AttendanceCalculator;
use App\Services\Hr\Attendance\AttendanceService;
use App\Support\Lookup;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Not executed in the build environment (no Laravel runtime) - run `php artisan test`. Needs bundle 16a. Excel fixtures are in tests/fixtures/attendance. */
class AttendanceImportProfilesTest extends TestCase
{
    use RefreshDatabase, CopsTestHelpers;

    private string $tz;
    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCops();
        AttendanceService::forgetTimezones();
        $this->tz = AttendanceService::tz($this->org->id);
        $this->hr = $this->makeUser('hr_manager');
        $this->at('2026-11-12', '10:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(string $date, string $time): void
    {
        Carbon::setTestNow(CarbonImmutable::createFromTimestampUTC(AttendanceCalculator::localToUtc($this->tz, $date, $time)));
    }

    private function person(string $role = 'staff'): array
    {
        $u = $this->makeUser($role);
        $e = Employee::create(['first_name' => ucfirst($role), 'last_name' => 'Tester', 'category_id' => Lookup::id('employee_category', 'contracted'),
            'status_id' => Lookup::id('employee_status', 'active'), 'user_id' => $u->id, 'hired_on' => '2026-01-05']);
        return [$u, $e];
    }

    /** Monday-Friday 08:00-17:00 from 1 November, assigned to the employee. */
    private function workweek(Employee $e): void
    {
        Sanctum::actingAs($this->hr);
        $shift = $this->postJson('/api/v1/attendance/shifts', ['name' => 'Day', 'start_time' => '08:00', 'end_time' => '17:00', 'break_minutes' => 60, 'grace_minutes' => 10])->assertCreated()->json('data.id');
        $sched = $this->postJson('/api/v1/attendance/schedules', ['name' => 'Office week', 'days' => array_map(fn ($d) => ['weekday' => $d, 'shift_id' => $shift], [1, 2, 3, 4, 5])])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/attendance/schedule-assignments', ['schedule_id' => $sched, 'effective_from' => '2026-11-01', 'employee_ids' => [$e->id]])->assertCreated();
    }

    private function fixture(string $name): UploadedFile
    {
        return new UploadedFile(base_path('tests/fixtures/attendance/' . $name), $name, null, null, true);
    }

    private function csv(string $content, string $name = 'punches.csv'): UploadedFile { return UploadedFile::fake()->createWithContent($name, $content); }

    private function profile(string $name): string
    {
        return collect($this->getJson('/api/v1/attendance/import-profiles')->assertOk()->json('data'))->firstWhere('name', $name)['id'];
    }

    private function upload(string $url, UploadedFile $file, array $extra = [])
    {
        return $this->post($url, ['file' => $file] + $extra, ['Accept' => 'application/json']);
    }

    private function day(Employee $e, string $date): ?AttendanceDay
    {
        return AttendanceDay::where('employee_id', $e->id)->whereDate('work_date', $date)->first();
    }

    private function mapCode(Employee $e, string $code): void
    {
        $this->postJson('/api/v1/attendance/device-codes', ['codes' => [['employee_id' => $e->id, 'code' => $code]]])->assertCreated();
    }

    public function test_an_excel_file_from_a_device_unknown_codes_are_listed_then_mapped(): void
    {
        [, $e] = $this->person();
        $this->workweek($e);
        $badge = $this->profile('Device badge number');

        $first = $this->upload('/api/v1/attendance/import', $this->fixture('punches_badge.xlsx'), ['profile_id' => $badge])->assertCreated()->json('data');
        $this->assertSame('xlsx', $first['file_type']);
        $this->assertSame(0, $first['rows_imported']);
        $this->assertSame(3, $first['rows_rejected']);
        $this->assertEqualsCanonicalizing(['101', '102'], $first['unknown_ids']);               // the numbers the device uses that nobody is mapped to yet

        $this->mapCode($e, '101');
        $this->postJson('/api/v1/attendance/device-codes', ['codes' => [['employee_id' => $e->id, 'code' => '101']]])->assertStatus(422);   // a code belongs to one person
        $second = $this->upload('/api/v1/attendance/import', $this->fixture('punches_badge.xlsx'), ['profile_id' => $badge])->assertCreated()->json('data');
        $this->assertSame(2, $second['rows_imported']);
        $this->assertSame(1, $second['rows_rejected']);
        $this->assertSame(['102'], $second['unknown_ids']);
        $d = $this->day($e, '2026-11-09');
        $this->assertSame(537, $d->worked_minutes);                                             // 08:03:00 to 17:00:30, read from real Excel date and time cells
        $this->assertSame('present', $d->status->code);

        $again = $this->upload('/api/v1/attendance/import', $this->fixture('punches_badge.xlsx'), ['profile_id' => $badge])->assertCreated()->json('data');
        $this->assertSame(0, $again['rows_imported']);                                          // importing the same file twice changes nothing
        $this->assertSame(2, AttendanceEvent::where('employee_id', $e->id)->count());
    }

    public function test_the_automatic_profile_also_finds_device_codes(): void
    {
        [, $e] = $this->person();
        $this->workweek($e);
        $this->mapCode($e, '101');
        $r = $this->upload('/api/v1/attendance/import', $this->fixture('punches_badge.xlsx'))->assertCreated()->json('data');      // no profile chosen
        $this->assertSame(2, $r['rows_imported']);
        $this->assertSame('Badge', $r['columns']['employee']);
    }

    public function test_a_preview_shows_what_would_happen_and_changes_nothing(): void
    {
        [, $e] = $this->person();
        $this->workweek($e);
        $this->mapCode($e, '101');
        $r = $this->upload('/api/v1/attendance/import/preview', $this->fixture('punches_badge.xlsx'), ['profile_id' => $this->profile('Device badge number')])->assertOk()->json('data');
        $this->assertTrue($r['dry_run']);
        $this->assertSame(2, $r['rows_imported']);
        $this->assertCount(2, $r['sample']);
        $this->assertSame('in', $r['sample'][0]['direction']);
        $this->assertStringStartsWith('2026-11-09 08:03:00', $r['sample'][0]['at']);              // shown in the organization's own time
        $this->assertSame(0, AttendanceEvent::count());
        $this->assertNull($r['import_id']);
    }

    public function test_old_excel_and_html_tables_import_and_agree_with_each_other(): void
    {
        [, $e] = $this->person();
        $this->workweek($e);
        $this->mapCode($e, '101');
        $xls = $this->upload('/api/v1/attendance/import', $this->fixture('log.xls'))->assertCreated()->json('data');
        $this->assertSame('xls', $xls['file_type']);
        $this->assertSame(2, $xls['rows_imported']);
        $this->assertSame(537, $this->day($e, '2026-11-09')->worked_minutes);

        $html = $this->upload('/api/v1/attendance/import', $this->fixture('device_report.xls'))->assertCreated()->json('data');       // an HTML table with an .xls name
        $this->assertSame('html', $html['file_type']);
        $this->assertSame(0, $html['rows_imported']);
        $this->assertSame(2, $html['rows_skipped']);                                             // the same punches as the .xls: nothing is duplicated
    }

    public function test_a_custom_profile_for_a_device_with_its_own_words_dates_and_columns(): void
    {
        [, $e] = $this->person();
        $this->workweek($e);
        $body = ['name' => 'Front desk terminal', 'layout' => 'punches', 'header_row' => 1, 'identify_by' => 'employee_number', 'employee_column' => 'User', 'timestamp_column' => 'When',
            'direction_column' => 'What', 'in_values' => 'Entree', 'out_values' => 'Sortie', 'ignore_values' => 'Pause', 'date_formats' => 'd/m/Y H:i'];
        $pid = $this->postJson('/api/v1/attendance/import-profiles', $body)->assertCreated()->assertJsonPath('data.is_system', false)->json('data.id');
        $this->postJson('/api/v1/attendance/import-profiles', $body)->assertStatus(422);               // names are unique
        $this->postJson('/api/v1/attendance/import-profiles', ['name' => 'Bad', 'date_formats' => 'nonsense'])->assertStatus(422);
        $n = $e->employee_number;
        $r = $this->upload('/api/v1/attendance/import', $this->csv("User,When,What\n$n,09/11/2026 08:00,Entree\n$n,09/11/2026 12:00,Pause\n$n,09/11/2026 17:00,Sortie\n"), ['profile_id' => $pid])->assertCreated()->json('data');
        $this->assertSame(2, $r['rows_imported']);
        $this->assertSame(1, $r['rows_skipped']);                                                // the "Pause" row is ignored on purpose
        $this->assertSame(540, $this->day($e, '2026-11-09')->worked_minutes);
        $this->upload('/api/v1/attendance/import', $this->csv("Matricule,Heure\n$n,09/11/2026 08:00\n"), ['profile_id' => $pid])->assertStatus(422);   // the profile's columns are missing

        $this->patchJson("/api/v1/attendance/import-profiles/$pid", ['in_values' => 'Entree,Arrivee'])->assertOk()->assertJsonPath('data.in_values', 'Entree,Arrivee');
        $this->deleteJson("/api/v1/attendance/import-profiles/$pid")->assertNoContent();
    }

    public function test_shipped_profiles_are_read_only_but_can_be_duplicated(): void
    {
        Sanctum::actingAs($this->hr);
        $names = collect($this->getJson('/api/v1/attendance/import-profiles')->assertOk()->json('data'));
        $this->assertTrue($names->pluck('name')->contains('Automatic'));
        $this->assertTrue($names->pluck('name')->contains('Numeric status 0 = in, 1 = out'));
        $sys = $this->profile('Numeric status 0 = in, 1 = out');
        $this->patchJson("/api/v1/attendance/import-profiles/$sys", ['in_values' => '9'])->assertStatus(422);
        $this->deleteJson("/api/v1/attendance/import-profiles/$sys")->assertStatus(422);
        $copy = $this->postJson("/api/v1/attendance/import-profiles/$sys/duplicate")->assertCreated()->assertJsonPath('data.is_system', false)->assertJsonPath('data.in_values', '0,3,4')->json('data.id');
        $this->patchJson("/api/v1/attendance/import-profiles/$copy", ['in_values' => '0'])->assertOk();
    }

    public function test_a_daily_report_with_title_rows_becomes_punches(): void
    {
        [, $e] = $this->person();
        $this->workweek($e);
        $this->mapCode($e, '101');
        $pid = $this->postJson('/api/v1/attendance/import-profiles', ['name' => 'Monthly report', 'layout' => 'daily', 'header_row' => 3, 'identify_by' => 'device_code',
            'slots' => [['column' => 'In 1', 'direction' => 'in'], ['column' => 'Out 1', 'direction' => 'out'], ['column' => 'In 2', 'direction' => 'in'], ['column' => 'Out 2', 'direction' => 'out']]])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/attendance/import-profiles', ['name' => 'No columns', 'layout' => 'daily'])->assertStatus(422);
        $r = $this->upload('/api/v1/attendance/import', $this->fixture('daily_report.xlsx'), ['profile_id' => $pid])->assertCreated()->json('data');
        $this->assertSame(6, $r['rows_imported']);
        $this->assertSame(480, $this->day($e, '2026-11-10')->worked_minutes);                    // 08:00-12:00 and 13:00-17:00
        $this->assertSame(495, $this->day($e, '2026-11-11')->worked_minutes);                    // 08:30-16:45
        $shipped = $this->upload('/api/v1/attendance/import/preview', $this->fixture('daily_report.xlsx'), ['profile_id' => $this->profile('Daily report with In / Out columns')]);
        $shipped->assertStatus(422);                                                              // the report has title lines: the shipped profile expects the header on row 1
    }

    public function test_inspecting_a_file_describes_its_sheets_and_columns(): void
    {
        Sanctum::actingAs($this->hr);
        $a = $this->upload('/api/v1/attendance/import/inspect', $this->fixture('punches_badge.xlsx'))->assertOk()->json('data');
        $this->assertSame('xlsx', $a['type']);
        $this->assertSame(['Punches', 'Notes'], $a['sheets']);
        $this->assertSame(1, $a['header_row']);
        $this->assertSame(['Badge', 'Date', 'Time', 'Status'], array_column($a['columns'], 'label'));
        $this->assertSame('A', $a['columns'][0]['letter']);
        $b = $this->upload('/api/v1/attendance/import/inspect', $this->fixture('daily_report.xlsx'))->assertOk()->json('data');
        $this->assertSame(3, $b['header_row']);                                                   // the title lines above the header are skipped
        $this->assertSame('In 1', $b['columns'][2]['label']);
        $this->upload('/api/v1/attendance/import/inspect', $this->fixture('punches_badge.xlsx'), ['sheet' => 'Missing'])->assertStatus(422);
        $this->upload('/api/v1/attendance/import/inspect', UploadedFile::fake()->createWithContent('virus.exe', 'x'))->assertStatus(422);
    }

    public function test_only_people_with_attendance_management_can_import_or_map_devices(): void
    {
        [$staff] = $this->person();
        Sanctum::actingAs($staff);
        $this->upload('/api/v1/attendance/import', $this->fixture('log.xls'))->assertForbidden();
        $this->getJson('/api/v1/attendance/import-profiles')->assertForbidden();
        $this->getJson('/api/v1/attendance/device-codes')->assertForbidden();
        $this->postJson('/api/v1/attendance/sites', ['name' => 'x', 'latitude' => 0, 'longitude' => 0, 'radius_meters' => 100])->assertForbidden();
    }

    public function test_geolocation_off_record_and_required(): void
    {
        [$staff, $e] = $this->person();
        $this->workweek($e);
        $this->at('2026-11-12', '08:00');
        Sanctum::actingAs($this->hr);
        $this->postJson('/api/v1/attendance/sites', ['name' => 'Head office', 'latitude' => -1.9441, 'longitude' => 30.0619, 'radius_meters' => 150])->assertCreated();
        $this->postJson('/api/v1/attendance/sites', ['name' => 'Bad', 'latitude' => 95, 'longitude' => 30, 'radius_meters' => 150])->assertStatus(422);
        $inside = ['latitude' => -1.9445, 'longitude' => 30.0622, 'accuracy' => 20];              // about 55 m from the site
        $far = ['latitude' => -1.9441, 'longitude' => 30.0709, 'accuracy' => 20];                // about 1 km away

        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/attendance/me/today')->assertJsonPath('data.geolocation', 'off');
        $this->postJson('/api/v1/attendance/me/clock', $far)->assertCreated();                    // off: nothing is asked and nothing is kept
        $this->assertNull(AttendanceEvent::where('employee_id', $e->id)->first()->latitude);

        $this->at('2026-11-12', '09:00');
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/hr/settings', ['geolocation_mode' => 1])->assertOk();
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/attendance/me/today')->assertJsonPath('data.geolocation', 'record');
        $this->postJson('/api/v1/attendance/me/clock', $far)->assertCreated();                    // record: allowed anywhere, the place is kept
        $ev = AttendanceEvent::where('employee_id', $e->id)->orderByDesc('occurred_at')->first();
        $this->assertEquals(-1.9441, (float) $ev->latitude);
        $this->assertNull($ev->site_id);

        $this->at('2026-11-12', '10:00');
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/hr/settings', ['geolocation_mode' => 2])->assertOk();
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/attendance/me/today')->assertJsonPath('data.geolocation', 'required');
        $this->postJson('/api/v1/attendance/me/clock')->assertStatus(422);                         // required: no location, no clock
        $this->postJson('/api/v1/attendance/me/clock', $far)->assertStatus(422);                   // required: outside every site
        $this->postJson('/api/v1/attendance/me/clock', ['accuracy' => 500] + $inside)->assertStatus(422);   // required: too imprecise to trust
        $this->postJson('/api/v1/attendance/me/clock', $inside)->assertCreated();
        $last = AttendanceEvent::where('employee_id', $e->id)->orderByDesc('occurred_at')->first();
        $this->assertNotNull($last->site_id);
        $this->assertSame(20, (int) $last->accuracy_m);
    }

    public function test_every_hr_setting_can_be_changed_within_its_range(): void
    {
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/hr/settings', ['overtime_min_minutes' => 30, 'punch_min_interval_seconds' => 60, 'applicant_retention_months' => 18])->assertOk();
        $byKey = collect($this->getJson('/api/v1/hr/settings')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame(30, $byKey['overtime_min_minutes']['value']);
        $this->assertSame(60, $byKey['punch_min_interval_seconds']['value']);
        $this->assertSame(18, $byKey['applicant_retention_months']['value']);
        $this->assertSame(0, $byKey['geolocation_mode']['value']);                                // the documented default
        $this->putJson('/api/v1/hr/settings', ['geolocation_mode' => 3])->assertStatus(422);       // out of range
        $this->putJson('/api/v1/hr/settings', ['pairing_window_hours' => 2])->assertStatus(422);
    }

    public function test_required_geolocation_without_any_site_does_not_lock_everybody_out(): void
    {
        [$staff, $e] = $this->person();
        $this->workweek($e);
        $this->at('2026-11-12', '08:00');
        Sanctum::actingAs($this->hr);
        $this->putJson('/api/v1/hr/settings', ['geolocation_mode' => 2])->assertOk();
        Sanctum::actingAs($staff);
        $this->getJson('/api/v1/attendance/me/today')->assertJsonPath('data.geolocation', 'record');   // nothing to enforce yet
        $this->postJson('/api/v1/attendance/me/clock')->assertCreated();
    }
}
