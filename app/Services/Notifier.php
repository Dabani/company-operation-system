<?php

namespace App\Services;

use App\Events\AppNotificationCreated;
use App\Jobs\DeliverNotification;
use App\Models\AppNotification;
use App\Models\NotificationDelivery;
use App\Models\SupportCase;
use App\Models\User;
use App\Support\Lookup;
use App\Support\Phone;
use Illuminate\Support\Collection;

/**
 * One call = in-app (live) notification + optional email + optional SMS, each in the recipient's own language.
 * Text lives in lang/{locale}/notify.php.
 */
class Notifier
{
    public static function send(
        User|Collection|array $users,
        string $key,                      // lang key under notify.*, e.g. "case_created_admin"
        array $params = [],
        ?SupportCase $case = null,
        array $channels = ['in_app', 'email'],
        ?string $link = null,
        ?string $organizationId = null,
    ): void {
        $orgId = $organizationId ?? $case?->organization_id ?? app(OrganizationContext::class)->organizationId();
        $link ??= $case ? '/cases/' . $case->id : null;

        $list = $users instanceof \Illuminate\Database\Eloquent\Model ? collect([$users]) : collect($users);
        foreach ($list->unique('id') as $user) {
            $locale = $user->language?->code ?? config('ops.default_language');
            $title = __("notify.$key.title", $params, $locale);
            $body = __("notify.$key.body", $params, $locale);
            $url = $link ? rtrim(config('ops.frontend_url'), '/') . $link : null;

            if (in_array('in_app', $channels, true)) {
                $n = AppNotification::create([
                    'organization_id' => $orgId, 'user_id' => $user->id, 'title' => $title, 'body' => mb_substr($body, 0, 500),
                    'case_id' => $case?->id, 'link' => $link,
                ]);
                \App\Support\Realtime::emit(new AppNotificationCreated($n));
            }

            if (in_array('email', $channels, true) && $user->email) {
                self::queue($orgId, $user, $case, 'email', $user->email, $title, $body, $url, $locale);
            }

            if (in_array('sms', $channels, true) && ($digits = Phone::e164Digits($user->phone))) {
                $sms = __("notify.$key.sms", $params, $locale);
                self::queue($orgId, $user, $case, 'sms', $digits, $title, $sms === "notify.$key.sms" ? $title . ': ' . $body : $sms, null, $locale);
            }
        }
    }

    private static function queue(?string $orgId, User $user, ?SupportCase $case, string $channel, string $recipient, string $subject, string $body, ?string $url, string $locale): void
    {
        $d = NotificationDelivery::create([
            'organization_id' => $orgId, 'user_id' => $user->id, 'case_id' => $case?->id,
            'channel_id' => Lookup::id('notification_channel', $channel), 'status_id' => Lookup::id('delivery_status', 'pending'),
            'recipient' => $recipient, 'subject' => $subject, 'body' => $body, 'action_url' => $url, 'locale' => $locale,
        ]);
        DeliverNotification::dispatch($d->id)->afterCommit();
    }

    /** Active users of an organization holding a permission (used for "alert the admins"). */
    public static function usersWithPermission(string $organizationId, string $permission): Collection
    {
        return User::query()->with('language')
            ->whereHas('status', fn ($s) => $s->where('code', 'active'))
            ->whereHas('memberships', fn ($m) => $m->where('organization_id', $organizationId)
                ->whereHas('status', fn ($s) => $s->where('code', 'active'))
                ->whereHas('roles', fn ($r) => $r->where('is_active', true)->whereHas('permissions', fn ($p) => $p->where('code', $permission))))
            ->get();
    }
}
