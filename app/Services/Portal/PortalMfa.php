<?php

namespace App\Services\Portal;

use App\Models\ClientIdentity;
use App\Models\ClientRecoveryCode;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Two-factor authentication for client users (authenticator app + one-time recovery codes). Required by default (setting portal.mfa_required). */
class PortalMfa
{
    public static function required(string $organizationId): bool { return (bool) SettingsService::get($organizationId, 'portal.mfa_required'); }

    private function fail(string $field, string $key): never { throw ValidationException::withMessages([$field => [__("portal.$key")]]); }

    /** @return array{secret: string, uri: string} */
    public function begin(ClientIdentity $i, string $issuer): array
    {
        if ($i->hasMfa()) $this->fail('code', 'mfa.already_enabled');
        $secret = Totp::generateSecret();
        $i->forceFill(['two_factor_secret' => $secret, 'two_factor_last_step' => null])->save();
        return ['secret' => $secret, 'uri' => Totp::uri($secret, $i->email, $issuer)];
    }

    /** @return string[] the recovery codes, shown exactly once */
    public function confirm(ClientIdentity $i, string $code): array
    {
        if ($i->hasMfa() || !$i->two_factor_secret) $this->fail('code', 'mfa.not_started');
        $step = Totp::verify($i->two_factor_secret, $code);
        if ($step === null) $this->fail('code', 'mfa.invalid_code');
        return DB::transaction(function () use ($i, $step) {
            $i->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->save();
            ClientRecoveryCode::where('client_identity_id', $i->id)->delete();
            $plain = [];
            for ($n = 0; $n < 10; $n++) {
                $c = Str::lower(Str::random(5)) . '-' . Str::lower(Str::random(5));
                $plain[] = $c;
                ClientRecoveryCode::create(['client_identity_id' => $i->id, 'code_hash' => $this->hash($c)]);
            }
            AuditLogger::log('portal.mfa_enabled', $i);
            return $plain;
        });
    }

    /** Authenticator code (each time step usable once) or a recovery code (consumed). */
    public function verify(ClientIdentity $i, string $code): bool
    {
        if (!$i->hasMfa()) return false;
        $step = Totp::verify($i->two_factor_secret, $code);
        if ($step !== null && ($i->two_factor_last_step === null || $step > $i->two_factor_last_step)) {
            $i->forceFill(['two_factor_last_step' => $step])->save();
            return true;
        }
        $rc = ClientRecoveryCode::where('client_identity_id', $i->id)->whereNull('used_at')->where('code_hash', $this->hash($code))->first();
        if ($rc) {
            $rc->update(['used_at' => now()]);
            AuditLogger::log('portal.recovery_code_used', $i);
            return true;
        }
        return false;
    }

    /** Staff action when a client loses the device: the next sign-in asks for a new set-up. Audited. */
    public function reset(ClientIdentity $i): void
    {
        $i->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null, 'remember_token' => Str::random(60)])->save();
        ClientRecoveryCode::where('client_identity_id', $i->id)->delete();
        AuditLogger::log('portal.mfa_reset', $i);
    }

    private function hash(string $code): string { return hash('sha256', Str::lower(preg_replace('/[^A-Za-z0-9]/', '', $code))); }
}
