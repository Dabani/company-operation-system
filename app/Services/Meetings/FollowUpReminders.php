<?php

namespace App\Services\Meetings;

use App\Models\Meeting;
use App\Models\MeetingAction;
use App\Models\MeetingResolution;
use App\Models\Organization;
use App\Models\User;
use App\Services\Notifier;
use App\Services\SettingsService;
use App\Support\Lookup;

/**
 * Due-soon and overdue reminders for resolutions and actions (Guide 6.7). Run hourly (cops:meetings-followups); each item is alerted
 * once when it comes due soon, and again every meeting.followup_overdue_repeat_days days while it stays overdue. The secretary and the organizer
 * are told about overdue items too. "Today" is the date in the organization time zone.
 */
class FollowUpReminders
{
    /** @return int notices sent */
    public function run(): int
    {
        $sent = 0;
        foreach (Organization::all() as $org) {
            $today = FollowUpService::today($org->id);
            $soonDays = max(0, (int) SettingsService::get($org->id, 'meeting.followup_due_soon_days'));
            $repeat = max(1, (int) SettingsService::get($org->id, 'meeting.followup_overdue_repeat_days'));
            foreach (['resolution' => [MeetingResolution::class, 'responsible_user_id', 'resolution_status'], 'action' => [MeetingAction::class, 'owner_id', 'action_status']] as $kind => [$model, $ownerCol, $lookup]) {
                $active = [Lookup::id($lookup, 'open'), Lookup::id($lookup, 'in_progress')];
                $rows = $model::withoutGlobalScopes()->where('organization_id', $org->id)->whereIn('status_id', $active)->whereNotNull('due_on')
                    ->where('due_on', '<=', date('Y-m-d', strtotime($today . " +$soonDays days")))->get();
                foreach ($rows as $row) {
                    $due = $row->due_on->toDateString();
                    $meeting = Meeting::withoutGlobalScopes()->with('status')->find($row->meeting_id);
                    if (!$meeting) {
                        continue;
                    }
                    $owner = $row->{$ownerCol} ? User::with('language')->find($row->{$ownerCol}) : null;
                    $params = ['title' => $row->title, 'ref' => $row->code, 'meeting' => $meeting->title, 'due' => $due];
                    if ($due < $today) {
                        if (!FollowUpRules::overdueDue($row->overdue_alerted_on?->toDateString(), $today, $repeat)) {
                            continue;
                        }
                        $params['days'] = FollowUpRules::daysOverdue($due, $today);
                        $row->forceFill(['overdue_alerted_on' => $today])->save();
                        $to = collect([$owner])->merge(User::whereKey(array_filter([$meeting->secretary_id, $meeting->organizer_id]))->with('language')->get())->filter()->unique('id');
                        $key = 'meeting_followup_overdue';
                    } elseif ($row->due_alerted_on === null) {
                        $row->forceFill(['due_alerted_on' => $today])->save();
                        $to = collect([$owner])->filter();
                        $key = 'meeting_followup_due';
                    } else {
                        continue;
                    }
                    if ($to->isNotEmpty()) {
                        Notifier::send($to, $key, $params, null, ['in_app', 'email'], '/meetings/' . $meeting->id, $org->id);
                        $sent++;
                    }
                }
            }
        }
        return $sent;
    }
}
