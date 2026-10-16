<?php

namespace App\Support;

class Phone
{
    /** "0788 123 456" -> "250788123456"; "+250 788..." -> "250788...". Returns null when it cannot be a valid number. */
    public static function e164Digits(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') return null;
        if (str_starts_with((string) $raw, '+')) { /* already has country code */ }
        elseif (str_starts_with($d, '00')) { $d = substr($d, 2); }
        elseif (str_starts_with($d, '0')) { $d = config('ops.phone_country_code') . substr($d, 1); }
        return strlen($d) >= 8 && strlen($d) <= 15 ? $d : null;
    }
}
