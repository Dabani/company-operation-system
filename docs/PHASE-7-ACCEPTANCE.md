# Phase 7 acceptance (Guide 6.8)

| Criterion | Where it is met |
|---|---|
| Draft minutes visible only to secretary, chair and authorized review participants | `MinutesAccess::canRead` (7c); journey test: attendee gets 404 until named as reviewer |
| Confidential meeting existence and minutes hidden from non-invitees | `Meeting::visibleTo`, `MinutesAccess::scopeLibrary` (7a, 7c); journey test: outsider gets 404 and an empty library |
| Attachments follow Controlled Files rules | papers, evidence and the minutes PDF are controlled files (`FileService`), restricted class refused on meeting links |
| Every state change, approval, publication, distribution and revision audited | `AuditLogger` calls in the services, status history tables, `meeting_minute_distributions`, PDF download audit |
| Complete cycle from invitation to approved, published minutes and tracked action items passes E2E | `tests/Feature/MeetingsJourneyTest.php` |

Run: `php artisan test --filter=MeetingsJourney` (MySQL test database, bundles 17 to 20 applied).
