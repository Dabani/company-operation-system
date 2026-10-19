<?php

// Notification texts. Placeholders: :ref :title :user :priority :status :app
return [
    'open' => 'Ouvrir dans la plateforme',
    'footer' => 'Ce message a été envoyé automatiquement. Merci de ne pas y répondre.',
    'chat_escalated' => 'Je vous mets en relation avec un agent. Veuillez patienter un instant.',
    'case_created_user' => [
        'title' => 'Nous avons reçu votre dossier :ref',
        'body' => 'Votre dossier « :title » a été enregistré. Nous vous tiendrons informé de son avancement.',
    ],
    'case_created_admin' => [
        'title' => 'Nouveau dossier :ref',
        'body' => '(:priority) :user a soumis « :title ».',
        'sms' => ':app : nouveau dossier :ref de :user (:priority). Consultez la plateforme.',
    ],
    'case_status' => [
        'title' => 'Dossier :ref mis à jour',
        'body' => 'Le statut de votre dossier est maintenant : :status.',
    ],
    'case_comment' => [
        'title' => 'Nouveau commentaire sur :ref',
        'body' => ':user a commenté « :title ».',
    ],
    'case_assigned' => [
        'title' => 'Dossier :ref qui vous est assigné',
        'body' => 'Le dossier « :title » vous a été assigné.',
    ],
    'chat_waiting' => [
        'title' => 'Un chat attend un agent',
        'body' => ':user attend de l\'aide.',
    ],
    'file_delete_requested' => [
        'title' => 'Suppression de fichier demandée',
        'body' => ':user demande la suppression du fichier « :file ». Ouvrez-le pour approuver ou rejeter.',
    ],
    'file_delete_decided' => [
        'title' => 'Demande de suppression :decision',
        'body' => 'La demande de suppression de « :file » a été :decision par :user.',
    ],
    'movement_requested' => [
        'title' => 'Sortie de matériel :ref',
        'body' => 'La demande :ref de :user (vers :dest) attend votre approbation.',
    ],
    'movement_decided' => [
        'title' => 'Sortie :ref :decision',
        'body' => 'Votre demande de sortie de matériel :ref a été :decision par :user.',
    ],
    'movement_overdue' => [
        'title' => 'Matériel en retard :ref',
        'body' => 'Le matériel de :ref (:user) devait être retourné le :date.',
    ],
    'incident_reported' => [
        'title' => 'Incident :ref (:priority)',
        'body' => '(:priority) :user a signalé « :title ».',
        'sms' => ':app : INCIDENT :ref (:priority) de :user. Consultez la plateforme.',
    ],
    'incident_sla_containment' => [
        'title' => 'Confinement en retard :ref',
        'body' => 'L\'incident :ref « :title » n\'a pas été confiné dans le délai cible.',
        'sms' => 'Confinement en retard pour :ref. Agissez maintenant.',
    ],
    'incident_sla_rca' => [
        'title' => 'Analyse des causes en retard :ref',
        'body' => 'L\'incident :ref « :title » n\'a pas d\'analyse des causes terminée dans le délai cible.',
    ],
    'incident_contained' => [
        'title' => 'Incident :ref confiné',
        'body' => 'L\'incident « :title » a été confiné par :user.',
    ],
    'access_granted' => [
        'title' => 'Accès accordé',
        'body' => 'Vous avez reçu un accès (:level) à :res de la part de :user.',
    ],
    'access_revoked' => [
        'title' => 'Accès révoqué',
        'body' => 'Votre accès à :res a été révoqué par :user.',
    ],
];
