<?php

// COPS Phase 3 portal messages. rw/sw are first-pass translations pending native review.
return [
    'mfa_required' => 'Saisissez le code de votre application d\'authentification pour continuer.',
    'mfa_setup_required' => 'Configurez l\'authentification à deux facteurs pour continuer.',
    'invitation_invalid' => 'Ce lien n\'est pas valide.',
    'invitation_used' => 'Ce lien a déjà été utilisé.',
    'invitation_expired' => 'Ce lien a expiré. Demandez une nouvelle invitation à votre contact.',
    'reset_sent' => 'Si un compte existe pour cet e-mail, un lien pour choisir un nouveau mot de passe a été envoyé.',
    'feature_disabled' => 'Cette fonction n\'est pas activée pour votre compte.',
    'project_no_client' => 'Indiquez un client sur le projet avant de le partager.',
    'mfa' => [
        'already_enabled' => 'L\'authentification à deux facteurs est déjà activée.',
        'not_started' => 'Commencez d\'abord la configuration.',
        'invalid_code' => 'Ce code n\'est pas valide. Essayez le code suivant ou utilisez un code de récupération.',
    ],
    'upload' => [
        'type_not_allowed' => 'Ce type de fichier n\'est pas autorisé. Autorisés : :types.',
        'too_large' => 'Le fichier est trop volumineux. Taille maximale : :mb Mo.',
    ],
    'invite' => [
        'portal_disabled' => 'Activez d\'abord le portail pour ce client.',
        'no_email' => 'Ce contact n\'a pas d\'adresse e-mail.',
        'already_active' => 'Cette personne a déjà un compte portail actif.',
        'email_taken' => 'Cette adresse e-mail a déjà un compte portail.',
        'throttled' => 'Une invitation vient d\'être envoyée. Patientez une minute avant de renvoyer.',
    ],
];
