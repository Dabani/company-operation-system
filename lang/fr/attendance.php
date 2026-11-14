<?php

// COPS Phase 4c: attendance messages.
return [
    'already_in' => 'Vous avez déjà pointé votre arrivée.',
    'not_clocked_in' => 'Vous n\'avez pas pointé votre arrivée.',
    'too_soon' => 'Trop tôt après votre dernier pointage.',
    'period_validated' => 'Ce mois est validé et verrouillé.',
    'network_not_allowed' => 'Vous ne pouvez pointer que depuis le réseau de l\'organisation.',
    'month_not_over' => 'Un mois ne peut être validé qu\'une fois terminé.',
    'already_validated' => 'Ce mois est déjà validé.',
    'cannot_validate' => 'Le mois ne peut pas encore être validé : :pending correction(s) en attente d\'approbation et :incomplete jour(s) sans pointage de sortie.',
    'not_validated' => 'Ce mois n\'est pas validé.',
    'correction_future' => 'Une correction ne peut pas concerner un jour futur.',
    'correction_order' => 'L\'heure de fin doit être postérieure à l\'heure de début.',
    'correction_too_long' => 'C\'est plus long que la période de travail maximale autorisée.',
    'correction_pending' => 'Une correction pour ce jour est déjà en attente d\'approbation.',
    'approval_title' => 'Correction de présence : :name, :date',
    'import_empty' => 'Le fichier est vide.',
    'import_columns' => 'Le fichier doit contenir une colonne employee_number et une colonne timestamp.',
    'import_too_many' => 'Le fichier contient plus de :max lignes.',
    'cidr_invalid' => 'Saisissez une adresse IP ou une plage comme 10.0.0.0/24.',
    'import_reason' => [
        'unknown_employee' => 'Numéro d\'employé inconnu',
        'bad_timestamp' => 'Date et heure illisibles',
        'future' => 'Date et heure dans le futur',
        'bad_direction' => 'La direction doit être in ou out',
        'period_validated' => 'Mois déjà validé',
    ],
];
