<?php

/**
 * Online meetings (COPS Phase 7f) use Jitsi Meet. Self-hosting is recommended: set the three secrets below, configure Jitsi with JWT authentication
 * (jitsi-meet-tokens, app id and secret must match) and COPS signs a short-lived token per participant, so only people COPS lets in can enter and only
 * hosts are moderators. Without a secret COPS still builds the room link (public instance, no access control by token: the random room name is the only protection).
 */
return [
    'domain' => env('JITSI_DOMAIN', 'meet.jit.si'),
    'app_id' => env('JITSI_APP_ID'),
    'app_secret' => env('JITSI_APP_SECRET'),
    'sub' => env('JITSI_SUB'),                       // the token "sub" claim: defaults to the domain
    'token_ttl_minutes' => (int) env('JITSI_TOKEN_TTL', 240),
];
