<?php

namespace App\Services\Portal;

use App\Mail\UserInvitationMail;
use App\Models\ClientIdentity;
use App\Models\ClientInvitation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Client onboarding reuses the staff activation design: a single-use token whose SHA-256 hash is stored, the raw token only in the e-mailed link,
 * one live link per person, expiry from ops.invitation_days. kind "reset" lets an active client choose a new password (forgot password).
 */
class ClientInvitationService
{
    public const RESEND_SECONDS = 60;

    /** @return array{0: ClientInvitation, 1: bool} invitation and whether the mail was handed to the mail server */
    public function issue(ClientIdentity $identity, ?User $by = null, string $kind = 'invite'): array
    {
        $raw = Str::random(64);
        $count = (int) ClientInvitation::where('client_identity_id', $identity->id)->max('send_count');
        $inv = DB::transaction(function () use ($identity, $by, $kind, $raw, $count) {
            ClientInvitation::where('client_identity_id', $identity->id)->whereNull('accepted_at')->delete();
            return ClientInvitation::create(['organization_id' => $identity->organization_id, 'client_identity_id' => $identity->id, 'token_hash' => hash('sha256', $raw), 'kind' => $kind,
                'sent_by' => $by?->id, 'send_count' => $count + 1, 'last_sent_at' => now(), 'expires_at' => now()->addDays((int) config('ops.invitation_days', 7))]);
        });
        return [$inv, $this->send($identity, $raw, $by)];
    }

    public function throttled(ClientIdentity $identity): bool
    {
        $last = ClientInvitation::where('client_identity_id', $identity->id)->whereNull('accepted_at')->max('last_sent_at');
        return $last && now()->diffInSeconds(\Illuminate\Support\Carbon::parse($last), true) < self::RESEND_SECONDS;
    }

    /** @return array{0: ?ClientInvitation, 1: ?ClientIdentity, 2: ?string} invitation, identity, problem (null = valid | invalid | used | expired) */
    public function inspect(Organization $org, string $email, string $token): array
    {
        $identity = ClientIdentity::withoutGlobalScopes()->where('organization_id', $org->id)->where('email', Str::lower(trim($email)))->first();
        $inv = $identity ? ClientInvitation::withoutGlobalScopes()->where('client_identity_id', $identity->id)->where('token_hash', hash('sha256', $token))->first() : null;
        if (!$inv) return [null, null, 'invalid'];
        if ($inv->accepted_at) return [$inv, $identity, 'used'];
        if ($inv->kind === 'invite' && $identity->password !== null && $identity->is_active) return [$inv, $identity, 'invalid'];
        if ($inv->isExpired()) return [$inv, $identity, 'expired'];
        return [$inv, $identity, null];
    }

    public function accept(ClientIdentity $identity, ClientInvitation $inv, string $password): ClientIdentity
    {
        DB::transaction(function () use ($identity, $inv, $password) {
            $identity->forceFill(['password' => $password, 'is_active' => true, 'remember_token' => Str::random(60)])->save();
            $inv->update(['accepted_at' => now()]);
            ClientInvitation::where('client_identity_id', $identity->id)->whereNull('accepted_at')->delete();
        });
        return $identity->refresh();
    }

    private function send(ClientIdentity $identity, string $raw, ?User $by): bool
    {
        $org = Organization::find($identity->organization_id);
        $url = rtrim(config('ops.frontend_url'), '/') . '/portal/activate?token=' . $raw . '&email=' . urlencode($identity->email) . '&org=' . urlencode((string) $org?->slug);
        $name = $identity->contact?->name ?? $identity->email;
        try {
            Mail::to($identity->email)->locale(config('ops.default_language', 'en'))->send(new UserInvitationMail($name, $org?->name ?: config('app.name'), $by?->name, $url, (int) config('ops.invitation_days', 7)));
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }
}
