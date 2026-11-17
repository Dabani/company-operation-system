<?php

namespace App\Services\Meetings;

use App\Mail\NotificationMail;
use App\Models\Meeting;
use App\Models\MeetingInvitation;
use App\Services\Hr\Attendance\AttendanceService;
use App\Services\Notifier;
use App\Support\Html;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Everything a meeting sends to people: invitations, changes, cancellations, reminders (Guide 6.4), and the calendar file.
 * Users get in-app + e-mail in their own language through Notifier; external guests get an e-mail with their personal reply link.
 * Texts live in lang/{locale}/notify.php (keys meeting_*).
 */
class MeetingNotices
{
    public function whenText(Meeting $m): string
    {
        $tz = AttendanceService::tz($m->organization_id);
        $s = $m->starts_at->setTimezone($tz);
        $e = $m->ends_at->setTimezone($tz);
        return $s->format('Y-m-d H:i') . ($s->isSameDay($e) ? '-' . $e->format('H:i') : ' → ' . $e->format('Y-m-d H:i')) . " ($tz)";
    }

    public function whereText(Meeting $m): string
    {
        $parts = array_filter([$m->room?->name, $m->venue, $m->online_url]);
        return $parts ? implode(' · ', $parts) : '-';
    }

    private function params(Meeting $m, array $extra = []): array
    {
        return ['title' => $m->title, 'ref' => $m->code, 'when' => $this->whenText($m), 'where' => $this->whereText($m), 'organizer' => $m->organizer?->name ?? ''] + $extra;
    }

    private function link(Meeting $m): string { return '/meetings/' . $m->id; }

    /**
     * @param iterable<MeetingInvitation> $invitations
     * @param string $key one of meeting_invited, meeting_updated, meeting_cancelled, meeting_reminder, meeting_agenda_circulated
     */
    public function send(Meeting $m, iterable $invitations, string $key, array $extra = [], array $channels = ['in_app', 'email']): void
    {
        $m->loadMissing(['room', 'organizer']);
        $params = $this->params($m, $extra);
        foreach ($invitations as $inv) {
            if ($inv->user_id) {
                $inv->loadMissing('user.language');
                if ($inv->user && ($inv->user->id !== $m->organizer_id || $key === 'meeting_reminder')) {
                    Notifier::send($inv->user, $key, $params, null, $channels, $this->link($m), $m->organization_id);
                }
                continue;
            }
            $this->mailGuest($m, $inv, $key, $params);
        }
    }

    private function mailGuest(Meeting $m, MeetingInvitation $inv, string $key, array $params): void
    {
        if (!$inv->guest_email) {
            return;
        }
        $locale = (string) config('ops.default_language', 'en');
        $url = rtrim((string) config('ops.frontend_url'), '/') . '/meeting-rsvp/' . $inv->token;
        try {
            Mail::to($inv->guest_email)->send(new NotificationMail(__("notify.$key.title", $params, $locale), __("notify.$key.body", $params, $locale), $url, $locale));
        } catch (Throwable $e) {
            report($e);              // a bad address must not stop the meeting from being scheduled
        }
    }

    // ---- calendar file ---------------------------------------------------------------------------------------------------
    public function ics(Meeting $m, ?MeetingInvitation $for = null): string
    {
        $m->loadMissing(['room', 'organizer', 'status', 'confidentiality', 'invitations.user', 'invitations.response']);
        $cancelled = $m->status?->code === 'cancelled';
        $partstat = ['pending' => 'NEEDS-ACTION', 'accepted' => 'ACCEPTED', 'declined' => 'DECLINED', 'proposed' => 'TENTATIVE'];
        // A confidential meeting does not reveal its guest list to every recipient: each person's file carries only their own entry.
        $list = $m->isConfidential() ? $m->invitations->filter(fn ($i) => $for && $i->id === $for->id) : $m->invitations;
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'cops.local';
        return IcsBuilder::build([
            'uid' => $m->id . '@' . $host, 'sequence' => $m->ics_sequence, 'method' => $cancelled ? 'CANCEL' : 'REQUEST', 'status' => $cancelled ? 'CANCELLED' : 'CONFIRMED',
            'summary' => $m->title, 'description' => trim(Html::plain($m->purpose) . ($m->online_url ? "\n" . $m->online_url : '')), 'location' => $this->whereText($m) === '-' ? null : $this->whereText($m),
            'url' => rtrim((string) config('ops.frontend_url'), '/') . $this->link($m), 'start' => $m->starts_at, 'end' => $m->ends_at, 'stamp' => CarbonImmutable::now('UTC'),
            'organizer' => $m->organizer ? ['name' => $m->organizer->name, 'email' => $m->organizer->email] : null,
            'attendees' => $list->map(fn ($i) => ['name' => $i->displayName(), 'email' => (string) $i->email(), 'partstat' => $partstat[$i->response?->code] ?? 'NEEDS-ACTION'])->values()->all(),
            'reminder_minutes' => 15,
        ]);
    }
}
