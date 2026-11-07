<?php

namespace App\Services\Portal;

/** RFC 6238 time-based one-time passwords (SHA-1, 30 s, 6 digits) - what Google Authenticator, Microsoft Authenticator, Authy and 2FAS expect. Pure, unit-tested. */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string { return self::base32Encode(random_bytes($bytes)); }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        return $out;
    }

    public static function base32Decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }

    public static function code(string $secret, int $timestamp, int $digits = 6, int $period = 30): string
    {
        $counter = intdiv($timestamp, $period);
        $hash = hash_hmac('sha1', pack('N*', 0, $counter), self::base32Decode($secret), true);
        $o = ord($hash[19]) & 0x0F;
        $n = ((ord($hash[$o]) & 0x7F) << 24) | ((ord($hash[$o + 1]) & 0xFF) << 16) | ((ord($hash[$o + 2]) & 0xFF) << 8) | (ord($hash[$o + 3]) & 0xFF);
        return str_pad((string) ($n % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** @return int|null the matched time step (use it to refuse a second use of the same code), or null when the code is wrong. */
    public static function verify(string $secret, string $code, ?int $now = null, int $window = 1, int $period = 30): ?int
    {
        $now ??= time();
        $code = preg_replace('/\s+/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) return null;
        for ($i = -$window; $i <= $window; $i++) {
            $t = $now + $i * $period;
            if (hash_equals(self::code($secret, $t, 6, $period), $code)) return intdiv($t, $period);
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode("$issuer:$account") . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }
}
