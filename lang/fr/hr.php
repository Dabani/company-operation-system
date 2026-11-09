<?php

// COPS Phase 4a: HR messages.
return [
    'employment' => [
        'pending_exists' => 'Cet employé a déjà un changement en attente d\'approbation.',
        'date_order' => 'La date d\'effet doit être postérieure au dernier changement enregistré.',
        'salary_required' => 'Un changement de salaire nécessite le nouveau montant.',
        'currency_required' => 'Un salaire nécessite une devise.',
        'nothing_changed' => 'Rien ne changerait.',
        'confirmation_needs_category' => 'Une confirmation doit définir la nouvelle catégorie de l\'employé.',
        'use_change' => 'Ce champ a un historique daté : enregistrez un changement d\'emploi au lieu de le modifier.',
        'approval_title' => [
            'promotion' => 'Promotion : :name',
            'confirmation' => 'Confirmation : :name',
            'salary_change' => 'Changement de salaire : :name',
            'category_change' => 'Changement de catégorie : :name',
            'transfer' => 'Mutation : :name',
            'manager_change' => 'Changement de responsable : :name',
        ],
    ],
    'criteria' => [
        'must_be_higher' => 'Le grade cible doit être supérieur au grade de départ.',
        'exists' => 'Cette règle de progression existe déjà.',
    ],
    'review' => [
        'cycle_closed' => 'Ce cycle d\'évaluation est clos.',
        'rating_required' => 'Choisissez une note avant de soumettre.',
    ],
    'reminder' => [
        'probation' => ['title' => 'Fin de période d\'essai proche : :name', 'body' => 'La période d\'essai de :name se termine le :date (dans :days jours). Décidez de la confirmation ou de la prolongation.'],
        'contract' => ['title' => 'Fin de contrat proche : :name', 'body' => 'Le contrat de :name se termine le :date (dans :days jours). Renouvelez ou planifiez le départ.'],
    ],
];
