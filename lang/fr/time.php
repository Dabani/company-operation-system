<?php

// COPS Phase 2a messages. rw/sw are first-pass translations pending native review.
return [
    'entry' => [
        'locked' => 'Le temps approuvé ou facturé ne peut pas être modifié. Demandez un ajustement à un responsable.',
        'overlap' => 'Ce temps chevauche une autre entrée ou un chronomètre en cours.',
        'week_locked' => 'La feuille de temps de cette semaine est déjà soumise ou approuvée. Demandez un ajustement à un responsable.',
        'project_closed' => 'Ce projet est clôturé et n\'accepte plus de temps.',
        'task_invalid' => 'Cette tâche n\'accepte pas de saisie de temps.',
        'times_fixed' => 'Les heures enregistrées par le chronomètre ne sont modifiables que si l\'entrée a été arrêtée automatiquement.',
    ],
    'timer' => [
        'already_running' => 'Un chronomètre est déjà en cours. Arrêtez-le d\'abord.',
        'none' => 'Aucun chronomètre en cours.',
        'already_paused' => 'Le chronomètre est déjà en pause.',
    ],
    'manual' => [
        'disabled' => 'Les saisies manuelles sont désactivées par votre organisation.',
        'reason_required' => 'Un motif d\'au moins 5 caractères est requis.',
        'end_before_start' => 'La fin doit être postérieure au début.',
        'too_long' => 'Une entrée ne peut pas dépasser 24 heures.',
        'future' => 'Une entrée ne peut pas se terminer dans le futur.',
        'too_old' => 'Cette date est plus ancienne que ce que votre organisation autorise pour les saisies manuelles.',
    ],
    'adjust' => [
        'only_locked' => 'Seules les entrées approuvées ou facturées peuvent être ajustées.',
        'range' => 'Un ajustement doit être non nul et inférieur à 24 heures.',
        'below_zero' => 'L\'ajustement rendrait le total négatif.',
    ],
    'timesheet' => [
        'not_draft' => 'Seule une feuille de temps en brouillon peut être soumise.',
        'timer_open' => 'Arrêtez ou supprimez le chronomètre en cours avant de soumettre la semaine.',
        'empty' => 'Aucune entrée en brouillon à soumettre pour cette semaine.',
        'needs_correction' => 'Corrigez les entrées signalées comme arrêtées automatiquement avant de soumettre.',
        'negative' => 'Le total de la semaine ne peut pas être négatif.',
    ],
    'rate' => [
        'scope_value_required' => 'Cette portée exige un rôle, une personne ou une tâche.',
    ],
];
