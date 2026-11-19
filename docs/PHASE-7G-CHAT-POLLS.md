# Phase 7g: chat and polls in online meetings

Needs bundle 32 (online meetings). Native COPS features, so they are kept with the meeting, follow COPS access rules and work for external guests; Jitsi's own chat stays available inside the video but is not recorded by COPS.

## Chat
- Participants (hosts, invited account holders who did not decline, and admitted guests) post while the online room is open; the text is kept with the meeting and stays readable for account-holder participants afterwards (guests only while the room is open).
- 1 to 1000 characters, rate limited. A host (organizer, chair, secretary, facilitator) can remove a message: the text disappears, the removal and who did it are recorded. Messages cannot be edited.
- Confidential meetings keep the same confidentiality: only participants and holders of the audited override see the chat.

## Polls
- Hosts open a poll while the meeting is in progress: a question (3 to 300 characters), 2 to 10 options, single or multiple choice (optional maximum), optional agenda item, optional anonymity, optional guest voting (never in confidential meetings).
- Eligible voters: participants who are invited account holders or hosts, and guests only when the poll allows them. One submission per voter, no changes afterwards.
- Hosts see the running count; everyone else sees the result when a host closes the poll. A closed poll shows counts, percentages of voters and the leading option(s); ties are shown as ties.
- Anonymous polls: who voted (voter table) is stored apart from what was voted (vote rows carry no voter and get random ids, not time-ordered ones), so nobody, including hosts, can see who chose what. Open and close are audited, with the final result. Non-anonymous polls show hosts the names after closing.
- A poll is an opinion tool, not a formal resolution. Use the existing resolutions for decisions that must be minuted.

## Apply
    python3 apply_b33_backend.py --repo <backend repo> --dry-run
    python3 apply_b33_backend.py --repo <backend repo>
    composer dump-autoload && php artisan migrate && php artisan db:seed --class=MeetingsCollabSeeder
    php artisan test --filter=CollabRules
Edits: routes/api.php, DatabaseSeeder, lang/*/meetings.php.

## Not included
Private messages, file sharing in chat, editing messages, poll results pushed into the minutes automatically, live push (the page polls every few seconds). Not run on a live install.
