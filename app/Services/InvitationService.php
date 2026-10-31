<?php

namespace App\Services;

use App\Mail\UserInvitationMail;
use App\Models\NotificationDelivery;
use App\Models\Organization;
use App\Models\User;
use App\Models\UserInvitation;
use App\Support\Lookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invitation / activation of new users.
 *
 *  issue()     create a fresh single-use token (older pending ones are invalidated) and e-mail the activation link
 *  inspect()   validate e-mail + token without consuming it
 *  accept()    set the password, activate the account, consume the token
 *
 * The raw token exists only in the e-mailed link; the database keeps its SHA-256 hash. The e-mail is sent directly (not queued)
 * so the link is never stored in the jobs table and the administrator sees at once whether it could be sent.
 */
class InvitationService
{
    public const RESEND_SECONDS = 60;

    public static function ttlDays(): int { return (int) config('ops.invitation_days', 7); }

    /** @return array{0: UserInvitation, 1: bool} the invitation and whether the e-mail was handed to the mail server */
    public function issue(User $user, ?User $by = null, ?string $organizationId = null): array
    {
        $organizationId ??= app(OrganizationContext::class)->organizationId();
        $raw = Str::random(64);
        $count = (int) UserInvitation::where('user_id', $user->id)->max('send_count');

        $inv = DB::transaction(function () use ($user, $by, $organizationId, $raw, $count) {
            UserInvitation::where('user_id', $user->id)->whereNull('accepted_at')->delete();   // only one live link at a time
            return UserInvitation::create([
                'user_id' => $user->id, 'organization_id' => $organizationId, 'token_hash' => hash('sha256', $raw),
                'sent_by' => $by?->id, 'send_count' => $count + 1, 'last_sent_at' => now(),
                'expires_at' => now()->addDays(self::ttlDays()),
            ]);
        });

        return [$inv, $this->send($user, $raw, $by, $organizationId)];
    }

    /** Can a new e-mail be sent now? (protects the mailbox and the provider quota) */
    public function throttled(User $user): bool
    {
        $last = UserInvitation::where('user_id', $user->id)->whereNull('accepted_at')->max('last_sent_at');
        return $last && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($last), true) < self::RESEND_SECONDS;
    }

    /** @return array{0: ?UserInvitation, 1: ?User, 2: ?string} invitation, user, problem (null = valid | invalid | used | expired) */
    public function inspect(string $email, string $token): array
    {
        $user = User::with('status')->where('email', Str::lower(trim($email)))->first();
        $inv = $user ? UserInvitation::where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->first() : null;

        if (!$inv) return [null, null, 'invalid'];
        if ($inv->accepted_at) return [$inv, $user, 'used'];
        if ($user->status?->code !== 'invited') return [$inv, $user, 'invalid'];
        if ($inv->isExpired()) return [$inv, $user, 'expired'];
        return [$inv, $user, null];
    }

    public function accept(User $user, UserInvitation $inv, string $password): User
    {
        DB::transaction(function () use ($user, $inv, $password) {
            $user->forceFill([
                'password' => $password, 'status_id' => Lookup::id('user_status', 'active'),
                'must_change_password' => false, 'email_verified_at' => $user->email_verified_at ?? now(), 'remember_token' => Str::random(60),
            ])->save();
            $inv->update(['accepted_at' => now()]);
            UserInvitation::where('user_id', $user->id)->whereNull('accepted_at')->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
        });
        return $user->refresh();
    }

    /** Remove every pending link (suspension, cancellation). */
    public function revoke(User $user): void
    {
        UserInvitation::where('user_id', $user->id)->whereNull('accepted_at')->delete();
    }

    private function send(User $user, string $raw, ?User $by, ?string $organizationId): bool
    {
        $locale = $user->language?->code ?? config('ops.default_language', 'en');
        $url = rtrim(config('ops.frontend_url'), '/') . '/activate?token=' . $raw . '&email=' . urlencode($user->email);
        $org = $organizationId ? Organization::find($organizationId)?->name : null;
        $error = null;

        try {
            Mail::to($user->email)->locale($locale)->send(new UserInvitationMail($user->name, $org ?: config('app.name'), $by?->name, $url, self::ttlDays()));
        } catch (\Throwable $e) {
            report($e);
            $error = mb_substr($e->getMessage(), 0, 400);
        }

        // Delivery log WITHOUT the link, so failures are visible in the notification delivery report.
        try {
            NotificationDelivery::create([
                'organization_id' => $organizationId, 'user_id' => $user->id,
                'channel_id' => Lookup::id('notification_channel', 'email'), 'status_id' => Lookup::id('delivery_status', $error ? 'failed' : 'sent'),
                'recipient' => $user->email, 'subject' => __('invitation.subject', ['app' => config('app.name')], $locale), 'body' => 'Invitation',
                'locale' => $locale, 'error' => $error, 'attempts' => 1, 'sent_at' => $error ? null : now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }

        return $error === null;
    }
}
