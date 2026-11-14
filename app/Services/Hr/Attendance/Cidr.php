<?php

namespace App\Services\Hr\Attendance;

/** Pure: is an IP address inside a CIDR range (IPv4 or IPv6)? Used by the optional clock-in network restriction. */
final class Cidr
{
    public static function valid(string $cidr): bool
    {
        [$ip, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $bin = @inet_pton((string) $ip);
        if ($bin === false) {
            return false;
        }
        return $bits === null || (ctype_digit($bits) && (int) $bits <= strlen($bin) * 8);
    }

    public static function contains(string $cidr, string $ip): bool
    {
        if (!self::valid($cidr)) {
            return false;
        }
        [$net, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $a = inet_pton($net);
        $b = @inet_pton($ip);
        if ($b === false || strlen($a) !== strlen($b)) {
            return false;
        }
        $bits = $bits === null ? strlen($a) * 8 : (int) $bits;
        $full = intdiv($bits, 8);
        if (substr($a, 0, $full) !== substr($b, 0, $full)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;
        return (ord($a[$full]) & $mask) === (ord($b[$full]) & $mask);
    }
}
