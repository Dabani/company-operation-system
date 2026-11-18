<?php

// COPS Phase 8a: procurement (suppliers and purchase requisitions). rw / sw are first-pass translations pending native review.
return [
    'supplier_not_editable' => 'Ce fournisseur ne peut plus être modifié.',
    'supplier_bad_move' => 'Ce changement de statut du fournisseur n\'est pas autorisé.',
    'supplier_incomplete' => 'Renseignez la raison sociale, le numéro fiscal, le pays et un contact (e-mail ou téléphone) avant l\'envoi en revue.',
    'supplier_reason_required' => 'Indiquez un motif (3 caractères minimum).',
    'supplier_approval_title' => 'Référencement fournisseur : :name (:code)',
    'supplier_not_approved' => 'Seul un fournisseur approuvé peut être indiqué sur une demande.',
    'preferred_reason_required' => 'Indiquez pourquoi ce fournisseur est préféré (3 caractères minimum).',
    'bank_pending_exists' => 'Un changement de compte bancaire attend déjà sa confirmation.',
    'bank_not_pending' => 'Ce compte bancaire n\'attend pas de confirmation.',
    'bank_same_person' => 'Les coordonnées bancaires doivent être confirmées par une autre personne que celle qui les a saisies.',
    'requisition_not_editable' => 'Cette demande ne peut plus être modifiée.',
    'requisition_missing' => 'Cette information est requise avant de soumettre la demande.',
    'exception_required' => 'La demande dépasse le budget du projet. Expliquez l\'exception (10 caractères minimum) pour la soumettre.',
    'tag_over_budget' => '(hors budget)',
    'tag_split' => '(fractionnement possible)',
    'requisition_approval_title' => 'Demande d\'achat :code : :title',
    'requisition_bad_move' => 'Ce changement de statut n\'est pas autorisé.',
];
