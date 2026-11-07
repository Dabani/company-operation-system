<?php

// COPS Phase 3 portal messages. rw/sw are first-pass translations pending native review.
return [
    'mfa_required' => 'Enter the code from your authenticator app to continue.',
    'mfa_setup_required' => 'Set up two-factor authentication to continue.',
    'invitation_invalid' => 'This link is not valid.',
    'invitation_used' => 'This link has already been used.',
    'invitation_expired' => 'This link has expired. Ask your contact for a new invitation.',
    'reset_sent' => 'If an account exists for that e-mail, a link to choose a new password has been sent.',
    'feature_disabled' => 'This feature is not enabled for your account.',
    'project_no_client' => 'Set a client on the project before sharing it.',
    'mfa' => [
        'already_enabled' => 'Two-factor authentication is already enabled.',
        'not_started' => 'Start the set-up first.',
        'invalid_code' => 'That code is not valid. Try the next code or use a recovery code.',
    ],
    'upload' => [
        'type_not_allowed' => 'This file type is not allowed. Allowed: :types.',
        'too_large' => 'The file is too large. Maximum size: :mb MB.',
    ],
    'invite' => [
        'portal_disabled' => 'Enable the portal for this client first.',
        'no_email' => 'This contact has no e-mail address.',
        'already_active' => 'This person already has an active portal account.',
        'email_taken' => 'This e-mail address already has a portal account.',
        'throttled' => 'An invitation was sent a moment ago. Please wait a minute before resending.',
    ],
];
