<?php

namespace App\Services\Events;

use App\Mail\NotificationMail;
use App\Models\Event;
use App\Models\EventAttendance;
use App\Models\EventRegistration;
use App\Models\EventStatusHistory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Notifier;
use App\Support\Html;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Events (Appendix A.4): lifecycle, registration with capacity and waitlist, check-in, notices. */
class EventService
{
    private function fail(string $field, string $key, array $p = []): never
    {
        throw ValidationException::withMessages([$field => [__("events.$key", $p)]]);
    }

    private function st(string $code): string { return Lookup::id('event_status', $code); }
    private function rst(string $code): string { return Lookup::id('event_registration_status', $code); }

    private function history(Event $e, ?string $from, string $to, ?User $by, ?string $note): void
    {
        EventStatusHistory::create(['organization_id' => $e->organization_id, 'event_id' => $e->id, 'from_status_id' => $from, 'to_status_id' => $to, 'changed_by' => $by?->id, 'note' => $note]);
    }

    // ---- the event --------------------------------------------------------------------------------------------------------------
    public function create(array $d, User $by): Event
    {
        $this->checkWindow($d);
        return DB::transaction(function () use ($d, $by) {
            $e = Event::create($this->clean($d) + ['status_id' => $this->st('draft'), 'organizer_id' => $d['organizer_id'] ?? $by->id, 'public_token' => Str::random(48)]);
            $this->history($e, null, $e->status_id, $by, null);
            AuditLogger::log('event.created', $e, [], ['code' => $e->code, 'title' => $e->title]);
            return $e;
        });
    }

    public function update(Event $e, array $d, User $by): Event
    {
        $e->loadMissing('status');
        if (!EventRules::editable($e->status->code)) {
            $this->fail('status', 'event_not_editable');
        }
        $this->checkWindow($d + ['starts_at' => $e->starts_at, 'ends_at' => $e->ends_at]);
        if (array_key_exists('capacity', $d) && $d['capacity'] !== null && $d['capacity'] < $this->taken($e)) {
            $this->fail('capacity', 'event_capacity_below_registered', ['n' => $this->taken($e)]);
        }
        $old = $e->only(['title', 'starts_at', 'ends_at', 'venue', 'capacity']);
        $e->update($this->clean($d));
        AuditLogger::log('event.updated', $e, array_map(fn ($v) => (string) $v, $old), array_map(fn ($v) => (string) $v, $e->only(array_keys($old))));
        $this->promoteWaitlist($e);                                 // a bigger room may free seats
        return $e->refresh();
    }

    private function clean(array $d): array
    {
        if (isset($d['description'])) {
            $d['description'] = Html::clean($d['description']);
        }
        unset($d['organizer_id'], $d['status']);
        return $d;
    }

    private function checkWindow(array $d): void
    {
        $s = isset($d['starts_at']) ? strtotime((string) $d['starts_at']) : null;
        $e = isset($d['ends_at']) ? strtotime((string) $d['ends_at']) : null;
        if ($s && $e && $e <= $s) {
            $this->fail('ends_at', 'event_bad_times');
        }
        $o = !empty($d['registration_opens_at']) ? strtotime((string) $d['registration_opens_at']) : null;
        $c = !empty($d['registration_closes_at']) ? strtotime((string) $d['registration_closes_at']) : null;
        if ($o && $c && $c <= $o) {
            $this->fail('registration_closes_at', 'event_bad_window');
        }
    }

    public function transition(Event $e, string $to, ?string $note, User $by): Event
    {
        $e->loadMissing('status');
        $from = $e->status->code;
        if (!EventRules::allowed($from, $to)) {
            $this->fail('status', 'event_bad_move');
        }
        if ($to === 'cancelled' && mb_strlen(trim((string) $note)) < 3) {
            $this->fail('note', 'event_reason_required');
        }
        if ($to === 'open' && $e->ends_at->isPast()) {
            $this->fail('status', 'event_already_over');
        }
        $fromId = $e->status_id;
        $e->update(['status_id' => $this->st($to), 'cancel_reason' => $to === 'cancelled' ? mb_substr(trim((string) $note), 0, 1000) : $e->cancel_reason]);
        $this->history($e, $fromId, $e->status_id, $by, $note);
        AuditLogger::log('event.status', $e, ['status' => $from], ['status' => $to, 'note' => $note]);
        if ($to === 'cancelled') {
            $this->notifyAll($e, 'event_cancelled', ['reason' => (string) $note]);
        }
        return $e->refresh();
    }

    public function rotateLink(Event $e, User $by): Event
    {
        $e->update(['public_token' => Str::random(48)]);
        AuditLogger::log('event.link_rotated', $e, [], []);
        return $e;
    }

    // ---- registration -----------------------------------------------------------------------------------------------------------
    public function taken(Event $e): int
    {
        return EventRegistration::where('event_id', $e->id)->where('status_id', $this->rst('registered'))->count();
    }

    /**
     * Register a person (a user, or an external guest when guest data is given). $byManager skips the registration window and lets the organizer add people.
     * @param array{name?:string,email?:string,phone?:string,organization?:string}|null $guest
     */
    public function register(Event $e, ?User $user, ?array $guest, ?User $by, bool $byManager = false, ?string $note = null): EventRegistration
    {
        return DB::transaction(function () use ($e, $user, $guest, $by, $byManager, $note) {
            $e = Event::withoutGlobalScopes()->whereKey($e->id)->lockForUpdate()->with('status')->firstOrFail();   // capacity is checked under a lock
            $code = $e->status->code;
            if ($byManager ? !in_array($code, ['draft', 'open', 'closed', 'in_progress'], true)
                : !EventRules::registrationOpen($code, time(), $e->registration_opens_at?->getTimestamp(), $e->registration_closes_at?->getTimestamp())) {
                $this->fail('status', $code === 'open' ? 'event_registration_window' : 'event_registration_closed');
            }
            if ($user === null && !$e->allow_guests && !$byManager) {
                $this->fail('email', 'event_guests_not_allowed');
            }
            $dup = EventRegistration::where('event_id', $e->id)->where('status_id', '!=', $this->rst('cancelled'))
                ->when($user, fn ($q) => $q->where('user_id', $user->id), fn ($q) => $q->where('guest_email', mb_strtolower(trim((string) ($guest['email'] ?? '')))));
            if ($dup->exists()) {
                $this->fail($user ? 'user_id' : 'email', 'event_already_registered');
            }
            $seat = EventRules::seat($e->capacity, $this->taken($e), $e->waitlist_enabled);
            if ($seat === 'full') {
                $this->fail('status', 'event_full');
            }
            $reg = EventRegistration::create([
                'event_id' => $e->id, 'user_id' => $user?->id, 'guest_name' => $user ? null : trim((string) $guest['name']), 'guest_email' => $user ? null : mb_strtolower(trim((string) $guest['email'])),
                'guest_phone' => $user ? null : ($guest['phone'] ?? null), 'guest_organization' => $user ? null : ($guest['organization'] ?? null),
                'status_id' => $this->rst($seat), 'ticket_token' => Str::random(48), 'registered_at' => now(), 'note' => $note ? mb_substr($note, 0, 500) : null, 'registered_by' => $by?->id,
            ]);
            AuditLogger::log('event.registered', $reg, [], ['event' => $e->code, 'status' => $seat, 'who' => $reg->displayName()]);
            $this->notifyOne($e, $reg, $seat === 'registered' ? 'event_registered' : 'event_waitlisted');
            return $reg;
        });
    }

    public function cancelRegistration(EventRegistration $r, Event $e, ?User $by): EventRegistration
    {
        $r->loadMissing('status');
        if ($r->status->code === 'cancelled') {
            return $r;
        }
        if ($r->attendance()->exists()) {
            $this->fail('status', 'event_already_checked_in');
        }
        $e->loadMissing('status');
        if (in_array($e->status->code, ['completed', 'cancelled'], true)) {
            $this->fail('status', 'event_registration_closed');
        }
        $r->update(['status_id' => $this->rst('cancelled'), 'cancelled_at' => now()]);
        AuditLogger::log('event.registration_cancelled', $r, [], ['event' => $e->code, 'who' => $r->displayName(), 'by' => $by?->id]);
        $this->promoteWaitlist($e);
        return $r->refresh();
    }

    /** Oldest waitlisted people take the free seats and are told. */
    public function promoteWaitlist(Event $e): int
    {
        $n = 0;
        DB::transaction(function () use ($e, &$n) {
            $e = Event::withoutGlobalScopes()->whereKey($e->id)->lockForUpdate()->firstOrFail();
            $free = EventRules::freeSeats($e->capacity, $this->taken($e));
            if ($free <= 0) {
                return;
            }
            $rows = EventRegistration::where('event_id', $e->id)->where('status_id', $this->rst('waitlisted'))->orderBy('registered_at')->limit(min($free, 200))->get();
            foreach ($rows as $r) {
                $r->update(['status_id' => $this->rst('registered')]);
                AuditLogger::log('event.waitlist_promoted', $r, [], ['event' => $e->code, 'who' => $r->displayName()]);
                $this->notifyOne($e, $r, 'event_waitlist_promoted');
                $n++;
            }
        });
        return $n;
    }

    // ---- check-in ---------------------------------------------------------------------------------------------------------------
    public function checkIn(Event $e, EventRegistration $r, User $by, string $method, bool $isManager, ?string $note = null): EventAttendance
    {
        $e->loadMissing('status');
        $r->loadMissing('status');
        if (!EventRules::canCheckIn($e->status->code, $isManager)) {
            $this->fail('status', 'event_not_running');
        }
        if ($r->status->code !== 'registered') {
            $this->fail('status', 'event_not_registered');
        }
        $existing = EventAttendance::where('registration_id', $r->id)->first();
        if ($existing) {
            return $existing;                                       // scanning twice is harmless
        }
        $a = EventAttendance::create(['event_id' => $e->id, 'registration_id' => $r->id, 'method_id' => Lookup::id('event_checkin_method', $method), 'checked_in_at' => now(), 'checked_in_by' => $by->id, 'note' => $note ? mb_substr($note, 0, 500) : null]);
        AuditLogger::log('event.checked_in', $a, [], ['event' => $e->code, 'who' => $r->displayName(), 'method' => $method, 'after_completion' => $e->status->code === 'completed']);
        return $a;
    }

    public function undoCheckIn(Event $e, EventRegistration $r, User $by, string $reason): void
    {
        if (mb_strlen(trim($reason)) < 3) {
            $this->fail('note', 'event_reason_required');
        }
        $a = EventAttendance::where('registration_id', $r->id)->first();
        if ($a) {
            AuditLogger::log('event.check_in_undone', $a, ['who' => $r->displayName()], ['reason' => $reason]);
            $a->delete();
        }
    }

    // ---- figures ----------------------------------------------------------------------------------------------------------------
    public function stats(Event $e): array
    {
        $by = EventRegistration::where('event_id', $e->id)->select('status_id', DB::raw('count(*) as n'))->groupBy('status_id')->pluck('n', 'status_id');
        $reg = (int) ($by[$this->rst('registered')] ?? 0);
        $att = EventAttendance::where('event_id', $e->id)->count();
        return ['registered' => $reg, 'waitlisted' => (int) ($by[$this->rst('waitlisted')] ?? 0), 'cancelled' => (int) ($by[$this->rst('cancelled')] ?? 0), 'attended' => $att,
            'no_shows' => max(0, $reg - $att), 'attendance_rate' => EventRules::attendanceRate($reg, $att), 'capacity' => $e->capacity, 'free_seats' => $e->capacity === null ? null : max(0, $e->capacity - $reg)];
    }

    // ---- notices ----------------------------------------------------------------------------------------------------------------
    public function whenText(Event $e): string
    {
        $tz = AttendanceService::tz($e->organization_id);
        return $e->starts_at->copy()->setTimezone($tz)->format('Y-m-d H:i') . ' (' . $tz . ')';
    }

    private function params(Event $e, array $extra = []): array
    {
        return $extra + ['title' => $e->title, 'ref' => $e->code, 'when' => $this->whenText($e), 'where' => $e->venue ?: ($e->online_url ?: '-')];
    }

    public function ticketUrl(EventRegistration $r): string { return rtrim((string) config('ops.frontend_url'), '/') . '/event-ticket/' . $r->ticket_token; }

    private function notifyOne(Event $e, EventRegistration $r, string $key, array $extra = []): void
    {
        $params = $this->params($e, $extra);
        if ($r->user_id) {
            Notifier::send(User::whereKey($r->user_id)->with('language')->get(), $key, $params, null, ['in_app', 'email'], '/events/' . $e->id, $e->organization_id);
            return;
        }
        if (!$r->guest_email) {
            return;
        }
        $locale = (string) config('ops.default_language', 'en');
        try {
            Mail::to($r->guest_email)->send(new NotificationMail(__("notify.$key.title", $params, $locale), __("notify.$key.body", $params, $locale), $this->ticketUrl($r), $locale));
        } catch (Throwable $ex) {
            report($ex);                                            // a bad address must not block the registration
        }
    }

    /** Everyone still registered or waitlisted hears about a cancellation. */
    private function notifyAll(Event $e, string $key, array $extra = []): void
    {
        EventRegistration::where('event_id', $e->id)->where('status_id', '!=', $this->rst('cancelled'))->with('user.language')->chunkById(200, function ($rows) use ($e, $key, $extra) {
            foreach ($rows as $r) {
                $this->notifyOne($e, $r, $key, $extra);
            }
        });
    }
}
