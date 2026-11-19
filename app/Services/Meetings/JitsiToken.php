<?php

namespace App\Services\Meetings;

/** Signs the HS256 JSON Web Token that a self-hosted Jitsi (jitsi-meet-tokens) accepts. Pure: no framework, no clock of its own. */
final class JitsiToken
{
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

    public static function sign(array $claims, string $secret): string
    {
        $head = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $body = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return "$head.$body." . self::b64(hash_hmac('sha256', "$head.$body", $secret, true));
    }

    public static function forUser(string $appId, string $secret, string $sub, string $room, string $name, ?string $email, string $id, bool $moderator, int $now, int $ttlSeconds): string
    {
        return self::sign([
            'aud' => 'jitsi', 'iss' => $appId, 'sub' => $sub, 'room' => $room, 'nbf' => $now - 10, 'exp' => $now + $ttlSeconds,
            'moderator' => $moderator,
            'context' => ['user' => ['id' => $id, 'name' => $name, 'email' => $email ?? '', 'moderator' => $moderator, 'affiliation' => $moderator ? 'owner' : 'member']],
        ], $secret);
    }
}
