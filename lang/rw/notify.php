<?php

// Notification texts. Placeholders: :ref :title :user :priority :status :app
return [
    'open' => 'Fungura ku rubuga',
    'footer' => 'Iyi mesaje yoherejwe mu buryo bwikora. Ntukayisubize.',
    'chat_escalated' => 'Ngiye kukugeza ku mukozi w\'ubufasha. Tegereza gato.',
    'case_created_user' => [
        'title' => 'Twakiriye ikibazo cyawe :ref',
        'body' => 'Ikibazo cyawe ":title" cyanditswe. Tuzakumenyesha uko gikomeza.',
    ],
    'case_created_admin' => [
        'title' => 'Ikibazo gishya :ref',
        'body' => '(:priority) :user yohereje ":title".',
        'sms' => ':app: ikibazo gishya :ref cya :user (:priority). Reba ku rubuga.',
    ],
    'case_status' => [
        'title' => 'Ikibazo :ref cyavuguruwe',
        'body' => 'Imiterere y\'ikibazo cyawe ubu ni: :status.',
    ],
    'case_comment' => [
        'title' => 'Igitekerezo gishya kuri :ref',
        'body' => ':user yanditse igitekerezo kuri ":title".',
    ],
    'case_assigned' => [
        'title' => 'Ikibazo :ref cyaguhawe',
        'body' => 'Wahawe ikibazo ":title".',
    ],
    'chat_waiting' => [
        'title' => 'Ikiganiro gikeneye umukozi',
        'body' => ':user ategereje ubufasha.',
    ],
    'file_delete_requested' => [
        'title' => 'Hasabwe gusiba dosiye',
        'body' => ':user yasabye gusiba dosiye « :file ». Yifungure wemeze cyangwa wange.',
    ],
    'file_delete_decided' => [
        'title' => 'Ubusabe bwo gusiba :decision',
        'body' => 'Ubusabe bwo gusiba « :file » bwarasuzumwe na :user.',
    ],
    'movement_requested' => [
        'title' => 'Gusohora ibikoresho :ref',
        'body' => 'Ubusabe :ref bwa :user (bijya :dest) butegereje kwemezwa nawe.',
    ],
    'movement_decided' => [
        'title' => 'Gusohora :ref :decision',
        'body' => 'Ubusabe bwawe bwo gusohora ibikoresho :ref bwasuzumwe na :user: :decision.',
    ],
    'movement_overdue' => [
        'title' => 'Ibikoresho byatinze :ref',
        'body' => 'Ibikoresho bya :ref (:user) byagombaga kugaruka ku wa :date.',
    ],
    'incident_reported' => [
        'title' => 'Ikibazo cyabaye :ref (:priority)',
        'body' => '(:priority) :user yatanze raporo ya « :title ».',
        'sms' => ':app: IKIBAZO :ref (:priority) cya :user. Reba ku rubuga.',
    ],
    'incident_sla_containment' => [
        'title' => 'Gukumira byatinze :ref',
        'body' => 'Ikibazo :ref « :title » ntikirakumirwa mu gihe cyagenwe.',
        'sms' => 'Gukumira byatinze kuri :ref. Kora ubu.',
    ],
    'incident_sla_rca' => [
        'title' => 'Isesengura ry\'intandaro ryatinze :ref',
        'body' => 'Ikibazo :ref « :title » nta sesengura ry\'intandaro rirarangira mu gihe cyagenwe.',
    ],
    'incident_contained' => [
        'title' => 'Ikibazo :ref cyakumiriwe',
        'body' => 'Ikibazo « :title » cyakumiriwe na :user.',
    ],
    'access_granted' => [
        'title' => 'Wahawe uburenganzira',
        'body' => 'Wahawe uburenganzira (:level) kuri :res na :user.',
    ],
    'access_revoked' => [
        'title' => 'Uburenganzira bwakuweho',
        'body' => 'Uburenganzira bwawe kuri :res bwakuweho na :user.',
    ],
];
