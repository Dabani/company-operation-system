<?php

namespace App\Services\Time;

use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\TimeEntrySegment;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Notifier;
use App\Services\OrganizationContext;
use App\Services\Projects\ProjectAccess;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persistent server-side timer and manual time. Start / pause / resume / stop are stored as segments with UTC timestamps,
 * so elapsed time survives closing the browser, switching device or losing the network (Guide 4.6).
 */
class TimeService
{
    public function __construct(private ProjectAccess $access, private OrganizationContext $ctx) {}

    private function now(): string { return gmdate('Y-m-d H:i:s'); }
    private function orgId(): string { return $this->ctx->organizationId(); }
    private function setting(string $key) { return SettingsService::get($this->orgId(), $key); }
    private function fail(string $field, string $key): never { throw ValidationException::withMessages([$field => [__("time.$key")]]); }

    /** Local calendar date of a UTC timestamp in the organization's timezone. */
    public function workDate(string $utc): string
    {
        $tz = $this->ctx->organization()?->timezone?->code ?: 'UTC';
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone($tz))->format('Y-m-d');
    }

    public function current(User $u): ?TimeEntry
    {
        return TimeEntry::where('user_id', $u->id)->whereNull('ended_at')->with(['project:id,code,name', 'task:id,number,title,project_id', 'segments'])->first();
    }

    // ---- timer -------------------------------------------------------------------------------------------------------------
    public function start(User $u, string $projectId, ?string $taskId, ?string $description, ?bool $billable, bool $stopExisting): TimeEntry
    {
        [$project, $task] = $this->target($u, $projectId, $taskId);
        return DB::transaction(function () use ($u, $project, $task, $description, $billable, $stopExisting) {
            $open = TimeEntry::where('user_id', $u->id)->whereNull('ended_at')->lockForUpdate()->first();
            if ($open) {
                if ($this->setting('time.single_timer') && !$stopExisting) $this->fail('timer', 'timer.already_running');
                $this->setting('time.single_timer') ? $this->stop($u) : $this->pause($u);
            }
            $now = $this->now();
            $this->assertWeekOpen($u, $this->workDate($now));
            $e = TimeEntry::create([
                'user_id' => $u->id, 'project_id' => $project->id, 'task_id' => $task?->id, 'work_date' => $this->workDate($now), 'started_at' => $now,
                'is_billable' => ($billable ?? true) && ($task?->is_billable ?? true), 'description' => $description,
                'status_id' => Lookup::id('time_entry_status', 'draft'),
            ]);
            TimeEntrySegment::create(['time_entry_id' => $e->id, 'user_id' => $u->id, 'started_at' => $now]);
            return $e->load(['project:id,code,name', 'task:id,number,title,project_id', 'segments']);
        });
    }

    public function pause(User $u): TimeEntry
    {
        return DB::transaction(function () use ($u) {
            $e = TimeEntry::where('user_id', $u->id)->whereNull('ended_at')->lockForUpdate()->first() ?? $this->fail('timer', 'timer.none');
            $seg = TimeEntrySegment::where('time_entry_id', $e->id)->whereNull('ended_at')->first() ?? $this->fail('timer', 'timer.already_paused');
            $seg->update(['ended_at' => $this->now()]);
            return $e->load('segments');
        });
    }

    public function resume(User $u): TimeEntry
    {
        return DB::transaction(function () use ($u) {
            $e = TimeEntry::where('user_id', $u->id)->whereNull('ended_at')->lockForUpdate()->first() ?? $this->fail('timer', 'timer.none');
            if (TimeEntrySegment::where('time_entry_id', $e->id)->whereNull('ended_at')->exists()) $this->fail('timer', 'timer.already_running');
            $now = $this->now();
            $this->assertNoOverlap($u, $now, null, $e->id);
            TimeEntrySegment::create(['time_entry_id' => $e->id, 'user_id' => $u->id, 'started_at' => $now]);
            return $e->load('segments');
        });
    }

    /** Close the timer. A zero-length entry is discarded and null is returned. */
    public function stop(User $u, bool $auto = false, ?string $at = null): ?TimeEntry
    {
        return DB::transaction(function () use ($u, $auto, $at) {
            $e = TimeEntry::where('user_id', $u->id)->whereNull('ended_at')->lockForUpdate()->first() ?? $this->fail('timer', 'timer.none');
            $end = $at ?? $this->now();
            TimeEntrySegment::where('time_entry_id', $e->id)->whereNull('ended_at')->update(['ended_at' => $end]);
            $segs = TimeEntrySegment::where('time_entry_id', $e->id)->orderBy('started_at')->get();
            $seconds = TimerMath::seconds($segs->map(fn ($s) => ['start' => $s->started_at->format('Y-m-d H:i:s'), 'end' => $s->ended_at->format('Y-m-d H:i:s')])->all(), $end);
            if ($seconds <= 0) {
                $e->delete();
                return null;
            }
            $e->update(['ended_at' => $segs->max('ended_at'), 'duration_seconds' => $seconds, 'auto_stopped' => $auto]);
            AuditLogger::log($auto ? 'time.auto_stopped' : 'time.stopped', $e, [], ['seconds' => $seconds]);
            return $e->refresh();
        });
    }

    /** Cut off timers that exceeded the allowed duration and flag them for correction (scheduler). */
    public function autoStopDue(): int
    {
        $max = (int) $this->setting('time.max_hours') * 3600;
        $now = $this->now();
        $n = 0;
        foreach (TimeEntry::whereNull('ended_at')->with('segments')->get() as $e) {
            $segs = $e->segments->map(fn ($s) => ['start' => $s->started_at->format('Y-m-d H:i:s'), 'end' => $s->ended_at?->format('Y-m-d H:i:s')])->all();
            $cut = TimerMath::autoStopAt($segs, $max, $now);
            if ($cut === null) continue;
            $user = User::find($e->user_id);
            $this->stop($user, true, $cut);
            Notifier::send($user, 'timer_auto_stopped', ['title' => $e->project?->code ?? '', 'hours' => $max / 3600], null, ['in_app', 'email'], '/time', $e->organization_id);
            $n++;
        }
        return $n;
    }

    // ---- manual entries, edits, adjustments ----------------------------------------------------------------------------------
    public function manual(User $u, array $d): TimeEntry
    {
        if (!$this->setting('time.manual_allowed')) $this->fail('started_at', 'manual.disabled');
        [$project, $task] = $this->target($u, $d['project_id'], $d['task_id'] ?? null);
        $this->assertWindow($d['started_at'], $d['ended_at']);
        $this->assertNoOverlap($u, $d['started_at'], $d['ended_at']);
        $workDate = $this->workDate($d['started_at']);
        $this->assertWeekOpen($u, $workDate);
        return DB::transaction(function () use ($u, $d, $project, $task, $workDate) {
            $secs = TimerMath::ts($d['ended_at']) - TimerMath::ts($d['started_at']);
            $e = TimeEntry::create([
                'user_id' => $u->id, 'project_id' => $project->id, 'task_id' => $task?->id, 'work_date' => $workDate, 'started_at' => $d['started_at'], 'ended_at' => $d['ended_at'],
                'duration_seconds' => $secs, 'is_manual' => true, 'manual_reason' => $d['reason'], 'is_billable' => ($d['is_billable'] ?? true) && ($task?->is_billable ?? true),
                'description' => $d['description'] ?? null, 'status_id' => Lookup::id('time_entry_status', 'draft'),
            ]);
            TimeEntrySegment::create(['time_entry_id' => $e->id, 'user_id' => $u->id, 'started_at' => $d['started_at'], 'ended_at' => $d['ended_at']]);
            AuditLogger::log('time.manual_created', $e, [], ['seconds' => $secs, 'reason' => $d['reason']]);
            return $e;
        });
    }

    public function update(TimeEntry $e, User $u, array $d): TimeEntry
    {
        abort_unless($e->user_id === $u->id, 404);
        if ($e->status->code !== 'draft' || $e->ended_at === null) $this->fail('entry', 'entry.locked');
        return DB::transaction(function () use ($e, $u, $d) {
            $old = $e->only(['description', 'is_billable', 'task_id', 'started_at', 'ended_at']);
            if (array_key_exists('task_id', $d) && $d['task_id'] !== $e->task_id) {
                [, $task] = $this->target($u, $e->project_id, $d['task_id']);
                $e->task_id = $task?->id;
            }
            foreach (['description'] as $f) if (array_key_exists($f, $d)) $e->{$f} = $d[$f];
            if (array_key_exists('is_billable', $d)) $e->is_billable = $d['is_billable'] && ($e->task?->is_billable ?? true);
            if (isset($d['started_at'], $d['ended_at'])) {
                if (!$e->is_manual && !$e->auto_stopped) $this->fail('started_at', 'entry.times_fixed');       // recorded by the timer: only correct if flagged
                if (trim((string) ($d['reason'] ?? '')) === '') $this->fail('reason', 'manual.reason_required');
                $this->assertWindow($d['started_at'], $d['ended_at']);
                $this->assertNoOverlap($u, $d['started_at'], $d['ended_at'], $e->id);
                $this->assertWeekOpen($u, $this->workDate($d['started_at']));
                $e->fill(['started_at' => $d['started_at'], 'ended_at' => $d['ended_at'], 'work_date' => $this->workDate($d['started_at']),
                    'duration_seconds' => TimerMath::ts($d['ended_at']) - TimerMath::ts($d['started_at']), 'is_manual' => true, 'manual_reason' => $d['reason'], 'auto_stopped' => false]);
                TimeEntrySegment::where('time_entry_id', $e->id)->delete();
                TimeEntrySegment::create(['time_entry_id' => $e->id, 'user_id' => $u->id, 'started_at' => $d['started_at'], 'ended_at' => $d['ended_at']]);
            }
            $e->save();
            AuditLogger::log('time.updated', $e, $old, collect($d)->except('reason')->all());
            return $e->refresh();
        });
    }

    public function delete(TimeEntry $e, User $u): void
    {
        abort_unless($e->user_id === $u->id, 404);
        if ($e->status->code !== 'draft' || $e->ended_at === null) $this->fail('entry', 'entry.locked');
        $e->delete();
        AuditLogger::log('time.deleted', $e);
    }

    /** Corrections to approved/invoiced time are signed adjustment entries with a reason; they follow the normal timesheet approval. */
    public function adjust(TimeEntry $orig, int $seconds, string $reason, User $by, ?string $date): TimeEntry
    {
        if (!in_array($orig->status->code, ['approved', 'invoiced'], true)) $this->fail('entry', 'adjust.only_locked');
        if ($seconds === 0 || abs($seconds) > 86400) $this->fail('seconds', 'adjust.range');
        if (trim($reason) === '') $this->fail('reason', 'manual.reason_required');
        $net = (int) TimeEntry::where('id', $orig->id)->orWhere('adjusts_entry_id', $orig->id)->sum('duration_seconds');
        if ($net + $seconds < 0) $this->fail('seconds', 'adjust.below_zero');
        $now = $this->now();
        $workDate = $date ?? $this->workDate($now);
        $this->assertWeekOpen(User::findOrFail($orig->user_id), $workDate);
        $e = TimeEntry::create([
            'user_id' => $orig->user_id, 'project_id' => $orig->project_id, 'task_id' => $orig->task_id, 'work_date' => $workDate, 'started_at' => $now, 'ended_at' => $now,
            'duration_seconds' => $seconds, 'is_manual' => true, 'manual_reason' => $reason, 'is_billable' => $orig->is_billable,
            'description' => $orig->description, 'adjusts_entry_id' => $orig->id, 'status_id' => Lookup::id('time_entry_status', 'draft'),
        ]);
        AuditLogger::log('time.adjusted', $e, [], ['original' => $orig->id, 'seconds' => $seconds, 'by' => $by->id, 'reason' => $reason]);
        return $e;
    }

    // ---- guards --------------------------------------------------------------------------------------------------------------
    /** Returns [Project, ?Task]; the user must be able to edit the project and the project/task must still accept time. */
    private function target(User $u, string $projectId, ?string $taskId): array
    {
        [$p, $level] = $this->access->find($u, $projectId);
        if (!ProjectAccess::canEdit($level)) abort(403, 'forbidden');
        if (in_array($p->status->code, ['completed', 'cancelled', 'delivered', 'closed'], true)) $this->fail('project_id', 'entry.project_closed');
        $task = null;
        if ($taskId) {
            $task = Task::where('project_id', $p->id)->find($taskId) ?? $this->fail('task_id', 'entry.task_invalid');
            if ($task->status->code === 'cancelled') $this->fail('task_id', 'entry.task_invalid');
        }
        return [$p, $task];
    }

    private function assertWindow(string $start, string $end): void
    {
        $s = TimerMath::ts($start); $e = TimerMath::ts($end); $now = time();
        if ($e <= $s) $this->fail('ended_at', 'manual.end_before_start');
        if ($e - $s > 86400) $this->fail('ended_at', 'manual.too_long');
        if ($e > $now + 60) $this->fail('ended_at', 'manual.future');
        if ($s < $now - (int) $this->setting('time.manual_max_days_back') * 86400) $this->fail('started_at', 'manual.too_old');
    }

    /** Reject overlapping time for the same person (open segments count up to now). */
    private function assertNoOverlap(User $u, string $start, ?string $end, ?string $exceptEntry = null): void
    {
        $now = $this->now();
        $segs = TimeEntrySegment::where('user_id', $u->id)->when($exceptEntry, fn ($q) => $q->where('time_entry_id', '!=', $exceptEntry))
            ->where('started_at', '<', $end ?? '9999-12-31 00:00:00')->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $start))->get();
        foreach ($segs as $s) {
            if (TimerMath::overlaps(['start' => $start, 'end' => $end], ['start' => $s->started_at->format('Y-m-d H:i:s'), 'end' => $s->ended_at?->format('Y-m-d H:i:s')], $now)) {
                $this->fail('started_at', 'entry.overlap');
            }
        }
    }

    /** Time cannot be added to a week whose timesheet is submitted or approved (use an adjustment instead). */
    private function assertWeekOpen(User $u, string $workDate): void
    {
        $ts = Timesheet::where('user_id', $u->id)->where('week_start', TimerMath::weekStart($workDate))->first();
        if ($ts && $ts->status->code !== 'draft') $this->fail('started_at', 'entry.week_locked');
    }
}
