# Phase 7 online meetings: acceptance walkthrough (bundles 32 and 33)

Prerequisites: backend and frontend bundles 32 and 33 applied, `php artisan migrate`, `db:seed --class=MeetingsOnlineSeeder`, `db:seed --class=MeetingsCollabSeeder`, `npm run build`.
Settings: `meeting.online_early_minutes` (15). Environment: `JITSI_DOMAIN` (default meet.jit.si). For a self-hosted Jitsi with login, also `JITSI_APP_ID`, `JITSI_APP_SECRET`, `JITSI_SUB`.
Use three accounts: organizer (A), ordinary participant (B), and a person invited by email only (G, external guest).

## A. Room and joining
1. A opens a scheduled meeting, tab **Online**, presses **Enable online room**. Expect: room switched on, no error.
2. A switches **Allow external guests** on. Expect: allowed; on a confidential meeting the switch is disabled with an explanation.
3. B opens the meeting more than 15 minutes before the start. Expect: Join disabled, text says it opens soon.
4. Within 15 minutes of the start, B presses **Join**. Expect: video loads inside the page; B is marked present in attendance.
5. A joins. Expect: A is a moderator (can mute others). B is not.
6. G opens the personal link `/meeting-online/<token>` (from the invitation page, "Join online"). Expect: joins as guest, never moderator.
7. Cancel or close the meeting. Expect: Join no longer available.

## B. Interventions
8. B requests **Wish to speak**. Expect: B is number 1 in the queue. A second request from B is refused.
9. G requests a **Question**, then B requests a **Point of order**. Expect: order is point of order, then G.
10. A gives the floor to B. Expect: B shows "Speaking now", B sees "It is your turn". A second grant while B speaks is refused until A presses **Finish**.
11. A dismisses G's request; B withdraws another. Expect: statuses dismissed/withdrawn, queue updates within 5 seconds on all screens.

## C. Chat
12. B writes a message. Expect: A and G see it within about 4 seconds; blank and over 1000 characters are refused.
13. A removes B's message. Expect: everyone sees "Message removed by a host"; the original text is gone from the response but the removal is recorded.
14. After the meeting ends, open the meeting. Expect: messages remain readable, posting disabled.

## D. Polls
15. A creates a single-choice poll with 3 options. Expect: B and G (only if guests may vote was ticked) can vote; others see "cannot vote".
16. B votes, then tries again. Expect: second vote refused.
17. B sees no counts while the poll is open; A sees the running count.
18. A closes the poll. Expect: everyone sees bars with percent and the leading option; closed polls refuse votes.
19. Create a multiple-choice poll with maximum 2 and an anonymous poll. Expect: a 3rd tick keeps Vote disabled; for the anonymous poll no names appear for anyone after closing.
20. Check the audit log. Expect: entries for room enable/disable, poll open and close, chat removal.

## Known limits
No recording; guests are not added to the attendance sheet; polls are not formal resolutions; chat and queues use 4–5 second polling, not websockets; rw/sw texts need native review.
