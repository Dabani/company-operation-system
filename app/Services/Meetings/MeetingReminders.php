<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\Organization;
use App\Services\SettingsService;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Reminders before a meeting (Guide 6.4), at configurable offsets (setting meeting.reminder_offsets, default "1440,60" = 24 h and 1 h before).
 * Each reminder goes once per person. A meeting scheduled inside an offset skips that offset: people get the most recent one, not a burst.
 * Run every five minutes by the scheduler (cops:meetings-remind).
 */
class MeetingReminders
{
    public function __construct(private MeetingNotices $notices) {}

    /** "1440, 60,60,x" -> [1440, 60]: whole minutes between 5 minutes and 14 days, largest first. Pure (unit-tested). */
    public static function parseOffsets(string $setting): array
    {
        $out = [];
        foreach (explode(',', $setting) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part >= 5 && (int) $part <= 20160) {
                $out[] = (int) $part;
            }
        }
        $out = array_values(array_unique($out));
        rsort($out);
        return $out;
    }

    /** Which offsets are due at $minutesBefore (minutes until the start): those not later than the time left. Pure. */
    public static function dueOffsets(array $offsets, int $minutesLeft): array
    {
        return array_values(array_filter($offsets, fn ($o) => $minutesLeft <= $o));
    }

    /** @return int reminders sent */
    public function run(): int
    {
        $sent = 0;
        foreach (Organization::all() as $org) {
            $offsets = self::parseOffsets((string) SettingsService::get($org->id, 'meeting.reminder_offsets'));
            if (!$offsets) {
                continue;
            }
            $sms = (bool) SettingsService::get($org->id, 'meeting.reminder_sms');
            $channels = $sms ? ['in_app', 'email', 'sms'] : ['in_app', 'email'];
            $now = now();
            $meetings = Meeting::withoutGlobalScopes()->where('organization_id', $org->id)->where('status_id', Lookup::id('meeting_status', 'scheduled'))
                ->where('starts_at', '>', $now->copy()->utc()->format('Y-m-d H:i:s'))
                ->where('starts_at', '<=', $now->copy()->addMinutes($offsets[0])->utc()->format('Y-m-d H:i:s'))
                ->with(['room', 'organizer', 'invitations' => fn ($q) => $q->withoutGlobalScopes()->with(['user.language', 'response'])])->get();

            foreach ($meetings as $m) {
                $left = (int) floor($now->diffInMinutes($m->starts_at, false));
                $due = self::dueOffsets($offsets, $left);
                if (!$due) {
                    continue;
                }
                $current = min($due);                                           // the most recent offset that has arrived
                foreach ($m->invitations as $inv) {
                    if ($inv->response?->code === 'declined') {
                        continue;
                    }
                    $already = DB::table('meeting_reminder_log')->where('invitation_id', $inv->id)->pluck('offset_minutes')->all();
                    if (in_array($current, $already, true)) {
                        continue;
                    }
                    foreach ($due as $o) {                                       // older offsets are marked as done silently
                        DB::table('meeting_reminder_log')->insertOrIgnore(['id' => (string) Str::ulid(), 'organization_id' => $org->id, 'invitation_id' => $inv->id, 'offset_minutes' => $o, 'sent_at' => $now]);
                    }
                    $this->notices->send($m, [$inv], 'meeting_reminder', ['minutes' => $left, 'in' => $this->human($left)], $channels);
                    $sent++;
                }
            }
        }
        return $sent;
    }

    private function human(int $minutes): string
    {
        return $minutes >= 120 ? intdiv($minutes, 60) . ' h' : $minutes . ' min';
    }
}
