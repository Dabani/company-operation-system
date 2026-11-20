<?php

// COPS Phase 6c: payroll export messages (fr).
return [
    'err' => [
        'verify_not_author' => 'Un format doit être vérifié par une autre personne que celle qui l\'a préparé.',
        'already_verified' => 'Ce format est déjà vérifié.',
        'verified_is_final' => 'Un format vérifié n\'est jamais modifié ni supprimé : désactivez-le et créez-en un nouveau.',
        'delimiter_required' => 'Un format délimité exige un séparateur.',
        'source_not_allowed' => 'Le champ « :source » n\'est pas disponible pour un fichier :kind.',
        'scheme_required' => 'Ce champ exige un régime.',
        'component_required' => 'Ce champ exige un composant de paie.',
        'constant_required' => 'Une colonne constante exige sa valeur.',
        'width_required' => 'Une colonne à largeur fixe exige une largeur.',
        'profile_not_verified' => 'Ce format n\'est pas vérifié (ou est désactivé) : rien ne peut en être généré.',
        'run_state' => 'Un fichier :kind ne peut être généré que lorsque la paie est : :allowed.',
        'adjustment_unsupported' => 'Les paies de régularisation ne supportent que le fichier bancaire : déclarations et journal des corrections se traitent avec le comptable.',
        'segregation' => 'Séparation des tâches : la personne qui a préparé ou calculé la paie ne peut pas générer son fichier bancaire.',
        'accounts_missing' => 'Comptes du journal non configurés pour : :keys.',
        'journal_unbalanced' => 'Le journal n\'est pas équilibré : il n\'a pas été généré.',
        'bank_account_missing' => 'Aucun compte bancaire enregistré pour : :names.',
        'no_rows' => 'Il n\'y a rien à exporter pour cette paie.',
        'filename_placeholder' => 'Paramètre inconnu {:name} dans le nom du fichier.',
    ],
    'format' => [
        'no_columns' => 'Le format n\'a aucune colonne.',
        'not_numeric' => 'Un nombre était attendu : :detail',
        'delimiter_in_value' => 'Une valeur contient le séparateur et le format n\'a pas de guillemet : :detail',
        'width_required' => 'Une colonne à largeur fixe n\'a pas de largeur : :detail',
        'overflow' => 'Une valeur ne tient pas dans sa colonne (rien n\'est coupé en silence) : :detail',
        'unknown_placeholder' => 'Paramètre inconnu dans l\'en-tête ou le pied : {:detail}',
        'encoding' => 'Un caractère ne peut pas être représenté dans l\'encodage choisi : :detail',
    ],
];
