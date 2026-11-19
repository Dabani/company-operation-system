# Phase 7f: online meetings (video conference) and interventions

## Design
- **Engine: Jitsi Meet.** Building a video stack from scratch (media servers, TURN, recording) is a product in itself; Jitsi is open source, can be self-hosted so meeting data stays with you, and embeds in the meeting page. COPS owns the meeting, who may enter and the intervention queue; Jitsi carries audio and video.
- **Rooms:** a meeting gets an online room on demand (`meetings.online_room`, a random name such as `cops-k3j...`). It can be combined with a physical room (hybrid). The old `online_url` field still works for external links (Zoom, Teams).
- **Who may enter:** hosts and invitees who did not decline, only while the room window is open (`meeting.online_early_minutes` before the start until the end, and while in progress). Hosts (organizer, chair, secretary, facilitator) are moderators. External guests enter through their personal invitation link, only when the meeting allows guests, is not confidential, and never as moderators.
- **Access control:** with `JITSI_APP_ID` and `JITSI_APP_SECRET` set (self-hosted Jitsi with JWT authentication), COPS signs a short-lived token per entry, so only people COPS admits can join and moderator rights come from COPS. Without them (public meet.jit.si) COPS only builds the link; the random room name is the only protection and moderator rights are not enforced. Use the lobby feature of Jitsi for admitting people at the door.
- **Attendance:** an invited account holder who enters while the meeting is in progress is recorded as "online" (existing attendance sheet, method self check-in). Every entry and exit is logged (`meeting_online_sessions`). Guests are logged but not added to the sheet; the secretary records them as walk-ins.
- **Interventions:** while the meeting is in progress, a participant (or guest) requests to speak: wish to speak, question, point of order or right of reply, with an optional topic and agenda item. One open request per person. The chair or facilitator (or manage_all) gives the floor to one person at a time; order: points of order, replies, then arrival order. Outcomes: done, dismissed (with note), withdrawn. Everything is audited and kept for the minutes.
- **Live updates:** the page polls every few seconds (no websocket dependency).

## Configuration
`.env`: `JITSI_DOMAIN` (default meet.jit.si), `JITSI_APP_ID`, `JITSI_APP_SECRET`, optional `JITSI_SUB` (token sub, defaults to the domain), `JITSI_TOKEN_TTL` (minutes, default 240). Settings: `meeting.online_enabled` (default off), `meeting.online_early_minutes` (default 15). Jitsi side: install jitsi-meet-tokens with the same app id and secret; the browser loads `https://<domain>/external_api.js` (allow it in your Content-Security-Policy).

## Apply
    python3 apply_b32_backend.py --repo <backend repo> --dry-run
    python3 apply_b32_backend.py --repo <backend repo>
    composer dump-autoload && php artisan migrate && php artisan db:seed --class=MeetingsOnlineSeeder
    php artisan test --filter=OnlineMeetingRules
Edits: routes/api.php, SettingsService, DatabaseSeeder, lang/*/meetings.php. Needs bundles 17 to 20 (meetings, attendance).

## Not in this version
In-meeting chat and polls (Jitsi has built-in chat), recording, breakout rooms, camera QR, guest attendance sheet entries, e-mail notices when a request is made (the chair sees the queue live). Not run on a live install: needs a Jitsi server and a browser.
