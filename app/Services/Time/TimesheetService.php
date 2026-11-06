<?php

namespace App\Services\Time;

use App\Models\Employee;
use App\Models\TimeEntry;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\Approvals\ApprovalEngine;
use App\Services\AuditLogger;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Weekly timesheets (Monday-Sunday in the organization's timezone), approved through the Phase 0 approval engine. */
class TimesheetService
{
    public function __construct(private ApprovalEngine $approvals) {}

    private function fail(string $key): never
    {
        throw ValidationException::withMessages(['timesheet' => [__("time.timesheet.$key")]]);
    }

    public function week(User $u, string $anyDate): Timesheet
    {
        $weekStart = TimerMath::weekStart($anyDate);

        return Timesheet::query()->createOrFirst(
            [
                'organization_id' => $u->organization_id,
                'user_id'        => $u->id,
                'week_start'     => $weekStart,
            ],
            [
                'status_id' => Lookup::id('timesheet_status', 'draft'),
            ]
        );
    }



    public function submit(User $u, string $anyDate): Timesheet
    {
        $ts = $this->week($u, $anyDate);
        return DB::transaction(function () use ($ts, $u) {
            $ts = Timesheet::lockForUpdate()->with('status')->findOrFail($ts->id);
            if ($ts->status->code !== 'draft') $this->fail('not_draft');
            $start = $ts->week_start->toDateString();
            $end = date('Y-m-d', strtotime($start . ' +6 days'));
            $entries = TimeEntry::where('user_id', $u->id)->whereBetween('work_date', [$start, $end])->with('status')->get();
            if ($entries->contains(fn($e) => $e->ended_at === null)) $this->fail('timer_open');
            $draft = $entries->filter(fn($e) => $e->status->code === 'draft');
            if ($draft->isEmpty()) $this->fail('empty');
            if ($draft->contains(fn($e) => $e->auto_stopped)) $this->fail('needs_correction');
            $total = (int) $draft->sum('duration_seconds');
            if ($total < 0) $this->fail('negative');

            $submitted = Lookup::id('time_entry_status', 'submitted');
            foreach ($draft as $e) $e->update(['status_id' => $submitted, 'timesheet_id' => $ts->id]);
            $name = $u->name;
            $req = $this->approvals->submit(
                'timesheet',
                $ts->id,
                $u,
                sprintf('Timesheet %s %s (%s h)', $name, $start, number_format($total / 3600, 2, '.', '')),
                ['department_id' => Employee::where('user_id', $u->id)->value('department_id')]
            );
            $ts->update(['status_id' => Lookup::id('timesheet_status', 'submitted'), 'total_seconds' => $total, 'approval_request_id' => $req->id, 'submitted_at' => now()]);
            AuditLogger::log('timesheet.submitted', $ts, [], ['week' => $start, 'seconds' => $total]);
            return $ts->refresh();
        });
    }

    /** Applied by the approval handler. approved => entries locked; anything else => back to draft so the owner can fix and resubmit. */
    public function applyOutcome(string $timesheetId, bool $approved, string $note): void
    {
        DB::transaction(function () use ($timesheetId, $approved, $note) {
            $ts = Timesheet::lockForUpdate()->with('status')->find($timesheetId);
            if (!$ts || $ts->status->code !== 'submitted') return;
            $entries = TimeEntry::where('timesheet_id', $ts->id)->get();
            if ($approved) {
                $ok = Lookup::id('time_entry_status', 'approved');
                foreach ($entries as $e) $e->update(['status_id' => $ok, 'approved_at' => now()]);
                $ts->update(['status_id' => Lookup::id('timesheet_status', 'approved'), 'approved_at' => now()]);
            } else {
                $draft = Lookup::id('time_entry_status', 'draft');
                foreach ($entries as $e) $e->update(['status_id' => $draft, 'timesheet_id' => null]);
                $ts->update(['status_id' => Lookup::id('timesheet_status', 'draft'), 'total_seconds' => 0, 'submitted_at' => null]);
            }
            AuditLogger::log($approved ? 'timesheet.approved' : 'timesheet.reopened', $ts, [], ['note' => $note]);
        });
    }
}
