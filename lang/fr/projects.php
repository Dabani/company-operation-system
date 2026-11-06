<?php

// COPS Phase 1 messages. rw/sw are first-pass translations pending native review.
return [
    'task' => [
        'transition_not_allowed' => 'Ce déplacement n\'est pas autorisé depuis le statut actuel de la tâche.',
        'blocked' => 'Terminez ou annulez d\'abord les tâches dont celle-ci dépend.',
        'dependency_same_project' => 'Une tâche ne peut dépendre que d\'une tâche du même projet.',
        'dependency_cycle' => 'Cette dépendance créerait une boucle.',
    ],
    'file' => [
        'not_restricted' => 'Les fichiers restreints ne peuvent pas être joints aux projets ou tâches.',
    ],
];
